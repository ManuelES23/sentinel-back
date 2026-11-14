<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmConfiguracionComercial;
use App\Services\CRM\ImportacionCatalogo\ImportadorCatalogo;
use App\Services\CRM\ImportacionCatalogo\LectorCatalogo;
use App\Services\CRM\ImportacionCatalogo\RevisionCatalogo;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Importación del catálogo de productos desde Excel, en dos pasos: revisar (no escribe nada, guarda el archivo
 * leído con un token ligado al usuario y la empresa) e importar (aplica; el Excel manda).
 */
class ImportacionCatalogoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const DISCO = 'local';

    private const CARPETA = 'crm/importaciones';

    private const VIGENCIA_HORAS = 2;

    private const VENCIDA = 'La revisión venció; vuelve a subir el archivo.';

    /** POST /crm/productos/importar/revisar */
    public function revisar(Request $request): JsonResponse
    {
        $empresaId = $this->preparar($request);
        $request->validate(['archivo' => 'required|file|mimes:xlsx|max:10240']);
        $this->limpiarVencidos();

        $archivo = $request->file('archivo');
        try {
            $lectura = (new LectorCatalogo())->leer($archivo->getRealPath());
        } catch (RuntimeException $e) {
            return $this->rechazar($e->getMessage(), 422);
        }
        $resumen = (new RevisionCatalogo())->revisar($empresaId, $lectura);

        $token = (string) Str::uuid();
        $ruta = Storage::disk(self::DISCO)->putFileAs(self::CARPETA, $archivo, $token.'.xlsx');
        if ($ruta === false) {
            return $this->rechazar('No se pudo guardar el archivo; inténtalo de nuevo.', 422);
        }
        $this->guardarToken($token, $request, $empresaId, $ruta, $resumen['huella']);

        return $this->jsonSuccess(['token' => $token, 'resumen' => $resumen], 'Revisión lista');
    }

    /** POST /crm/productos/importar */
    public function importar(Request $request): JsonResponse
    {
        $empresaId = $this->preparar($request);
        $datos = $request->validate([
            'token' => 'required|string|max:64',
            'elegidas' => 'nullable|array',
            'desactivar_faltantes' => 'nullable|boolean',
        ]);
        $elegidas = $datos['elegidas'] ?? [];
        $desactivar = (bool) ($datos['desactivar_faltantes'] ?? false);
        $this->limpiarVencidos();

        // Una sola importación a la vez por empresa (dos revisiones distintas chocarían en el índice único);
        // el token además se consume una sola vez más abajo. Con el candado ocupado el token no se gasta.
        $candado = Cache::lock('crm_import_catalogo_empresa_'.$empresaId, 300);
        if (! $candado->get()) {
            return $this->rechazar('Ya hay una importación del catálogo en curso en esta empresa; inténtalo en un momento.', 409);
        }
        try {
            return $this->importarConCandado($request, $empresaId, $datos['token'], $elegidas, $desactivar);
        } finally {
            $candado->release();
        }
    }

    private function importarConCandado(Request $request, int $empresaId, string $token, array $elegidas, bool $desactivar): JsonResponse
    {
        $datos = ['token' => $token];
        $guardado = Cache::get($this->llave($datos['token']));
        if (! is_array($guardado)
            || $guardado['user_id'] !== $request->user()->id
            || $guardado['empresa_id'] !== $empresaId
            || ! Storage::disk(self::DISCO)->exists($guardado['ruta'])) {
            return $this->rechazar(self::VENCIDA, 422);
        }

        try {
            $lectura = (new LectorCatalogo())->leer(Storage::disk(self::DISCO)->path($guardado['ruta']));
        } catch (RuntimeException $e) {
            return $this->rechazar($e->getMessage(), 422);
        }

        $revision = new RevisionCatalogo();
        if (($invalida = $revision->claveElegidaInvalida($lectura, $elegidas)) !== null) {
            return $this->rechazar('La fila elegida para la clave '.$invalida.' no es válida.', 422);
        }

        // Si algo del catálogo cambió desde la revisión, no se aplica: se vuelve a revisar.
        $actual = $revision->revisar($empresaId, $lectura);
        if (! hash_equals($guardado['huella'], $actual['huella'])) {
            if (Cache::pull($this->llave($datos['token'])) === null) {
                return $this->rechazar(self::VENCIDA, 422);
            }
            $nuevo = (string) Str::uuid();
            $rutaNueva = self::CARPETA.'/'.$nuevo.'.xlsx';
            Storage::disk(self::DISCO)->move($guardado['ruta'], $rutaNueva);
            // El archivo vuelve a valer 2 h, igual que el token nuevo (la limpieza mira la fecha del archivo).
            @touch(Storage::disk(self::DISCO)->path($rutaNueva));
            $this->guardarToken($nuevo, $request, $empresaId, $rutaNueva, $actual['huella']);

            return response()->json([
                'success' => false,
                'message' => 'El catálogo cambió desde la revisión; revisa de nuevo.',
                'data' => ['token' => $nuevo, 'resumen' => $actual],
            ], 409);
        }

        // Actualizar productos que ya existen o desactivar faltantes exige además «editar».
        $plan = $revision->planificar($empresaId, $lectura, $elegidas);
        if ($plan['resumen']['actualizados'] > 0 || $desactivar) {
            $this->exigirPermisoSubmodulo(
                $empresaId, 'catalogos', 'productos', 'editar',
                'No tienes permiso para editar productos del catálogo.',
            );
        }

        // Bajo el candado, el token se consume aquí: un segundo envío ya no lo encuentra.
        if (Cache::pull($this->llave($datos['token'])) === null) {
            return $this->rechazar(self::VENCIDA, 422);
        }
        try {
            $aplicado = (new ImportadorCatalogo($revision))->importar($empresaId, $lectura, $elegidas, $desactivar);
        } catch (Throwable $e) {
            $this->guardarToken($datos['token'], $request, $empresaId, $guardado['ruta'], $guardado['huella']);
            throw $e;
        }

        Storage::disk(self::DISCO)->delete($guardado['ruta']);

        return $this->jsonSuccess($aplicado, 'Catálogo importado correctamente');
    }

    /** Borra los archivos guardados hace más de 2 h (el token de caché vence solo). */
    private function limpiarVencidos(): void
    {
        $disco = Storage::disk(self::DISCO);
        $limite = now()->subHours(self::VIGENCIA_HORAS)->getTimestamp();
        foreach ($disco->files(self::CARPETA) as $ruta) {
            if ($disco->lastModified($ruta) < $limite) {
                $disco->delete($ruta);
            }
        }
    }

    /** Empresa del contexto, con permiso de crear y con el catálogo por líneas encendido. */
    private function preparar(Request $request): int
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo(
            $empresaId, 'catalogos', 'productos', 'crear',
            'No tienes permiso para importar productos.',
        );
        abort_unless(CrmConfiguracionComercial::catalogoPorLineas((int) $empresaId), 422, 'Esta empresa no usa el catálogo por líneas.');

        return (int) $empresaId;
    }

    private function llave(string $token): string
    {
        return 'crm_import_catalogo_'.$token;
    }

    private function guardarToken(string $token, Request $request, int $empresaId, string $ruta, string $huella): void
    {
        Cache::put($this->llave($token), [
            'user_id' => $request->user()->id,
            'empresa_id' => $empresaId,
            'ruta' => $ruta,
            'huella' => $huella,
        ], now()->addHours(self::VIGENCIA_HORAS));
    }

    private function rechazar(string $mensaje, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $mensaje], $status);
    }
}
