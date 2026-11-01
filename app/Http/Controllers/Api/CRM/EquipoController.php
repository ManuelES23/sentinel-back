<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\VendedorUpdated;
use App\Exceptions\CRM\RolesEquipoException;
use App\Models\CRM\CrmVendedor;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\CRM\CambioRolesCrm;
use App\Services\CRM\DeduccionRolesCrm;
use App\Traits\CRM\FiltraPorEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EquipoController
 * Equipo comercial del CRM (spec 2026-11-01): una pantalla donde cada persona
 * tiene tres roles que se suman (Vende, Gerencia, Catálogos). Los roles se
 * deducen de los permisos reales y de la ficha de vendedor; guardar los
 * reescribe en un solo paso, todo o nada. Multi-tenant por la empresa de
 * contexto (X-Enterprise-Id, validada contra el acceso activo del usuario).
 *
 * Quién puede qué lo decide CambioRolesCrm comparando lo pedido contra lo
 * deducido; aquí solo se exige poder VER el Equipo comercial (Administrador o
 * catalogos.vendedores.ver), porque todas las respuestas traen la lista.
 */
class EquipoController extends CrmBaseController
{
    use FiltraPorEmpresa;

    public function __construct(
        private readonly DeduccionRolesCrm $deduccion,
        private readonly CambioRolesCrm $cambio,
    ) {}

    /**
     * GET /crm/equipo
     */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->empresaDeContexto();
        $permisos = $this->exigirVer($empresa, $request->user());

        try {
            return $this->jsonSuccess($this->cuerpo($empresa, $permisos));
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * PUT /crm/equipo
     * Guarda todos los cambios de roles juntos (todo o nada) y devuelve la
     * lista actualizada. Emite VendedorUpdated por cada ficha creada o
     * cambiada, solo después de confirmar.
     */
    public function guardar(Request $request): JsonResponse
    {
        $empresa = $this->empresaDeContexto();
        $actor = $request->user();
        $this->exigirVer($empresa, $actor);

        $validated = $request->validate([
            'cambios' => 'required|array|min:1|max:50',
            'cambios.*.user_id' => 'required|integer|distinct|exists:users,id',
            'cambios.*.antes' => 'required|array',
            'cambios.*.antes.vende' => 'required|boolean',
            'cambios.*.antes.gerencia' => 'required|boolean',
            'cambios.*.antes.catalogos' => 'required|boolean',
            'cambios.*.vende' => 'required|boolean',
            'cambios.*.gerencia' => 'required|boolean',
            'cambios.*.catalogos' => 'required|boolean',
        ], [
            'cambios.max' => 'Se pueden guardar hasta 50 personas a la vez.',
        ]);

        try {
            $fichas = $this->cambio->guardar($empresa, $actor, $validated['cambios']);
            $this->difundirFichas($fichas);

            return $this->jsonSuccess($this->cuerpo($empresa, $this->cambio->permisos($empresa, $actor)), 'Equipo comercial guardado correctamente');
        } catch (RolesEquipoException $e) {
            return $this->jsonError($e->getMessage(), $e->status());
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * POST /crm/equipo/fichas/{vendedor}/ligar
     * Liga una ficha sin usuario a un usuario, al momento, y le suma Vende.
     */
    public function ligar(Request $request, CrmVendedor $vendedor): JsonResponse
    {
        $empresa = $this->empresaDeContexto();
        $actor = $request->user();
        $this->exigirVer($empresa, $actor);

        // La ficha debe ser de la empresa de contexto: una de otra empresa no existe para esta.
        if ((int) $vendedor->empresa_id !== $empresa->id) {
            return $this->jsonError('La ficha no pertenece a esta empresa.', 404);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        try {
            $fichas = $this->cambio->ligar($empresa, $actor, $vendedor, User::findOrFail($validated['user_id']));
            $this->difundirFichas($fichas);

            return $this->jsonSuccess($this->cuerpo($empresa, $this->cambio->permisos($empresa, $actor)), 'Usuario ligado a la ficha correctamente');
        } catch (RolesEquipoException $e) {
            return $this->jsonError($e->getMessage(), $e->status());
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    // ── Internos ──────────────────────────────────────────────────────────

    /** Empresa de contexto ya validada contra el acceso del usuario. */
    private function empresaDeContexto(): Enterprise
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        return Enterprise::findOrFail($empresaId);
    }

    /**
     * Administrador o catalogos.vendedores.ver. Devuelve los permisos de quien
     * pregunta para no calcularlos dos veces.
     *
     * @return array<string, mixed>
     */
    private function exigirVer(Enterprise $empresa, User $actor): array
    {
        $permisos = $this->cambio->permisos($empresa, $actor);
        abort_unless($permisos['ver'], 403, 'No tienes permiso para ver el Equipo comercial.');

        return $permisos;
    }

    /**
     * Cuerpo común de las tres respuestas. Los usuarios disponibles solo
     * salen para quien puede marcar Vende (Administrador o con `crear`).
     *
     * @param  array<string, mixed>  $permisos
     * @return array{personas: mixed, usuarios_disponibles: mixed, permisos: array<string, mixed>}
     *
     * @throws InvalidArgumentException si la empresa no tiene el CRM.
     */
    private function cuerpo(Enterprise $empresa, array $permisos): array
    {
        return [
            'personas' => $this->deduccion->deEmpresa($empresa)->values(),
            'usuarios_disponibles' => $permisos['marcar_vende']
                ? $this->deduccion->usuariosDisponibles($empresa)->values()
                : [],
            'permisos' => $permisos,
        ];
    }

    /**
     * Emite VendedorUpdated por cada ficha creada o cambiada. Solo se llama
     * con lo que el servicio devolvió tras confirmar la transacción.
     *
     * @param  array<int, array{accion: string, vendedor: CrmVendedor}>  $fichas
     */
    private function difundirFichas(array $fichas): void
    {
        foreach ($fichas as $item) {
            $this->difundir(new VendedorUpdated($item['accion'], $item['vendedor']->toArray()));
        }
    }
}
