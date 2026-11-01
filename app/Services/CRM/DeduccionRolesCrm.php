<?php

namespace App\Services\CRM;

use App\Models\Application;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deduce los roles del Equipo comercial (vende, gerencia, catálogos) de cada
 * persona en una empresa. El rol nunca se guarda: sale de los permisos reales
 * en la aplicación CRM y de la ficha de vendedor (spec 2026-11-01 §3 y §4).
 *
 * - Permisos reales: concedidos, con el tipo de permiso del mismo submódulo
 *   (criterio de PermisoSubmodulo) y con acceso activo a la aplicación, al
 *   módulo y al submódulo. Un permiso con el acceso apagado no cuenta.
 * - La definición de cada rol se intersecta con lo que existe en el CRM de la
 *   empresa; si queda vacía, el rol nunca se deduce como marcado.
 * - Las claves de permiso tienen la forma `modulo.submodulo.permiso`.
 *
 * La deducción por empresa (`deEmpresa`) usa un número constante de consultas.
 */
class DeduccionRolesCrm
{
    /**
     * Deducción pura a partir de los permisos reales y la ficha.
     *
     * @param  string[]  $reales  claves `modulo.submodulo.permiso` concedidas
     * @param  array{base: string[], gerencia: string[], catalogos: string[]}  $definiciones  ya intersectadas con el CRM de la empresa
     * @return array{vende: bool, gerencia: bool, catalogos: bool, ajustado: bool, aviso: ?string}
     */
    public static function deducir(array $reales, array $definiciones, bool $tieneFicha, bool $fichaActiva): array
    {
        $reales = array_values(array_unique($reales));

        $gerencia = $definiciones['gerencia'] !== [] && ! array_diff($definiciones['gerencia'], $reales);
        $catalogos = $definiciones['catalogos'] !== [] && ! array_diff($definiciones['catalogos'], $reales);
        $vende = $fichaActiva;
        $baseCompleta = $definiciones['base'] !== [] && ! array_diff($definiciones['base'], $reales);

        $esperado = array_merge(
            $vende ? $definiciones['base'] : [],
            $gerencia ? $definiciones['gerencia'] : [],
            $catalogos ? $definiciones['catalogos'] : [],
        );

        // Excepción: perfil Vendedor al que le falta la ficha. No es un ajuste
        // a mano: la base cuenta como esperada y se avisa.
        $aviso = null;
        if (! $vende && ! $gerencia && $baseCompleta) {
            $esperado = array_merge($esperado, $definiciones['base']);
            $aviso = 'falta_ficha';
        }
        if ($tieneFicha && ! $fichaActiva) {
            $aviso = 'ficha_inactiva';
        }

        // Sin permisos reales no hay nada «a mano» que conservar (p. ej. una
        // ficha sin usuario): nunca es ajustado.
        $ajustado = $reales !== []
            && (array_diff($reales, $esperado) !== [] || array_diff($esperado, $reales) !== []);

        return [
            'vende' => $vende,
            'gerencia' => $gerencia,
            'catalogos' => $catalogos,
            'ajustado' => $ajustado,
            'aviso' => $aviso,
        ];
    }

    /**
     * Aplana una definición (módulo → submódulo → permisos) a claves
     * `modulo.submodulo.permiso`.
     *
     * @param  array<string, array<string, string[]>>  $definicion
     * @return string[]
     */
    public static function claves(array $definicion): array
    {
        $claves = [];
        foreach ($definicion as $modulo => $submodulos) {
            foreach ($submodulos as $submodulo => $permisos) {
                foreach ($permisos as $permiso) {
                    $claves[] = "{$modulo}.{$submodulo}.{$permiso}";
                }
            }
        }

        return array_values(array_unique($claves));
    }

    /**
     * @throws InvalidArgumentException si la empresa no tiene el CRM.
     */
    public function appCrm(Enterprise $empresa): Application
    {
        $app = Application::where('enterprise_id', $empresa->id)->where('slug', 'crm')->first();

        if (! $app) {
            throw new InvalidArgumentException('La empresa no tiene el CRM Comercial configurado.');
        }

        return $app;
    }

    /**
     * Roles de una persona en una empresa.
     *
     * @return array{roles: array{vende: bool, gerencia: bool, catalogos: bool}, ajustado: bool, aviso: ?string, oportunidades_abiertas: int}
     *
     * @throws InvalidArgumentException si la empresa no tiene el CRM.
     */
    public function dePersona(User $usuario, Enterprise $empresa): array
    {
        $app = $this->appCrm($empresa);
        $definiciones = $this->definiciones($app);

        $reales = $this->consultaReales($app, $usuario->id)
            ->select('m.slug as modulo', 's.slug as submodulo', 'spt.slug as permiso')
            ->get()
            ->map(fn ($f) => "{$f->modulo}.{$f->submodulo}.{$f->permiso}")
            ->all();

        $ficha = CrmVendedor::where('empresa_id', $empresa->id)->where('user_id', $usuario->id)->first();
        $tieneAcceso = UserEnterpriseAccess::where('enterprise_id', $empresa->id)
            ->where('user_id', $usuario->id)->where('is_active', true)->exists();
        $abiertas = $ficha ? ($this->abiertasPorVendedor($empresa, [$ficha->id])[$ficha->id] ?? 0) : 0;

        return $this->resultado($reales, $definiciones, $ficha, $tieneAcceso, $abiertas);
    }

    /**
     * Todas las personas del Equipo comercial de la empresa.
     *
     * Entran: quien tiene acceso activo a la empresa y algún permiso real del
     * CRM o una ficha ligada, las fichas sin usuario, las fichas inactivas y
     * las de usuarios sin acceso. Activas por nombre; las fichas inactivas
     * al final.
     *
     * @return Collection<int, array{user_id: ?int, vendedor_id: ?int, nombre: string, email: ?string, telefono: ?string, roles: array{vende: bool, gerencia: bool, catalogos: bool}, ajustado: bool, aviso: ?string, ficha_activa: bool, oportunidades_abiertas: int}>
     *
     * @throws InvalidArgumentException si la empresa no tiene el CRM.
     */
    public function deEmpresa(Enterprise $empresa): Collection
    {
        $app = $this->appCrm($empresa);
        $definiciones = $this->definiciones($app);

        $realesPorUsuario = [];
        $this->consultaReales($app)
            ->select('usp.user_id', 'm.slug as modulo', 's.slug as submodulo', 'spt.slug as permiso')
            ->get()
            ->each(function ($f) use (&$realesPorUsuario) {
                $realesPorUsuario[(int) $f->user_id][] = "{$f->modulo}.{$f->submodulo}.{$f->permiso}";
            });

        $conAcceso = array_flip(
            UserEnterpriseAccess::where('enterprise_id', $empresa->id)->where('is_active', true)
                ->pluck('user_id')->map(fn ($id) => (int) $id)->all()
        );

        $fichas = CrmVendedor::where('empresa_id', $empresa->id)->get();
        $fichaPorUsuario = $fichas->whereNotNull('user_id')->keyBy('user_id');
        $abiertas = $this->abiertasPorVendedor($empresa);

        $idsUsuarios = collect(array_keys($realesPorUsuario))
            ->filter(fn ($id) => isset($conAcceso[$id]))
            ->merge($fichaPorUsuario->keys())
            ->unique()->values();
        $usuarios = User::whereIn('id', $idsUsuarios)->get(['id', 'name', 'email', 'phone'])->keyBy('id');

        $filas = collect();
        foreach ($idsUsuarios as $userId) {
            $usuario = $usuarios->get($userId);
            if (! $usuario) {
                continue;
            }
            $filas->push($this->filaDeListado(
                $realesPorUsuario[$userId] ?? [],
                $definiciones,
                $fichaPorUsuario->get($userId),
                $usuario,
                isset($conAcceso[$userId]),
                $abiertas,
            ));
        }
        foreach ($fichas->whereNull('user_id') as $ficha) {
            $filas->push($this->filaDeListado([], $definiciones, $ficha, null, false, $abiertas));
        }

        return $filas
            ->sort(function (array $a, array $b) {
                $inactivaA = $a['vendedor_id'] !== null && ! $a['ficha_activa'];
                $inactivaB = $b['vendedor_id'] !== null && ! $b['ficha_activa'];

                return [$inactivaA, mb_strtolower($a['nombre'])] <=> [$inactivaB, mb_strtolower($b['nombre'])];
            })
            ->values();
    }

    /**
     * Usuarios con acceso activo a la empresa que aún no tienen permisos
     * reales del CRM ni ficha: candidatos para «Agregar persona».
     *
     * @return Collection<int, array{id: int, name: string, email: string}>
     *
     * @throws InvalidArgumentException si la empresa no tiene el CRM.
     */
    public function usuariosDisponibles(Enterprise $empresa): Collection
    {
        $app = $this->appCrm($empresa);

        return User::query()
            ->whereIn('id', UserEnterpriseAccess::select('user_id')
                ->where('enterprise_id', $empresa->id)->where('is_active', true))
            ->whereNotIn('id', CrmVendedor::select('user_id')
                ->where('empresa_id', $empresa->id)->whereNotNull('user_id'))
            ->whereNotIn('id', $this->consultaReales($app)->select('usp.user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]);
    }

    /**
     * Ids (de los pedidos) que tienen algún vínculo con la empresa: una fila de
     * acceso a ella (activa o no), una ficha de vendedor o permisos reales del
     * CRM. Una sola consulta (unión), sin consultas por persona.
     *
     * @param  int[]  $userIds
     * @return array<int, true> ids que pertenecen a la empresa, como llaves
     *
     * @throws InvalidArgumentException si la empresa no tiene el CRM.
     */
    public function conVinculo(Enterprise $empresa, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $app = $this->appCrm($empresa);

        $acceso = DB::table('user_enterprise_access')
            ->where('enterprise_id', $empresa->id)->whereIn('user_id', $userIds)->select('user_id');
        $ficha = DB::table('crm_vendedores')
            ->where('empresa_id', $empresa->id)->whereIn('user_id', $userIds)->select('user_id');
        $permisos = $this->consultaReales($app)->whereIn('usp.user_id', $userIds)->select('usp.user_id');

        return $acceso->union($ficha)->union($permisos)->pluck('user_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /**
     * Resultado anidado de una persona.
     *
     * @param  string[]  $reales
     * @return array{roles: array{vende: bool, gerencia: bool, catalogos: bool}, ajustado: bool, aviso: ?string, oportunidades_abiertas: int}
     */
    private function resultado(array $reales, array $definiciones, ?CrmVendedor $ficha, bool $tieneAcceso, int $abiertas): array
    {
        $d = self::deducir($reales, $definiciones, $ficha !== null, (bool) $ficha?->activo);

        // Ficha activa de alguien que ya no tiene acceso a la empresa.
        $aviso = $d['aviso'];
        if ($ficha && $ficha->activo && $ficha->user_id !== null && ! $tieneAcceso) {
            $aviso = 'sin_acceso';
        }

        return [
            'roles' => ['vende' => $d['vende'], 'gerencia' => $d['gerencia'], 'catalogos' => $d['catalogos']],
            'ajustado' => $d['ajustado'],
            'aviso' => $aviso,
            'oportunidades_abiertas' => $abiertas,
        ];
    }

    /**
     * Fila del listado de la empresa: datos de la persona + su resultado.
     * La ficha manda sobre los datos del usuario cuando existe.
     *
     * @param  string[]  $reales
     * @param  array<int, int>  $abiertasPorVendedor
     */
    private function filaDeListado(array $reales, array $definiciones, ?CrmVendedor $ficha, ?User $usuario, bool $tieneAcceso, array $abiertasPorVendedor): array
    {
        $r = $this->resultado($reales, $definiciones, $ficha, $tieneAcceso, $ficha ? ($abiertasPorVendedor[$ficha->id] ?? 0) : 0);

        return [
            'user_id' => $ficha ? $ficha->user_id : $usuario?->id,
            'vendedor_id' => $ficha?->id,
            'nombre' => $ficha ? $ficha->nombre : $usuario?->name,
            'email' => $ficha ? $ficha->email : $usuario?->email,
            'telefono' => $ficha ? $ficha->telefono : $usuario?->phone,
            'roles' => $r['roles'],
            'ajustado' => $r['ajustado'],
            'aviso' => $r['aviso'],
            'ficha_activa' => (bool) $ficha?->activo,
            'oportunidades_abiertas' => $r['oportunidades_abiertas'],
        ];
    }

    /**
     * Definiciones de los roles intersectadas con los permisos que existen en
     * el CRM de la empresa (una consulta).
     *
     * @return array{base: string[], gerencia: string[], catalogos: string[]}
     */
    private function definiciones(Application $app): array
    {
        $existentes = DB::table('submodule_permission_types as spt')
            ->join('submodules as s', 's.id', '=', 'spt.submodule_id')
            ->join('modules as m', 'm.id', '=', 's.module_id')
            ->where('m.application_id', $app->id)
            ->select('m.slug as modulo', 's.slug as submodulo', 'spt.slug as permiso')
            ->get()
            ->mapWithKeys(fn ($f) => ["{$f->modulo}.{$f->submodulo}.{$f->permiso}" => true])
            ->all();

        $dejarExistentes = fn (array $roles) => array_values(array_filter(
            self::claves(CrmPerfilPermisosService::definicionRoles($roles)),
            fn (string $clave) => isset($existentes[$clave]),
        ));

        return [
            'base' => $dejarExistentes(['vende']),
            'gerencia' => $dejarExistentes(['gerencia']),
            'catalogos' => $dejarExistentes(['catalogos']),
        ];
    }

    /**
     * Permisos concedidos en el CRM con acceso activo a aplicación, módulo y
     * submódulo, y tipo de permiso del mismo submódulo. Sin `select`: quien
     * llama elige las columnas.
     */
    private function consultaReales(Application $app, ?int $userId = null): Builder
    {
        return DB::table('user_submodule_permissions as usp')
            ->join('submodule_permission_types as spt', function ($j) {
                $j->on('spt.id', '=', 'usp.permission_type_id')
                    ->on('spt.submodule_id', '=', 'usp.submodule_id');
            })
            ->join('submodules as s', 's.id', '=', 'usp.submodule_id')
            ->join('modules as m', 'm.id', '=', 's.module_id')
            ->join('user_application_access as uaa', function ($j) {
                $j->on('uaa.user_id', '=', 'usp.user_id')->on('uaa.application_id', '=', 'm.application_id');
            })
            ->join('user_module_access as uma', function ($j) {
                $j->on('uma.user_id', '=', 'usp.user_id')->on('uma.module_id', '=', 'm.id');
            })
            ->join('user_submodule_access as usa', function ($j) {
                $j->on('usa.user_id', '=', 'usp.user_id')->on('usa.submodule_id', '=', 's.id');
            })
            ->where('m.application_id', $app->id)
            ->where('usp.is_granted', true)
            ->where('uaa.is_active', true)
            ->where('uma.is_active', true)
            ->where('usa.is_active', true)
            ->when($userId !== null, fn ($q) => $q->where('usp.user_id', $userId))
            ->distinct();
    }

    /**
     * Oportunidades abiertas (no cerradas) por ficha, en una sola consulta.
     *
     * @param  int[]|null  $vendedorIds  null = todas las de la empresa
     * @return array<int, int>
     */
    private function abiertasPorVendedor(Enterprise $empresa, ?array $vendedorIds = null): array
    {
        return CrmOportunidad::activas()
            ->where('empresa_id', $empresa->id)
            ->when($vendedorIds !== null, fn ($q) => $q->whereIn('vendedor_id', $vendedorIds))
            ->selectRaw('vendedor_id, COUNT(*) as total')
            ->groupBy('vendedor_id')
            ->pluck('total', 'vendedor_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
