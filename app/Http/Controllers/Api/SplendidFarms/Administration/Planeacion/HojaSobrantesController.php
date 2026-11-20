<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use App\Services\Planeacion\CalculadoraBalance;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use App\Services\Planeacion\ValidadorRenglonPlaneacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Hoja de sobrantes del Programa de empaque: guarda en una sola petición
 * atómica los renglones nuevos, los cambios y los borrados. Exige
 * `programa-empaque.capturar`.
 *
 * Mismo contrato que la hoja de necesidades, sin cliente y con tipo de
 * empaque obligatorio. Un sobrante siempre lleva color (variedades o grupo de
 * balance): sin él no llegaría al balance. Asignar más de lo disponible no es
 * un error: se guarda y el grupo queda en `falta`. Subir cajas exige una
 * temporada abierta del cultivo; bajar y borrar, no.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class HojaSobrantesController extends Controller
{
    use PermisoPlaneacion;

    public function guardar(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('programa-empaque', 'capturar');

        $datos = $request->validate($this->reglas());

        $nuevas = array_values($datos['nuevas'] ?? []);
        $cambios = array_values($datos['cambios'] ?? []);
        $borradas = array_values(array_map('intval', $datos['borradas'] ?? []));

        $idsCambios = array_map(fn ($c) => (int) $c['id'], $cambios);
        foreach ($borradas as $i => $id) {
            if (in_array($id, $idsCambios, true)) {
                throw ValidationException::withMessages(["borradas.$i" => 'El renglón viene a la vez como cambio y como borrado.']);
            }
        }

        $claves = [];
        DB::transaction(function () use ($programa, $nuevas, $cambios, $borradas, $idsCambios, $servicio, $registro, &$claves) {
            // El bloqueo DEBE ser lo primero de la transacción (MySQL en
            // REPEATABLE READ toma la «foto» en la primera lectura sin
            // bloqueo). Primero la fila de la semana, como en Embarques: con
            // la semana sin sobrantes no hay renglones que bloquear y dos
            // capturas a la vez se interbloquearían por los huecos del índice.
            // Luego los renglones que se cambian o borran, por id ascendente.
            ProgramaSemanal::whereKey($programa->id)->lockForUpdate()->value('id');

            $ids = array_values(array_unique([...$idsCambios, ...$borradas]));
            sort($ids);
            $existentes = $ids === []
                ? collect()
                : ProgramaSobrante::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $validador = new ValidadorRenglonPlaneacion($servicio);
            $presentaciones = $validador->presentaciones([...$nuevas, ...$cambios]);

            $aBorrar = $this->planearBorradas($programa, $borradas, $existentes);
            $planCambios = $this->planearCambios($programa, $cambios, $existentes, $presentaciones, $validador);
            $planNuevas = $this->planearNuevas($programa, $nuevas, $presentaciones, $validador);

            // Todo está validado: desde aquí solo se escribe.
            $hubo = false;
            foreach ($aBorrar as $sobrante) {
                $sobrante->delete();
                $hubo = true;
            }
            foreach ($planCambios as $plan) {
                $hubo = $this->aplicarCambio($plan) || $hubo;
            }
            foreach ($planNuevas as $plan) {
                $claves[$plan['clave']] = $this->crear($programa, $plan);
                $hubo = true;
            }

            if ($hubo) {
                $registro->emitir($programa);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Hoja de sobrantes guardada.',
            'claves' => (object) $claves,
            'data' => $servicio->semana($programa->fresh(), $this->permisosUsuario()),
        ]);
    }

    // ── Reglas de forma ─────────────────────────────────

    /**
     * Reglas de un renglón completo: las de Necesidades sin cliente y con el
     * tipo de empaque obligatorio.
     *
     * @return array<string, list<mixed>>
     */
    private static function reglasRenglon(string $prefijo): array
    {
        $reglas = ProgramaNecesidadController::reglasRenglon($prefijo);
        unset($reglas["{$prefijo}cliente_id"], $reglas["{$prefijo}cliente_nombre"]);

        $empaque = array_values(array_filter(
            $reglas["{$prefijo}tipo_empaque_id"],
            fn ($regla) => $regla !== 'nullable',
        ));
        $reglas["{$prefijo}tipo_empaque_id"] = ['required', ...$empaque];

        return $reglas;
    }

    /** @return array<string, list<mixed>> */
    private function reglas(): array
    {
        // Un cambio lleva solo los campos que cambian: los obligatorios de un
        // renglón completo pasan a «si viene, no vacío», y los catálogos
        // activos se revisan después, solo si el valor cambió.
        $parcial = [];
        foreach (self::reglasRenglon('cambios.*.') as $campo => $reglas) {
            if ($campo === 'cambios.*.cajas' || $campo === 'cambios.*.cajas.*') {
                continue;
            }
            $nuevas = ['sometimes'];
            foreach ($reglas as $regla) {
                if ($regla instanceof Exists || (is_string($regla) && str_starts_with($regla, 'required_without:'))) {
                    continue;
                }
                $nuevas[] = $regla === 'required' ? 'filled' : $regla;
            }
            $parcial[$campo] = $nuevas;
        }

        $max = ProgramaNecesidadController::MAX_CAJAS;
        $diasValidos = array_map('strval', range(0, CalculadoraBalance::DIAS - 1));

        return [
            'nuevas' => ['sometimes', 'array', 'max:300'],
            'nuevas.*' => ['array'],
            'nuevas.*.clave' => ['required', 'string', 'max:64', 'distinct'],
        ] + self::reglasRenglon('nuevas.*.') + [
            'cambios' => ['sometimes', 'array', 'max:600'],
            'cambios.*' => ['array'],
            'cambios.*.id' => ['required', 'integer', 'distinct'],
        ] + $parcial + [
            'cambios.*.cajas' => ['sometimes', 'array', 'min:1', 'max:'.CalculadoraBalance::DIAS, function ($atributo, $valor, $falla) use ($diasValidos) {
                foreach (array_keys((array) $valor) as $dia) {
                    if (! in_array((string) $dia, $diasValidos, true)) {
                        $falla('Los días de las cajas van de 0 (lunes) a 6 (domingo).');

                        return;
                    }
                }
            }],
            'cambios.*.cajas.*' => ['required', 'integer', 'min:0', "max:$max"],
            'borradas' => ['sometimes', 'array', 'max:600'],
            'borradas.*' => ['integer', 'distinct'],
        ];
    }

    // ── Planeación (valida todo, no escribe) ────────────

    /**
     * Borrar siempre se puede: un renglón que ya no existe se da por borrado y
     * solo se rechaza el que es de otra semana.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, ProgramaSobrante>  $existentes
     * @return list<ProgramaSobrante>
     */
    private function planearBorradas(ProgramaSemanal $programa, array $ids, Collection $existentes): array
    {
        $salida = [];
        foreach ($ids as $i => $id) {
            $sobrante = $existentes->get($id);
            if ($sobrante === null) {
                continue;
            }
            if ((int) $sobrante->programa_id !== (int) $programa->id) {
                throw ValidationException::withMessages(["borradas.$i" => 'El renglón no es de esta semana.']);
            }
            $salida[] = $sobrante;
        }

        return $salida;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  Collection<int, Presentacion>  $presentaciones
     * @return list<array{clave: string, atributos: array<string, mixed>, variedades: list<int>}>
     */
    private function planearNuevas(ProgramaSemanal $programa, array $filas, Collection $presentaciones, ValidadorRenglonPlaneacion $validador): array
    {
        $salida = [];
        foreach ($filas as $i => $fila) {
            $ruta = "nuevas.$i";

            $presentacion = $validador->presentacionActiva($presentaciones, (int) $fila['presentacion_id'], "$ruta.presentacion_id");
            $cultivoId = (int) $presentacion->cultivo_id;
            $this->exigirPresentacionDelColor($fila, $cultivoId, $validador, "$ruta.presentacion_id");

            $variedades = $validador->resolverVariedades($fila, $cultivoId, $ruta);
            $validador->exigirTemporadaAbierta($programa, $cultivoId, "$ruta.presentacion_id");

            $atributos = [
                'bodega_id' => (int) $fila['bodega_id'],
                'cultivo_id' => $cultivoId,
                'presentacion_id' => (int) $fila['presentacion_id'],
                'tipo_empaque_id' => (int) $fila['tipo_empaque_id'],
            ];
            foreach (array_values($fila['cajas']) as $dia => $valor) {
                $atributos["cajas_{$dia}"] = (int) $valor;
            }

            $salida[] = ['clave' => (string) $fila['clave'], 'atributos' => $atributos, 'variedades' => $variedades];
        }

        return $salida;
    }

    /**
     * Un cambio solo valida lo que de verdad modifica: bajar cajas o cambiar
     * de bodega o empaque no dependen de la temporada.
     *
     * @param  list<array<string, mixed>>  $filas
     * @param  Collection<int, ProgramaSobrante>  $existentes
     * @param  Collection<int, Presentacion>  $presentaciones
     * @return list<array{sobrante: ProgramaSobrante, atributos: array<string, mixed>, variedades: ?list<int>}>
     */
    private function planearCambios(ProgramaSemanal $programa, array $filas, Collection $existentes, Collection $presentaciones, ValidadorRenglonPlaneacion $validador): array
    {
        $salida = [];
        foreach ($filas as $i => $fila) {
            $ruta = "cambios.$i";
            $actual = $existentes->get((int) $fila['id']);
            if ($actual === null || (int) $actual->programa_id !== (int) $programa->id) {
                throw ValidationException::withMessages(["$ruta.id" => 'El renglón no existe en esta semana.']);
            }

            $atributos = [];

            if (isset($fila['bodega_id']) && (int) $fila['bodega_id'] !== (int) $actual->bodega_id) {
                $validador->exigirBodegaActiva((int) $fila['bodega_id'], "$ruta.bodega_id");
                $atributos['bodega_id'] = (int) $fila['bodega_id'];
            }

            if (isset($fila['tipo_empaque_id']) && (int) $fila['tipo_empaque_id'] !== (int) $actual->tipo_empaque_id) {
                $validador->exigirTipoEmpaqueActivo((int) $fila['tipo_empaque_id'], "$ruta.tipo_empaque_id");
                $atributos['tipo_empaque_id'] = (int) $fila['tipo_empaque_id'];
            }

            $cultivoId = (int) $actual->cultivo_id;
            $cultivoCambio = false;
            if (isset($fila['presentacion_id']) && (int) $fila['presentacion_id'] !== (int) $actual->presentacion_id) {
                $presentacion = $validador->presentacionActiva($presentaciones, (int) $fila['presentacion_id'], "$ruta.presentacion_id");
                $atributos['presentacion_id'] = (int) $fila['presentacion_id'];
                $cultivoCambio = (int) $presentacion->cultivo_id !== $cultivoId;
                $cultivoId = (int) $presentacion->cultivo_id;
                $atributos['cultivo_id'] = $cultivoId;
            }

            // Las variedades se revisan si vienen o si cambió el cultivo (las
            // actuales ya no cuadrarían con la presentación nueva).
            $variedades = null;
            $mandaColor = ! empty($fila['variedad_ids']) || isset($fila['grupo_id']);
            if ($mandaColor || $cultivoCambio) {
                $actuales = DB::table('programa_sobrante_variedad')->where('sobrante_id', $actual->id)->pluck('variedad_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
                if ($mandaColor) {
                    $this->exigirPresentacionDelColor($fila, $cultivoId, $validador, "$ruta.presentacion_id");
                }
                $variedades = $mandaColor
                    ? $validador->resolverVariedades($fila, $cultivoId, $ruta)
                    : $validador->exigirMismoGrupo($actuales, $cultivoId, "$ruta.presentacion_id");
                if ($variedades === $actuales) {
                    $variedades = null;
                }
            }

            $subeCajas = false;
            if (isset($fila['cajas'])) {
                foreach ($fila['cajas'] as $dia => $valor) {
                    $valor = (int) $valor;
                    $subeCajas = $subeCajas || $valor > (int) $actual->{"cajas_{$dia}"};
                    $atributos["cajas_{$dia}"] = $valor;
                }
            }

            // Subir cajas (o pasar el renglón a otro cultivo) en una semana sin
            // temporada del cultivo arrastraría su negativo al inventario de las
            // siguientes. Bajar o quitar, no.
            if ($cultivoCambio) {
                $validador->exigirTemporadaAbierta($programa, $cultivoId, "$ruta.presentacion_id");
            } elseif ($subeCajas) {
                $validador->exigirTemporadaAbierta($programa, $cultivoId, "$ruta.cajas");
            }

            $salida[] = ['sobrante' => $actual, 'atributos' => $atributos, 'variedades' => $variedades];
        }

        return $salida;
    }

    /**
     * Un color de un cultivo con una presentación de otro se marca en la
     * presentación: el cultivo del renglón sale de ella. Si no se sabe el
     * cultivo del color (variedad o grupo inexistente), lo marca después la
     * revisión del color.
     */
    private function exigirPresentacionDelColor(array $fila, int $cultivoId, ValidadorRenglonPlaneacion $validador, string $campo): void
    {
        $delColor = $validador->cultivoDelColor($fila);
        if ($delColor !== null && $delColor !== $cultivoId) {
            throw ValidationException::withMessages([$campo => 'La presentación no es del cultivo del color elegido.']);
        }
    }

    // ── Escritura ───────────────────────────────────────

    /**
     * @param  array{atributos: array<string, mixed>, variedades: list<int>}  $plan
     * @return int id del renglón creado
     */
    private function crear(ProgramaSemanal $programa, array $plan): int
    {
        $sobrante = ProgramaSobrante::create($plan['atributos'] + [
            'programa_id' => $programa->id,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);
        $sobrante->variedades()->sync($plan['variedades']);

        return $sobrante->id;
    }

    /**
     * Sin cambio de valor no hay escritura ni aviso.
     *
     * @param  array{sobrante: ProgramaSobrante, atributos: array<string, mixed>, variedades: ?list<int>}  $plan
     */
    private function aplicarCambio(array $plan): bool
    {
        $sobrante = $plan['sobrante'];
        $hubo = false;

        $sobrante->fill($plan['atributos']);
        if ($sobrante->isDirty()) {
            $sobrante->updated_by = Auth::id();
            $sobrante->save();
            $hubo = true;
        }
        if ($plan['variedades'] !== null) {
            $sobrante->variedades()->sync($plan['variedades']);
            $hubo = true;
        }

        return $hubo;
    }
}
