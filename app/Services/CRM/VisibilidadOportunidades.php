<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmOportunidad;
use App\Support\CRM\AlcanceOportunidades;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Regla única de visibilidad de oportunidades del CRM.
 *
 * - `oportunidades.ver` es requisito para ver cualquier oportunidad.
 * - Con `oportunidades.equipo` (perfil gerencia) se ve a todo el equipo.
 * - Sin él, solo las del CrmVendedor ACTIVO del usuario en la empresa.
 *
 * Dos sabores:
 * - Estricto (endpoints de oportunidades): exigirVer, vendedorVisible y
 *   exigirVisible abortan con 403.
 * - Alcance (datos incrustados en otras respuestas): alcance() no aborta y
 *   devuelve un AlcanceOportunidades para filtrar la consulta.
 * - entidadVisible(): para agenda, actividades y seguimientos que se cuelgan
 *   de una oportunidad; la no visible se trata como inexistente.
 *
 * El Dashboard NO usa esta regla: su visibilidad depende de
 * dashboard.ejecutivo (ver DashboardController::resolverVendedor).
 */
class VisibilidadOportunidades
{
    use VerificaPermisoSubmodulo;

    public const MENSAJE_SIN_VER = 'No tienes permiso para ver oportunidades.';

    public const MENSAJE_SIN_VENDEDOR = 'Tu usuario no está vinculado a un vendedor activo.';

    public const MENSAJE_AJENA = 'No puedes ver las oportunidades de otro vendedor.';

    public const MENSAJE_MODIFICAR_AJENA = 'No puedes modificar las oportunidades de otro vendedor.';

    public function __construct(private readonly VendedorActualService $vendedores) {}

    public function puedeVer(int $empresaId): bool
    {
        return $this->tienePermisoSubmodulo($empresaId, 'oportunidades', 'oportunidades', 'ver');
    }

    public function veAlEquipo(int $empresaId): bool
    {
        return $this->tienePermisoSubmodulo($empresaId, 'oportunidades', 'oportunidades', 'equipo');
    }

    /** Id del CrmVendedor activo del usuario autenticado en la empresa, o null. */
    public function vendedorPropioId(int $empresaId): ?int
    {
        $propio = $this->vendedores->vendedorDelUsuario($empresaId, (int) Auth::id());

        return $propio ? (int) $propio->id : null;
    }

    /** 403 si el usuario no tiene oportunidades.ver. */
    public function exigirVer(?int $empresaId): void
    {
        $this->exigirPermisoSubmodulo($empresaId, 'oportunidades', 'oportunidades', 'ver', self::MENSAJE_SIN_VER);
    }

    /**
     * Vendedor cuyas oportunidades puede leer el usuario (asume `ver` ya
     * exigido). Con `equipo` devuelve lo solicitado (null = todo el equipo);
     * sin él queda limitado a su propio CrmVendedor activo: si no lo tiene
     * vinculado, o pide el de otro, 403.
     */
    public function vendedorVisible(int $empresaId, ?int $solicitado, string $mensajeAjeno = self::MENSAJE_AJENA): ?int
    {
        if ($this->veAlEquipo($empresaId)) {
            return $solicitado;
        }

        $propio = $this->vendedorPropioId($empresaId);
        abort_unless($propio !== null, 403, self::MENSAJE_SIN_VENDEDOR);
        abort_unless($solicitado === null || $solicitado === $propio, 403, $mensajeAjeno);

        return $propio;
    }

    /**
     * 403 si el usuario no puede ver ESTA oportunidad: sin `ver`, sin vendedor
     * activo vinculado (y sin `equipo`) o si es de otro vendedor. Una
     * oportunidad sin vendedor solo la ve quien tiene `equipo`.
     */
    public function exigirVisible(int $empresaId, CrmOportunidad $oportunidad, string $mensajeAjena = self::MENSAJE_AJENA): void
    {
        $this->exigirVer($empresaId);
        $this->vendedorVisible($empresaId, (int) $oportunidad->vendedor_id, $mensajeAjena);
    }

    /** Sin abortar: si el usuario puede ver ESTA oportunidad (misma regla que exigirVisible). */
    public function esVisible(CrmOportunidad $oportunidad): bool
    {
        return $this->alcance((int) $oportunidad->empresa_id)->incluye($oportunidad);
    }

    /**
     * Para las escrituras que cuelgan algo de una entidad (agenda, actividades,
     * seguimientos): devuelve null si la entidad es una oportunidad que el
     * usuario no puede ver, para que el endpoint responda exactamente igual
     * que si no existiera y no sirva de oráculo de existencia.
     *
     * @template T of Model
     *
     * @param  T|null  $entidad
     * @return T|null
     */
    public function entidadVisible(?Model $entidad): ?Model
    {
        if ($entidad instanceof CrmOportunidad && ! $this->esVisible($entidad)) {
            return null;
        }

        return $entidad;
    }

    /** Sabor que no aborta, para oportunidades incrustadas en otras respuestas. */
    public function alcance(int $empresaId): AlcanceOportunidades
    {
        if (! $this->puedeVer($empresaId)) {
            return new AlcanceOportunidades($empresaId, false, false, null);
        }

        if ($this->veAlEquipo($empresaId)) {
            return new AlcanceOportunidades($empresaId, true, true, null);
        }

        return new AlcanceOportunidades($empresaId, true, false, $this->vendedorPropioId($empresaId));
    }
}
