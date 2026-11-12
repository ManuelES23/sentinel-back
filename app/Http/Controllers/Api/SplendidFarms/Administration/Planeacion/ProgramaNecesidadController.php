<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Necesidades de ventas de la semana: un renglón por cliente, bodega y
 * presentación, con las cajas de cada día y una o más variedades aceptadas
 * del mismo grupo de balance.
 *
 * La captura renglón por renglón vive en `HojaNecesidadesController` (guardado
 * en lote); aquí queda copiar la semana anterior y las reglas de un renglón
 * que la Hoja reutiliza. Exige `necesidades-venta.capturar` y avisa en tiempo real.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class ProgramaNecesidadController extends Controller
{
    use PermisoPlaneacion;

    public const MAX_CAJAS = 999999;

    /**
     * Reglas de validación de un renglón completo. `$prefijo` es la ruta del
     * renglón dentro del cuerpo con su punto final (`'nuevas.*.'`, o `''` si el
     * renglón es el cuerpo mismo).
     *
     * El color se manda como `variedad_ids` o como `grupo_id` (un grupo de
     * balance: su id, o «v{id}» si la variedad no tiene grupo), nunca los dos.
     * Esto solo revisa forma y catálogos activos; las reglas de negocio
     * (cultivo, grupo, temporada) las aplica quien guarda.
     *
     * @return array<string, list<mixed>>
     */
    public static function reglasRenglon(string $prefijo): array
    {
        $p = $prefijo;

        return [
            "{$p}bodega_id" => ['required', 'integer', Rule::exists('plan_bodegas_destino', 'id')->where('is_active', true)],
            "{$p}cliente_id" => ['nullable', 'integer'],
            "{$p}cliente_nombre" => ['nullable', "required_without:{$p}cliente_id", 'string', 'max:150'],
            "{$p}presentacion_id" => ['required', 'integer', Rule::exists('plan_presentaciones', 'id')->where('is_active', true)],
            "{$p}tipo_empaque_id" => ['nullable', 'integer', Rule::exists('plan_tipos_empaque', 'id')->where('is_active', true)],
            // Sin `distinct`: con comodines compara contra las variedades de todos los renglones.
            "{$p}variedad_ids" => ['nullable', "required_without:{$p}grupo_id", "prohibits:{$p}grupo_id", 'array', 'min:1', 'max:50', function ($atributo, $valor, $falla) {
                if (is_array($valor) && count(array_unique($valor)) !== count($valor)) {
                    $falla('Hay variedades repetidas.');
                }
            }],
            "{$p}variedad_ids.*" => ['required', 'integer'],
            "{$p}grupo_id" => ['nullable', "required_without:{$p}variedad_ids", 'regex:/^v?\d{1,10}$/'],
            "{$p}cajas" => ['required', 'array', 'size:6'],
            "{$p}cajas.*" => ['required', 'integer', 'min:0', 'max:'.self::MAX_CAJAS],
        ];
    }

    /**
     * Copia los renglones de la semana anterior (la que empieza 7 días antes)
     * con sus cajas y variedades. Solo si esta semana aún no tiene renglones
     * y si cada cultivo copiado tiene una temporada abierta en esta semana.
     */
    public function copiarAnterior(ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('necesidades-venta', 'capturar');

        $copiadas = DB::transaction(function () use ($programa, $servicio, $registro) {
            // Se bloquea el programa para que dos copias a la vez no dupliquen renglones.
            ProgramaSemanal::whereKey($programa->id)->lockForUpdate()->value('id');

            if (ProgramaNecesidad::where('programa_id', $programa->id)->exists()) {
                throw ValidationException::withMessages(['semana' => 'Esta semana ya tiene necesidades']);
            }

            $lunesAnterior = $programa->lunes->copy()->subDays(7)->toDateString();
            $anterior = ProgramaSemanal::whereDate('lunes', $lunesAnterior)->first();
            $origen = $anterior
                ? ProgramaNecesidad::with('variedades:id')->where('programa_id', $anterior->id)->orderBy('id')->get()
                : collect();
            if ($origen->isEmpty()) {
                throw ValidationException::withMessages(['semana' => 'La semana anterior no tiene necesidades']);
            }
            foreach ($origen->pluck('cultivo_id')->unique() as $cultivoId) {
                $this->exigirTemporadaAbierta($programa, (int) $cultivoId, $servicio);
            }

            foreach ($origen as $o) {
                $copia = ProgramaNecesidad::create([
                    'programa_id' => $programa->id,
                    'bodega_id' => $o->bodega_id,
                    'cliente_id' => $o->cliente_id,
                    'cliente_nombre' => $o->cliente_nombre,
                    'cultivo_id' => $o->cultivo_id,
                    'presentacion_id' => $o->presentacion_id,
                    'tipo_empaque_id' => $o->tipo_empaque_id,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ] + $this->atributosCajas($o->cajasPorDia()));
                $copia->variedades()->sync($o->variedades->pluck('id')->all());
                $registro->emitir($programa);
            }

            return $origen->count();
        });

        return response()->json([
            'success' => true,
            'message' => "Se copiaron {$copiadas} necesidades de la semana anterior.",
            'data' => ['copiadas' => $copiadas],
        ]);
    }

    /**
     * Cada cultivo copiado debe tener una temporada abierta que toque la
     * semana: una necesidad en una semana sin producción arrastraría su
     * negativo al inventario inicial de las siguientes.
     */
    private function exigirTemporadaAbierta(ProgramaSemanal $programa, int $cultivoId, ProgramaSemanalService $servicio): void
    {
        if (! $servicio->temporadasDeSemana($programa)->contains(fn ($t) => (int) $t->cultivo_id === $cultivoId)) {
            throw ValidationException::withMessages(['semana' => 'El cultivo no tiene una temporada abierta en esta semana.']);
        }
    }

    /**
     * @param  array<int, int|string>  $cajas  Seis cantidades, de lunes a sábado.
     * @return array<string, int>
     */
    private function atributosCajas(array $cajas): array
    {
        $salida = [];
        foreach (array_values($cajas) as $dia => $valor) {
            $salida["cajas_{$dia}"] = (int) $valor;
        }

        return $salida;
    }
}
