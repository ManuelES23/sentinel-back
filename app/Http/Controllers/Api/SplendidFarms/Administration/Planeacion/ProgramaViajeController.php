<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoProgramaSemanal;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaViaje;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Viajes de logística de la semana: un Full lleva dos cajas de tráiler y un
 * Sencillo una sola. Todas las escrituras exigen `capturar-logistica`, quedan
 * en el historial del programa (si ya hay junta) y avisan en tiempo real.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class ProgramaViajeController extends Controller
{
    use PermisoProgramaSemanal;

    private const MAX_CAJAS = 999999;

    private const TIPOS = ['full', 'sencillo'];

    /** Crea un viaje en la semana. */
    public function store(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-logistica');

        $datos = $this->validarViaje($request, $programa, $servicio);

        $viaje = DB::transaction(function () use ($programa, $datos, $registro) {
            $viaje = ProgramaViaje::create($datos + [
                'programa_id' => $programa->id,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            $registro->registrar($programa, 'viaje', $viaje->id, 'creado', null, $this->instantanea($viaje));

            return $viaje;
        });

        return response()->json([
            'success' => true,
            'message' => 'Viaje guardado.',
            'data' => $this->payload($viaje),
        ], 201);
    }

    /** Reemplaza los campos del viaje; el programa es el del viaje. */
    public function update(Request $request, ProgramaViaje $viaje, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-logistica');

        $programa = $viaje->programa()->firstOrFail();
        $datos = $this->validarViaje($request, $programa, $servicio);

        DB::transaction(function () use ($viaje, $programa, $datos, $registro) {
            // Se relee con bloqueo: dos ediciones a la vez no se pisan.
            $actual = ProgramaViaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            $antes = $this->instantanea($actual);

            $actual->fill($datos);
            if ($actual->isDirty()) {
                $actual->updated_by = Auth::id();
                $actual->save();
            }

            // Sin cambio de valor no hay historial ni aviso.
            $despues = $this->instantanea($actual);
            if ($antes != $despues) {
                $registro->registrar($programa, 'viaje', $actual->id, 'actualizado', $antes, $despues);
            }

            $viaje->setRawAttributes($actual->getAttributes(), true);
        });

        return response()->json([
            'success' => true,
            'message' => 'Viaje actualizado.',
            'data' => $this->payload($viaje),
        ]);
    }

    public function destroy(ProgramaViaje $viaje, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-logistica');

        $programa = $viaje->programa()->firstOrFail();

        DB::transaction(function () use ($viaje, $programa, $registro) {
            $antes = $this->instantanea($viaje);
            $viaje->delete();
            $registro->registrar($programa, 'viaje', $viaje->id, 'borrado', $antes, null);
        });

        return response()->json(['success' => true, 'message' => 'Viaje eliminado.']);
    }

    // ── Validación ──────────────────────────────────────

    /**
     * Campos del viaje ya validados. El cultivo debe ser el de una temporada
     * abierta que toque la semana; un Full exige la segunda caja y un
     * Sencillo la guarda nula aunque llegue con valor.
     *
     * @return array{dia: int, bodega_id: int, cultivo_id: int, tipo: string, cajas_caja_1: int, cajas_caja_2: ?int, notas: ?string}
     */
    private function validarViaje(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio): array
    {
        $esFull = $request->input('tipo') === 'full';
        $reglaCajas = ['integer', 'min:0', 'max:'.self::MAX_CAJAS];

        $datos = $request->validate([
            'dia' => ['required', 'integer', 'between:0,5'],
            'bodega_id' => ['required', 'integer', Rule::exists('plan_bodegas_destino', 'id')->where('is_active', true)],
            'cultivo_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'cajas_caja_1' => array_merge(['required'], $reglaCajas),
            // Solo un Full la necesita; en un Sencillo se ignora lo que llegue.
            'cajas_caja_2' => $esFull ? array_merge(['required'], $reglaCajas) : ['nullable'],
            'notas' => ['nullable', 'string', 'max:255'],
        ]);

        $cultivoId = (int) $datos['cultivo_id'];
        if (! $servicio->temporadasDeSemana($programa)->contains(fn ($t) => (int) $t->cultivo_id === $cultivoId)) {
            throw ValidationException::withMessages(['cultivo_id' => 'El cultivo no tiene una temporada abierta en esta semana.']);
        }

        return [
            'dia' => (int) $datos['dia'],
            'bodega_id' => (int) $datos['bodega_id'],
            'cultivo_id' => $cultivoId,
            'tipo' => $datos['tipo'],
            'cajas_caja_1' => (int) $datos['cajas_caja_1'],
            'cajas_caja_2' => $esFull ? (int) $datos['cajas_caja_2'] : null,
            'notas' => $datos['notas'] ?? null,
        ];
    }

    // ── Salidas ─────────────────────────────────────────

    /** Valores del viaje para el historial (mismas llaves antes y después). */
    private function instantanea(ProgramaViaje $v): array
    {
        return [
            'dia' => $v->dia,
            'bodega_id' => $v->bodega_id,
            'cultivo_id' => $v->cultivo_id,
            'tipo' => $v->tipo,
            'cajas_caja_1' => $v->cajas_caja_1,
            'cajas_caja_2' => $v->cajas_caja_2,
            'notas' => $v->notas,
        ];
    }

    private function payload(ProgramaViaje $v): array
    {
        return [
            'id' => $v->id,
            'programa_id' => $v->programa_id,
            'cajas_cajas' => $v->tipo === 'full'
                ? [(int) $v->cajas_caja_1, (int) $v->cajas_caja_2]
                : [(int) $v->cajas_caja_1],
        ] + $this->instantanea($v);
    }
}
