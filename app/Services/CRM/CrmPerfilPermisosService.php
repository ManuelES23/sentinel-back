<?php

namespace App\Services\CRM;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Perfiles de permisos del CRM Comercial (fase 1 de la reorganización).
 *
 * Un perfil es un conjunto fijo de módulo → submódulo → permisos. Aplicarlo a
 * un usuario REEMPLAZA todos sus accesos y permisos dentro de la aplicación
 * `crm` de una empresa: lo que está en el perfil queda activo y todo lo demás
 * del CRM queda inactivo. Nada fuera del CRM de esa empresa se toca.
 *
 * El menú lateral ya muestra solo lo que el usuario tiene permitido, así que
 * el perfil define qué ve cada persona sin cambiar el sidebar.
 *
 * Nota sobre Agenda: ver la agenda de otro vendedor exige `agenda.equipo`
 * (solo gerencia). "crear" y "editar" ya no cuentan como gerencia: el
 * vendedor los necesita para registrar y completar sus propios eventos.
 */
class CrmPerfilPermisosService
{
    private const BASE_VENDEDOR = [
        'mi-dia' => ['mi-dia' => ['ver']],
        'agenda' => ['agenda' => ['ver', 'crear', 'editar']],
        'oportunidades' => ['oportunidades' => ['ver', 'crear', 'editar']],
        'clientes' => ['clientes' => ['ver', 'crear', 'editar']],
        'prospectos' => ['prospectos' => ['ver', 'crear', 'editar']],
        'cotizaciones' => ['cotizaciones' => ['ver', 'crear', 'editar']],
        'actividades' => ['actividades' => ['ver', 'crear', 'editar']],
        'empresas-externas' => ['empresas-externas' => ['ver', 'crear', 'editar', 'gestionar_contactos']],
        'dashboard' => ['dashboard' => ['ver']],
        'presupuestos' => ['presupuestos' => ['ver']],
        'integraciones' => ['outlook' => ['ver']],
    ];

    private const EXTRA_GERENCIA = [
        'mi-dia' => ['mi-dia' => ['equipo']],
        'agenda' => ['agenda' => ['eliminar', 'equipo']],
        'oportunidades' => ['oportunidades' => ['eliminar', 'cerrar', 'equipo', 'cobro']],
        'clientes' => ['clientes' => ['eliminar', 'asignar_vendedor']],
        'prospectos' => ['prospectos' => ['eliminar', 'asignar_vendedor']],
        'cotizaciones' => ['cotizaciones' => ['aprobar', 'rechazar']],
        'actividades' => ['actividades' => ['eliminar']],
        'empresas-externas' => ['empresas-externas' => ['eliminar']],
        'dashboard' => ['dashboard' => ['ejecutivo']],
        'presupuestos' => ['presupuestos' => ['crear', 'editar']],
        'integraciones' => ['dialpad' => ['ver', 'editar', 'sync']],
    ];

    private const ADMINISTRACION = [
        'catalogos' => [
            'vendedores' => ['ver', 'crear', 'editar', 'eliminar'],
            'regiones' => ['ver', 'crear', 'editar', 'eliminar'],
            'zonas' => ['ver', 'crear', 'editar', 'eliminar'],
            'bodegas' => ['ver', 'crear', 'editar', 'eliminar'],
            'productos' => ['ver', 'crear', 'editar', 'eliminar'],
            'configuracion-comercial' => ['ver', 'editar'],
            'plantillas-whatsapp' => ['ver', 'crear', 'editar', 'eliminar'],
            'categorias-rechazo' => ['ver', 'crear', 'editar', 'eliminar'],
            'etiquetas-actividad' => ['ver', 'crear', 'editar', 'eliminar'],
        ],
        'integraciones' => [
            'dialpad' => ['ver', 'sync'],
            'outlook' => ['ver'],
        ],
    ];

    public const NOMBRES = [
        'vendedor' => 'Vendedor',
        'gerencia' => 'Gerencia comercial',
        'administracion' => 'Administración',
    ];

    public const DESCRIPCIONES = [
        'vendedor' => 'Agenda, oportunidades, clientes, prospectos, cotizaciones y actividades. Ve sus propias metas y su tablero. Sin catálogos ni aprobaciones.',
        'gerencia' => 'Todo lo del vendedor sobre cualquier vendedor, más aprobar cotizaciones, asignar vendedores, definir metas, tablero ejecutivo y clasificar llamadas.',
        'administracion' => 'Catálogos, configuración comercial e integraciones. Sin acceso a la operación de ventas.',
    ];

    /** @return array<string, array<string, array<string, string[]>>> */
    public static function definiciones(): array
    {
        return [
            'vendedor' => self::BASE_VENDEDOR,
            'gerencia' => array_merge_recursive(self::BASE_VENDEDOR, self::EXTRA_GERENCIA),
            'administracion' => self::ADMINISTRACION,
        ];
    }

    /**
     * Roles del Equipo comercial. Se suman: los permisos de una persona son la
     * unión de las definiciones de sus roles. No se guardan, se deducen
     * (ver DeduccionRolesCrm).
     */
    public const ROLES = ['vende', 'gerencia', 'catalogos'];

    /**
     * Unión de las definiciones de los roles pedidos, con la forma de
     * definiciones(): vende → base de venta, gerencia → perfil gerencia
     * (base + extras) y catalogos → administración.
     *
     * @param  string[]  $roles  subconjunto de ROLES
     * @return array<string, array<string, string[]>>
     *
     * @throws InvalidArgumentException si algún rol no existe.
     */
    public static function definicionRoles(array $roles): array
    {
        $definiciones = self::definiciones();
        $porRol = [
            'vende' => $definiciones['vendedor'],
            'gerencia' => $definiciones['gerencia'],
            'catalogos' => $definiciones['administracion'],
        ];

        $union = [];
        foreach (array_unique($roles) as $rol) {
            if (! isset($porRol[$rol])) {
                throw new InvalidArgumentException("El rol \"{$rol}\" no existe.");
            }
            foreach ($porRol[$rol] as $modulo => $submodulos) {
                foreach ($submodulos as $submodulo => $permisos) {
                    $union[$modulo][$submodulo] = array_values(array_unique(array_merge(
                        $union[$modulo][$submodulo] ?? [],
                        $permisos,
                    )));
                }
            }
        }

        return $union;
    }

    /** @return string[] */
    public static function slugs(): array
    {
        return array_keys(self::NOMBRES);
    }

    /** Lista para la interfaz de administración. */
    public function perfiles(): array
    {
        return array_map(fn (string $slug) => [
            'slug' => $slug,
            'nombre' => self::NOMBRES[$slug],
            'descripcion' => self::DESCRIPCIONES[$slug],
        ], self::slugs());
    }

    /**
     * Reemplaza los accesos del usuario en el CRM de la empresa por el perfil.
     *
     * @return array{perfil: string, modulos_activos: int, permisos_otorgados: int}
     *
     * @throws InvalidArgumentException si el perfil no existe o la empresa no tiene CRM.
     */
    public function aplicar(User $usuario, Enterprise $empresa, string $perfil): array
    {
        $definiciones = self::definiciones();
        if (! array_key_exists($perfil, $definiciones)) {
            throw new InvalidArgumentException("El perfil \"{$perfil}\" no existe.");
        }

        return ['perfil' => $perfil] + $this->reemplazar($usuario, $empresa, $definiciones[$perfil]);
    }

    /**
     * Reemplaza los accesos del usuario en el CRM de la empresa por la UNIÓN
     * de los roles marcados (vende, gerencia, catalogos), con la misma
     * mecánica de aplicar(). Con `$roles === []` la persona pierde el acceso
     * a la aplicación CRM de esa empresa; su acceso a la empresa y a otras
     * aplicaciones no cambia. No toca la ficha de vendedor.
     *
     * @param  string[]  $roles  subconjunto de ROLES
     * @return array{modulos_activos: int, permisos_otorgados: int}
     *
     * @throws InvalidArgumentException si algún rol no existe o la empresa no tiene CRM.
     */
    public function aplicarRoles(User $usuario, Enterprise $empresa, array $roles): array
    {
        return $this->reemplazar($usuario, $empresa, self::definicionRoles($roles));
    }

    /**
     * Reemplaza todos los accesos y permisos del usuario dentro de la
     * aplicación `crm` de la empresa por la definición dada (módulo →
     * submódulo → permisos). Lo que está en la definición queda activo y todo
     * lo demás del CRM queda inactivo; con una definición vacía también se
     * apaga el acceso a la aplicación. Nada fuera de ese CRM se toca.
     *
     * @param  array<string, array<string, string[]>>  $definicion
     * @return array{modulos_activos: int, permisos_otorgados: int}
     *
     * @throws InvalidArgumentException si la empresa no tiene CRM.
     */
    private function reemplazar(User $usuario, Enterprise $empresa, array $definicion): array
    {
        $app = Application::where('enterprise_id', $empresa->id)
            ->where('slug', 'crm')
            ->with('modules.submodules.permissionTypes')
            ->first();

        if (! $app) {
            throw new InvalidArgumentException('La empresa no tiene el CRM Comercial configurado.');
        }

        return DB::transaction(function () use ($usuario, $app, $definicion) {
            $ahora = now();
            $modulosActivos = 0;
            $permisosOtorgados = 0;

            $aplicacionActiva = $definicion !== [];
            UserApplicationAccess::updateOrCreate(
                ['user_id' => $usuario->id, 'application_id' => $app->id],
                ['is_active' => $aplicacionActiva] + ($aplicacionActiva ? ['granted_at' => $ahora] : []),
            );

            foreach ($app->modules as $modulo) {
                $submodulosDelPerfil = $definicion[$modulo->slug] ?? [];
                $moduloActivo = $submodulosDelPerfil !== [];

                UserModuleAccess::updateOrCreate(
                    ['user_id' => $usuario->id, 'module_id' => $modulo->id],
                    ['is_active' => $moduloActivo] + ($moduloActivo ? ['granted_at' => $ahora] : []),
                );
                $modulosActivos += $moduloActivo ? 1 : 0;

                foreach ($modulo->submodules as $submodulo) {
                    $permisosDelPerfil = array_unique($submodulosDelPerfil[$submodulo->slug] ?? []);
                    $submoduloActivo = $permisosDelPerfil !== [];

                    UserSubmoduleAccess::updateOrCreate(
                        ['user_id' => $usuario->id, 'submodule_id' => $submodulo->id],
                        ['is_active' => $submoduloActivo] + ($submoduloActivo ? ['granted_at' => $ahora] : []),
                    );

                    foreach ($submodulo->permissionTypes as $tipo) {
                        $otorgado = in_array($tipo->slug, $permisosDelPerfil, true);

                        UserSubmodulePermission::updateOrCreate(
                            [
                                'user_id' => $usuario->id,
                                'submodule_id' => $submodulo->id,
                                'permission_type_id' => $tipo->id,
                            ],
                            ['is_granted' => $otorgado],
                        );
                        $permisosOtorgados += $otorgado ? 1 : 0;
                    }
                }
            }

            return [
                'modulos_activos' => $modulosActivos,
                'permisos_otorgados' => $permisosOtorgados,
            ];
        });
    }
}
