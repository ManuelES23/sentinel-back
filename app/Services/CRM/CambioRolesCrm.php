<?php

namespace App\Services\CRM;

use App\Exceptions\CRM\RolesEquipoException;
use App\Models\ActivityLog;
use App\Models\CRM\CrmVendedor;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Support\PermisoSubmodulo;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cambia los roles del Equipo comercial (vende, gerencia, catálogos) de una o
 * varias personas en una empresa. Spec 2026-11-01 §5 y §6.
 *
 * - Los roles se suman: los permisos del CRM de la persona pasan a ser la
 *   unión de los roles marcados (CrmPerfilPermisosService::aplicarRoles).
 * - Vende además maneja la ficha: marcarlo reactiva la ficha de la persona o
 *   crea una con sus datos de usuario; quitarlo la desactiva sin borrarla
 *   (sus oportunidades, clientes y agenda siguen a su nombre). Nunca liga sola
 *   una ficha sin usuario: eso lo hace ligar().
 * - Todo va en UNA transacción: una fila inválida detiene el lote completo
 *   (permisos, fichas y bitácora incluidos).
 * - La autorización se decide comparando lo pedido contra lo deducido.
 *
 * Este servicio NO difunde eventos: devuelve las fichas creadas o cambiadas
 * para que quien lo llama emita VendedorUpdated después del commit.
 */
class CambioRolesCrm
{
    /** Mismo mensaje que PerfilPermisosController::aplicar. */
    public const SIN_ACCESO = 'El usuario no tiene acceso a esta empresa. Dale acceso a la empresa antes de aplicar un perfil del CRM.';

    /** Mismo criterio que PerfilPermisosController::ROLES_ADMIN. */
    private const ROLES_ADMIN = ['admin', 'superadmin'];

    public function __construct(
        private readonly DeduccionRolesCrm $deduccion,
        private readonly CrmPerfilPermisosService $perfiles,
    ) {}

    /**
     * Guarda todos los cambios juntos, todo o nada.
     *
     * @param  array<int, array{user_id: int, antes: array{vende: bool, gerencia: bool, catalogos: bool}, vende: bool, gerencia: bool, catalogos: bool}>  $cambios
     * @return array<int, array{accion: 'created'|'updated', vendedor: CrmVendedor}> fichas para difundir tras el commit
     *
     * @throws RolesEquipoException 403 sin permiso, 409 si los roles ya cambiaron, 422 si el dato no es válido.
     */
    public function guardar(Enterprise $empresa, User $actor, array $cambios): array
    {
        $this->exigirCrm($empresa);
        $pedidos = $this->normalizar($cambios);

        $ids = array_column($pedidos, 'user_id');
        $esAdmin = $this->esAdministrador($actor);
        $puede = $this->permisosDeVendedores($empresa, $actor);

        // Quien no es Administrador solo puede tocar a gente de su empresa: un
        // id ajeno (o inexistente) responde igual y sin nombre, antes de
        // comparar nada, para no poder listar los nombres de todos los usuarios.
        if (! $esAdmin) {
            $miembros = $this->deduccion->conVinculo($empresa, $ids);
            foreach ($ids as $id) {
                if (! isset($miembros[$id])) {
                    throw new RolesEquipoException("El usuario {$id} no pertenece a esta empresa.", 422);
                }
            }
        }

        $salida = DB::transaction(function () use ($empresa, $actor, $pedidos, $ids, $esAdmin, $puede) {
            // Primera sentencia: bloquea las filas de las personas (en orden de
            // id, para no cruzarse con otro guardado) y fija la lectura, así
            // dos guardados de la misma persona se turnan y el segundo ve lo
            // que confirmó el primero (y responde 409 si ya no coincide).
            $usuarios = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $salida = [];

            foreach ($pedidos as $pedido) {
                $usuario = $usuarios->get($pedido['user_id'])
                    ?? throw new RolesEquipoException("No existe el usuario {$pedido['user_id']}.", 422);

                // 1. Lo que hay hoy.
                $hoy = $this->deduccion->dePersona($usuario, $empresa);

                // 2. La pantalla veía otra cosa: alguien más cambió esta persona.
                if ($pedido['antes'] !== $hoy['roles']) {
                    throw new RolesEquipoException("Los roles de {$usuario->name} cambiaron; recarga la pantalla.", 409);
                }

                // 3. Sin cambio de roles no se escribe nada, aunque esté ajustada.
                if ($pedido['roles'] === $hoy['roles']) {
                    continue;
                }

                // 4. Quién puede hacer qué.
                if (! $esAdmin) {
                    $this->autorizar($actor, $usuario, $hoy, $pedido['roles'], $puede);
                }

                // 5. Terminar con algún rol exige acceso activo a la empresa;
                //    quitar roles no, para dar de baja a quien ya lo perdió.
                if (in_array(true, $pedido['roles'], true) && ! $this->tieneAccesoActivo($usuario, $empresa)) {
                    throw new RolesEquipoException(self::SIN_ACCESO, 422);
                }

                // 6. Permisos y ficha.
                $this->perfiles->aplicarRoles($usuario, $empresa, $this->claves($pedido['roles']));
                $item = $this->sincronizarFicha($empresa, $usuario, $hoy['roles'], $pedido['roles']);
                if ($item) {
                    $salida[] = $item;
                }

                // 7. Bitácora.
                $this->registrar($usuario, $empresa, $hoy['roles'], $pedido['roles']);
            }

            return $salida;
        });

        return $this->conUsuario($salida);
    }

    /**
     * Liga una ficha sin usuario a un usuario, al momento. Le suma Vende a los
     * roles que ya tenía. Responde 422 si la ficha ya tiene usuario, si el
     * usuario ya tiene una ficha en la empresa (activa o no) o si no tiene
     * acceso activo a ella.
     *
     * @return array<int, array{accion: 'updated', vendedor: CrmVendedor}>
     *
     * @throws RolesEquipoException
     */
    public function ligar(Enterprise $empresa, User $actor, CrmVendedor $ficha, User $usuario): array
    {
        $this->exigirCrm($empresa);
        $esAdmin = $this->esAdministrador($actor);
        $puede = $this->permisosDeVendedores($empresa, $actor);

        $salida = DB::transaction(function () use ($empresa, $actor, $ficha, $usuario, $esAdmin, $puede) {
            // Primero la fila del usuario y luego la de la ficha (mismo orden
            // que guardar): dos personas ligando o guardando a la vez se turnan.
            $usuario = User::lockForUpdate()->find($usuario->id)
                ?? throw new RolesEquipoException("No existe el usuario {$usuario->id}.", 422);
            $ficha = CrmVendedor::where('empresa_id', $empresa->id)->lockForUpdate()->find($ficha->id)
                ?? throw new RolesEquipoException('La ficha no pertenece a esta empresa.', 404);

            if (! $esAdmin) {
                if (! $puede('editar')) {
                    throw new RolesEquipoException('No tienes permiso para ligar un usuario a una ficha.', 403);
                }
                if ($actor->id === $usuario->id) {
                    throw new RolesEquipoException("No puedes ligarte a ti mismo ({$usuario->name}); pídele a un administrador que lo haga.", 403);
                }
            }

            $hoy = $this->deduccion->dePersona($usuario, $empresa);
            if (! $esAdmin && $hoy['ajustado']) {
                throw new RolesEquipoException("{$usuario->name} tiene permisos ajustados a mano; solo un administrador puede cambiarlos.", 403);
            }

            if ($ficha->user_id !== null) {
                throw new RolesEquipoException("La ficha de {$ficha->nombre} ya tiene un usuario.", 422);
            }
            if (CrmVendedor::where('empresa_id', $empresa->id)->where('user_id', $usuario->id)->exists()) {
                throw new RolesEquipoException("{$usuario->name} ya tiene una ficha de vendedor en esta empresa.", 422);
            }
            if (! $this->tieneAccesoActivo($usuario, $empresa)) {
                throw new RolesEquipoException(self::SIN_ACCESO, 422);
            }

            // Ruling: un no administrador no toca a un administrador del sistema.
            // Va después de los 422 para no confirmar el nombre de quien no es de la empresa.
            if (! $esAdmin && $this->esAdministrador($usuario)) {
                throw new RolesEquipoException("Solo un administrador puede cambiar los roles de {$usuario->name}.", 403);
            }

            $despues = ['vende' => true] + $hoy['roles'];
            try {
                $ficha->update(['user_id' => $usuario->id, 'activo' => true]);
            } catch (UniqueConstraintViolationException) {
                // Otra sesión le dio una ficha a este usuario mientras tanto.
                throw new RolesEquipoException("Los roles de {$usuario->name} cambiaron; recarga la pantalla.", 409);
            }
            $this->perfiles->aplicarRoles($usuario, $empresa, $this->claves($despues));
            $this->registrar($usuario, $empresa, $hoy['roles'], $despues);

            return [['accion' => 'updated', 'vendedor' => $ficha]];
        });

        return $this->conUsuario($salida);
    }

    /**
     * Qué puede hacer quien pregunta, para deshabilitar casillas y botones.
     * Un Administrador puede todo; los demás, según catalogos.vendedores.
     *
     * @return array{user_id: int, roles_completos: bool, marcar_vende: bool, quitar_vende: bool, editar_ficha: bool, crear_ficha: bool, eliminar_ficha: bool, ver: bool}
     */
    public function permisos(Enterprise $empresa, User $actor): array
    {
        $esAdmin = $this->esAdministrador($actor);
        $puede = $esAdmin ? fn (string $slug) => true : $this->permisosDeVendedores($empresa, $actor);

        return [
            'user_id' => $actor->id,
            'roles_completos' => $esAdmin,
            'marcar_vende' => $puede('crear'),
            'quitar_vende' => $puede('editar'),
            'editar_ficha' => $puede('editar'),
            'crear_ficha' => $puede('crear'),
            'eliminar_ficha' => $puede('eliminar'),
            'ver' => $puede('ver'),
        ];
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /** Administrador = rol del sistema `admin` o `superadmin`. */
    private function esAdministrador(User $actor): bool
    {
        return in_array($actor->role, self::ROLES_ADMIN, true);
    }

    /**
     * Consulta perezosa y con memoria de los permisos de quien captura en
     * catalogos.vendedores: cada permiso se pregunta una sola vez.
     *
     * @return Closure(string): bool
     */
    private function permisosDeVendedores(Enterprise $empresa, User $actor): Closure
    {
        $memoria = [];

        return function (string $slug) use ($empresa, $actor, &$memoria): bool {
            return $memoria[$slug] ??= PermisoSubmodulo::tiene($actor->id, $empresa->id, 'crm', 'catalogos', 'vendedores', $slug);
        };
    }

    /**
     * Autorización de quien no es Administrador sobre UNA persona cuyos roles
     * cambian (los pedidos ya difieren de los deducidos).
     *
     * @param  array{roles: array{vende: bool, gerencia: bool, catalogos: bool}, ajustado: bool}  $hoy
     * @param  array{vende: bool, gerencia: bool, catalogos: bool}  $pedido
     * @param  Closure(string): bool  $puede
     *
     * @throws RolesEquipoException 403
     */
    private function autorizar(User $actor, User $usuario, array $hoy, array $pedido, Closure $puede): void
    {
        $nombre = $usuario->name;

        if ($actor->id === $usuario->id) {
            throw new RolesEquipoException("No puedes cambiar tus propios roles ({$nombre}); pídele a un administrador que lo haga.", 403);
        }
        if ($this->esAdministrador($usuario)) {
            throw new RolesEquipoException("Solo un administrador puede cambiar los roles de {$nombre}.", 403);
        }
        if ($hoy['ajustado']) {
            throw new RolesEquipoException("{$nombre} tiene permisos ajustados a mano; solo un administrador puede cambiarlos.", 403);
        }
        if ($pedido['gerencia'] !== $hoy['roles']['gerencia'] || $pedido['catalogos'] !== $hoy['roles']['catalogos']) {
            throw new RolesEquipoException("Solo un administrador puede cambiar Gerencia o Catálogos de {$nombre}.", 403);
        }
        if ($pedido['vende'] && ! $hoy['roles']['vende'] && ! $puede('crear')) {
            throw new RolesEquipoException("No tienes permiso para marcar Vende a {$nombre}.", 403);
        }
        if (! $pedido['vende'] && $hoy['roles']['vende'] && ! $puede('editar')) {
            throw new RolesEquipoException("No tienes permiso para quitar Vende a {$nombre}.", 403);
        }
    }

    /**
     * Marcar Vende reactiva la ficha del usuario en la empresa o crea una con
     * sus datos; quitarlo la desactiva sin borrarla. Nunca toma una ficha sin
     * usuario. Devuelve la ficha para difundir, o null si no cambió ninguna.
     *
     * @param  array{vende: bool, gerencia: bool, catalogos: bool}  $antes
     * @param  array{vende: bool, gerencia: bool, catalogos: bool}  $despues
     * @return array{accion: 'created'|'updated', vendedor: CrmVendedor}|null
     */
    private function sincronizarFicha(Enterprise $empresa, User $usuario, array $antes, array $despues): ?array
    {
        if ($antes['vende'] === $despues['vende']) {
            return null;
        }

        $ficha = CrmVendedor::where('empresa_id', $empresa->id)->where('user_id', $usuario->id)->first();

        if ($despues['vende']) {
            if ($ficha) {
                $ficha->update(['activo' => true]);

                return ['accion' => 'updated', 'vendedor' => $ficha];
            }

            try {
                $nueva = CrmVendedor::create([
                    'empresa_id' => $empresa->id,
                    'user_id' => $usuario->id,
                    'nombre' => $usuario->name,
                    'email' => $usuario->email,
                    'telefono' => $usuario->phone,
                    'activo' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Otra sesión creó la ficha de esta persona mientras tanto.
                throw new RolesEquipoException("Los roles de {$usuario->name} cambiaron; recarga la pantalla.", 409);
            }

            return ['accion' => 'created', 'vendedor' => $nueva];
        }

        if ($ficha && $ficha->activo) {
            $ficha->update(['activo' => false]);

            return ['accion' => 'updated', 'vendedor' => $ficha];
        }

        return null;
    }

    /**
     * @param  array{vende: bool, gerencia: bool, catalogos: bool}  $antes
     * @param  array{vende: bool, gerencia: bool, catalogos: bool}  $despues
     */
    private function registrar(User $usuario, Enterprise $empresa, array $antes, array $despues): void
    {
        ActivityLog::log(
            action: 'roles_crm',
            model: 'User',
            modelId: $usuario->id,
            oldValues: $antes + ['enterprise_id' => $empresa->id],
            newValues: $despues + ['enterprise_id' => $empresa->id],
        );
    }

    /**
     * @param  array{vende: bool, gerencia: bool, catalogos: bool}  $roles
     * @return string[]
     */
    private function claves(array $roles): array
    {
        return array_keys(array_filter($roles));
    }

    private function tieneAccesoActivo(User $usuario, Enterprise $empresa): bool
    {
        return UserEnterpriseAccess::where('enterprise_id', $empresa->id)
            ->where('user_id', $usuario->id)->where('is_active', true)->exists();
    }

    /** @throws RolesEquipoException 422 si la empresa no tiene el CRM. */
    private function exigirCrm(Enterprise $empresa): void
    {
        try {
            $this->deduccion->appCrm($empresa);
        } catch (InvalidArgumentException $e) {
            throw new RolesEquipoException($e->getMessage(), 422);
        }
    }

    /**
     * Valida la forma de los cambios y fija el orden y el tipo de los roles
     * para poder compararlos con `===`.
     *
     * @return array<int, array{user_id: int, antes: array{vende: bool, gerencia: bool, catalogos: bool}, roles: array{vende: bool, gerencia: bool, catalogos: bool}}>
     *
     * @throws RolesEquipoException 422
     */
    private function normalizar(array $cambios): array
    {
        $pedidos = [];
        $vistos = [];

        foreach ($cambios as $cambio) {
            $userId = is_array($cambio) ? filter_var($cambio['user_id'] ?? null, FILTER_VALIDATE_INT) : false;
            $antes = is_array($cambio) ? $this->normalizarRoles($cambio['antes'] ?? null) : null;
            $roles = is_array($cambio) ? $this->normalizarRoles($cambio) : null;

            if ($userId === false || $antes === null || $roles === null) {
                throw new RolesEquipoException('Cada cambio necesita user_id, los roles de antes y los roles pedidos.', 422);
            }
            if (isset($vistos[$userId])) {
                throw new RolesEquipoException("El usuario {$userId} viene repetido en el guardado.", 422);
            }
            $vistos[$userId] = true;

            $pedidos[] = ['user_id' => $userId, 'antes' => $antes, 'roles' => $roles];
        }

        return $pedidos;
    }

    /** @return array{vende: bool, gerencia: bool, catalogos: bool}|null */
    private function normalizarRoles(mixed $origen): ?array
    {
        if (! is_array($origen)) {
            return null;
        }

        $roles = [];
        foreach (CrmPerfilPermisosService::ROLES as $rol) {
            if (! array_key_exists($rol, $origen)) {
                return null;
            }
            $valor = filter_var($origen[$rol], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($valor === null) {
                return null;
            }
            $roles[$rol] = $valor;
        }

        return $roles;
    }

    /**
     * Carga el usuario de todas las fichas con una sola consulta.
     *
     * @param  array<int, array{accion: string, vendedor: CrmVendedor}>  $salida
     */
    private function conUsuario(array $salida): array
    {
        EloquentCollection::make(array_column($salida, 'vendedor'))->load('user:id,name,email');

        return $salida;
    }
}
