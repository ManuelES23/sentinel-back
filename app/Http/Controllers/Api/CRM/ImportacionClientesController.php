<?php

namespace App\Http\Controllers\Api\CRM;

use App\Services\CRM\ImportacionClientes\LectorClientes;
use App\Services\CRM\ImportacionClientes\PlanImportacionClientes;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Importación del Excel de clientes en dos pasos: revisar (no escribe clientes;
 * guarda el archivo y devuelve un token) e importar (con el emparejamiento de
 * vendedores y los dominios internos que eligió el usuario).
 */
class ImportacionClientesController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const CARPETA = 'crm/importaciones-clientes';

    private const VIGENCIA_HORAS = 2;

    private const VENCIDA = 'La revisión venció; vuelve a subir el archivo.';

    public function __construct(
        private readonly LectorClientes $lector,
        private readonly PlanImportacionClientes $plan,
    ) {}

    /** POST /crm/clientes/importar/revisar (multipart `archivo`). */
    public function revisar(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'clientes', 'clientes', 'crear', 'No tienes permiso para importar clientes.');

        $request->validate(
            ['archivo' => 'required|file|mimes:xlsx|max:10240'],
            ['archivo.required' => 'Elige el archivo de Excel.', 'archivo.mimes' => 'El archivo debe ser Excel (.xlsx).', 'archivo.max' => 'El archivo pesa más de 10 MB.'],
        );

        $this->borrarRevisionesViejas();

        $uuid = (string) Str::uuid();
        $ruta = $request->file('archivo')->storeAs(self::CARPETA, $uuid.'.xlsx', 'local');
        if ($ruta === false) {
            return $this->jsonError('No se pudo guardar el archivo; inténtalo de nuevo.', 422);
        }

        try {
            $filas = $this->lector->leer(Storage::disk('local')->path($ruta));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($ruta);

            return $this->jsonError($e->getMessage(), 422);
        }

        $plan = $this->plan->planificar($empresaId, $filas);

        Cache::put($this->llave($uuid), [
            'user_id' => Auth::id(),
            'empresa_id' => $empresaId,
            'ruta' => $ruta,
        ], now()->addHours(self::VIGENCIA_HORAS));

        return $this->jsonSuccess(['token' => $uuid, 'resumen' => $plan['resumen']], 'Archivo revisado');
    }

    /** POST /crm/clientes/importar {token, vendedores: {texto: id|null}, dominios_internos?: string[]} */
    public function importar(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        $this->exigirPermisoSubmodulo($empresaId, 'clientes', 'clientes', 'crear', 'No tienes permiso para importar clientes.');

        $datos = $request->validate([
            'token' => 'required|string|max:64',
            // Lista de pares (no un objeto texto => id): el texto del Excel puede traer
            // puntos y fetchAPI convierte las claves de los objetos a snake_case.
            'vendedores' => 'present|array',
            'vendedores.*.texto' => 'required|string|max:255',
            'vendedores.*.vendedor_id' => [
                'nullable', 'integer',
                Rule::exists('crm_vendedores', 'id')->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
            ],
            'dominios_internos' => 'sometimes|array',
            'dominios_internos.*' => 'string|max:255',
        ], [
            'vendedores.*.vendedor_id.exists' => 'Uno de los vendedores elegidos no pertenece a esta empresa.',
        ]);
        $mapeo = [];
        foreach ($datos['vendedores'] as $par) {
            $mapeo[trim($par['texto'])] = $par['vendedor_id'] ?? null;
        }

        $llave = $this->llave($datos['token']);
        $guardado = Cache::get($llave);
        if (! is_array($guardado)
            || (int) $guardado['user_id'] !== (int) Auth::id()
            || (int) $guardado['empresa_id'] !== (int) $empresaId
            || ! Storage::disk('local')->exists($guardado['ruta'])) {
            return $this->jsonError(self::VENCIDA, 422);
        }

        try {
            $filas = $this->lector->leer(Storage::disk('local')->path($guardado['ruta']));
        } catch (RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        // Una importación de clientes a la vez por empresa: dos al mismo tiempo verían
        // los mismos «nuevos» y los crearían dos veces (la clave no es única en la BD).
        $candado = Cache::lock('crm_import_clientes_empresa_'.$empresaId, 300);
        if (! $candado->get()) {
            return $this->jsonError('Ya hay una importación de clientes en curso en esta empresa; inténtalo en un momento.', 409);
        }

        try {
            $plan = $this->plan->planificar(
                $empresaId,
                $filas,
                $mapeo,
                $datos['dominios_internos'] ?? null,
            );

            if ($plan['resumen']['actualizados'] > 0) {
                $this->exigirPermisoSubmodulo($empresaId, 'clientes', 'clientes', 'editar', 'No tienes permiso para actualizar clientes que ya existen.');
            }
            if ($this->plan->asignaVendedores($plan)) {
                $this->exigirPermisoSubmodulo($empresaId, 'clientes', 'clientes', 'asignar_vendedor', 'No tienes permiso para asignar vendedor a clientes.');
            }

            // El token se gasta solo aquí: un 403 de arriba no lo consume, y con el
            // candado tomado un doble envío encuentra el token ya usado.
            if (Cache::pull($llave) === null) {
                return $this->jsonError(self::VENCIDA, 422);
            }

            $aplicado = $this->plan->aplicar($plan);
        } finally {
            $candado->release();
        }

        Storage::disk('local')->delete($guardado['ruta']);

        return $this->jsonSuccess($aplicado, 'Clientes importados');
    }

    private function llave(string $token): string
    {
        return 'crm_import_clientes_'.$token;
    }

    /** Sin tarea programada: cada revisión borra los archivos de revisiones de más de 2 h. */
    private function borrarRevisionesViejas(): void
    {
        $disco = Storage::disk('local');
        $limite = now()->subHours(self::VIGENCIA_HORAS)->getTimestamp();
        foreach ($disco->files(self::CARPETA) as $archivo) {
            if ($disco->lastModified($archivo) < $limite) {
                $disco->delete($archivo);
            }
        }
    }
}
