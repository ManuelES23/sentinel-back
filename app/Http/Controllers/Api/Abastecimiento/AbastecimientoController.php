<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Events\AbastecimientoUpdated;
use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use App\Services\Abastecimiento\ContextoAbastecimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Base de los controladores de Administración → Abastecimiento. */
abstract class AbastecimientoController extends Controller
{
    public function __construct(protected ContextoAbastecimiento $ctx)
    {
    }

    /**
     * Para los endpoints que responden con dinero (la cuenta de un convenio, la previa de una liquidación):
     * exigen `view` del submódulo ADEMÁS de su permiso propio (el administrador siempre pasa). Se llama antes de
     * validar, bloquear o escribir: un 403 no deja rastro.
     */
    protected function autorizarConVer(Request $request, string $submodulo, string $permiso): Enterprise
    {
        $this->ctx->autorizar($request, $submodulo, 'view');

        return $this->ctx->autorizar($request, $submodulo, $permiso);
    }

    /**
     * Emite el cambio en tiempo real. Se llama después del commit y sin dejar
     * que una falla del servidor de websockets convierta la respuesta en 500:
     * el registro ya quedó guardado y la pantalla recarga por su cuenta.
     *
     * @param  string  $entidad  convenio|recepcion|productor|temporada|cultivo|tipo_carga|liquidacion|pago
     */
    protected function difundir(string $entidad, string $accion, array $data, Enterprise $empresa): void
    {
        try {
            $pendiente = broadcast(new AbastecimientoUpdated($accion, $data, $empresa->slug, $entidad));
            // PendingBroadcast emite al destruirse; forzarlo aquí mantiene la excepción dentro del try.
            unset($pendiente);
        } catch (\Throwable $e) {
            Log::warning('Abastecimiento: no se pudo emitir el evento en tiempo real', [
                'entidad' => $entidad,
                'accion' => $accion,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
