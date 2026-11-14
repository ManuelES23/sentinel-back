<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\TipoEmpaque;
use App\Services\Planeacion\ClientesCrmSbp;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Hoja de necesidades de la semana: guarda en una sola petición atómica los
 * renglones nuevos, los cambios y los borrados. Exige `necesidades-venta.capturar`.
 *
 * Un cambio solo toca lo que trae: `cajas` es un mapa día → cajas y los demás
 * días se quedan como estaban. Con un error en cualquier renglón no se guarda
 * ninguno. Borrar y bajar cajas nunca dependen del estado de los catálogos
 * (temporada cerrada, presentación o bodega desactivadas): solo se validan las
 * reglas de catálogo en los renglones nuevos y en los campos que un cambio modifica.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class HojaNecesidadesController extends Controller
{
    use PermisoPlaneacion;

    private const MENSAJE_GRUPO = 'Las variedades deben ser del mismo grupo de balance';

    private const MENSAJE_TEMPORADA = 'El cultivo no tiene una temporada abierta en esta semana.';

    public function guardar(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('necesidades-venta', 'capturar');

        $this->limpiarNombres($request);
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
            // El bloqueo de los renglones que se cambian o borran DEBE ser la
            // primera sentencia de la transacción. MySQL corre en REPEATABLE
            // READ: la «foto» se toma en la primera lectura sin bloqueo, así que
            // si cualquier otra lectura (catálogos, temporadas, variedades) va
            // antes, quien espera el bloqueo obtiene el renglón pero sigue viendo
            // la foto vieja: no ve lo que la otra captura acaba de confirmar y
            // pisa sus cajas. (SQLite en pruebas no puede ejercitarlo.)
            $ids = array_values(array_unique([...$idsCambios, ...$borradas]));
            sort($ids);
            $existentes = $ids === []
                ? collect()
                : ProgramaNecesidad::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $clientes = new ClientesCrmSbp;
            $presentaciones = Presentacion::whereIn('id', $this->idsPresentacion($nuevas, $cambios))
                ->get(['id', 'cultivo_id', 'is_active'])
                ->keyBy('id');

            $aBorrar = $this->planearBorradas($programa, $borradas, $existentes);
            $planCambios = $this->planearCambios($programa, $cambios, $existentes, $presentaciones, $clientes, $servicio);
            $planNuevas = $this->planearNuevas($programa, $nuevas, $presentaciones, $clientes, $servicio);

            // Todo está validado: desde aquí solo se escribe.
            $hubo = false;
            foreach ($aBorrar as $necesidad) {
                $necesidad->delete();
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
            'message' => 'Hoja de necesidades guardada.',
            'claves' => (object) $claves,
            'data' => $servicio->semana($programa->fresh(), $this->permisosUsuario()),
        ]);
    }

    // ── Reglas de forma ─────────────────────────────────

    /** @return array<string, list<mixed>> */
    private function reglas(): array
    {
        // Un cambio lleva solo los campos que cambian: los obligatorios de un
        // renglón completo pasan a «si viene, no vacío», y los catálogos
        // activos se revisan después, solo si el valor cambió.
        $parcial = [];
        foreach (ProgramaNecesidadController::reglasRenglon('cambios.*.') as $campo => $reglas) {
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

        return [
            'nuevas' => ['sometimes', 'array', 'max:300'],
            'nuevas.*' => ['array'],
            'nuevas.*.clave' => ['required', 'string', 'max:64', 'distinct'],
        ] + ProgramaNecesidadController::reglasRenglon('nuevas.*.') + [
            'cambios' => ['sometimes', 'array', 'max:600'],
            'cambios.*' => ['array'],
            'cambios.*.id' => ['required', 'integer', 'distinct'],
        ] + $parcial + [
            'cambios.*.cajas' => ['sometimes', 'array', 'min:1', 'max:6', function ($atributo, $valor, $falla) {
                foreach (array_keys((array) $valor) as $dia) {
                    if (! in_array((string) $dia, ['0', '1', '2', '3', '4', '5'], true)) {
                        $falla('Los días de las cajas van de 0 (lunes) a 5 (sábado).');

                        return;
                    }
                }
            }],
            'cambios.*.cajas.*' => ['required', 'integer', 'min:0', "max:$max"],
            'borradas' => ['sometimes', 'array', 'max:600'],
            'borradas.*' => ['integer', 'distinct'],
        ];
    }

    /** El nombre del cliente se guarda sin espacios sobrantes; vacío es ausente. */
    private function limpiarNombres(Request $request): void
    {
        foreach (['nuevas', 'cambios'] as $coleccion) {
            $filas = $request->input($coleccion);
            if (! is_array($filas)) {
                continue;
            }
            foreach ($filas as $i => $fila) {
                if (is_array($fila) && isset($fila['cliente_nombre']) && is_string($fila['cliente_nombre'])) {
                    $nombre = trim($fila['cliente_nombre']);
                    $filas[$i]['cliente_nombre'] = $nombre === '' ? null : $nombre;
                }
            }
            $request->merge([$coleccion => $filas]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nuevas
     * @param  list<array<string, mixed>>  $cambios
     * @return list<int>
     */
    private function idsPresentacion(array $nuevas, array $cambios): array
    {
        $ids = [];
        foreach ([...$nuevas, ...$cambios] as $fila) {
            if (isset($fila['presentacion_id'])) {
                $ids[(int) $fila['presentacion_id']] = true;
            }
        }

        return array_keys($ids);
    }

    // ── Planeación (valida todo, no escribe) ────────────

    /**
     * Borrar siempre se puede: un renglón que ya no existe se da por borrado y
     * solo se rechaza el que es de otra semana.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, ProgramaNecesidad>  $existentes
     * @return list<ProgramaNecesidad>
     */
    private function planearBorradas(ProgramaSemanal $programa, array $ids, Collection $existentes): array
    {
        $salida = [];
        foreach ($ids as $i => $id) {
            $necesidad = $existentes->get($id);
            if ($necesidad === null) {
                continue;
            }
            if ((int) $necesidad->programa_id !== (int) $programa->id) {
                throw ValidationException::withMessages(["borradas.$i" => 'El renglón no es de esta semana.']);
            }
            $salida[] = $necesidad;
        }

        return $salida;
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  Collection<int, Presentacion>  $presentaciones
     * @return list<array{clave: string, atributos: array<string, mixed>, variedades: list<int>}>
     */
    private function planearNuevas(ProgramaSemanal $programa, array $filas, Collection $presentaciones, ClientesCrmSbp $clientes, ProgramaSemanalService $servicio): array
    {
        $salida = [];
        foreach ($filas as $i => $fila) {
            $ruta = "nuevas.$i";

            $presentacion = $presentaciones->get((int) $fila['presentacion_id']);
            if (! $presentacion || ! $presentacion->is_active) {
                throw ValidationException::withMessages(["$ruta.presentacion_id" => 'La presentación no existe o está desactivada.']);
            }
            $cultivoId = (int) $presentacion->cultivo_id;

            $variedades = $this->resolverVariedades($fila, $cultivoId, $servicio, $ruta);
            $this->exigirTemporadaAbierta($programa, $cultivoId, $servicio, "$ruta.presentacion_id");
            [$clienteId, $nombre] = $this->resolverCliente($fila, $clientes, $ruta);

            $salida[] = [
                'clave' => (string) $fila['clave'],
                'atributos' => [
                    'bodega_id' => (int) $fila['bodega_id'],
                    'cliente_id' => $clienteId,
                    'cliente_nombre' => $nombre,
                    'cultivo_id' => $cultivoId,
                    'presentacion_id' => (int) $fila['presentacion_id'],
                    'tipo_empaque_id' => isset($fila['tipo_empaque_id']) ? (int) $fila['tipo_empaque_id'] : null,
                ] + $this->atributosCajas(array_map('intval', array_values($fila['cajas']))),
                'variedades' => $variedades,
            ];
        }

        return $salida;
    }

    /**
     * Un cambio solo valida lo que de verdad modifica: bajar cajas, cambiar
     * de cliente o de bodega, etc., no dependen de la temporada.
     *
     * @param  list<array<string, mixed>>  $filas
     * @param  Collection<int, ProgramaNecesidad>  $existentes
     * @param  Collection<int, Presentacion>  $presentaciones
     * @return list<array{necesidad: ProgramaNecesidad, atributos: array<string, mixed>, variedades: ?list<int>}>
     */
    private function planearCambios(ProgramaSemanal $programa, array $filas, Collection $existentes, Collection $presentaciones, ClientesCrmSbp $clientes, ProgramaSemanalService $servicio): array
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
                if (! BodegaDestino::whereKey((int) $fila['bodega_id'])->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(["$ruta.bodega_id" => 'La bodega no existe o está desactivada.']);
                }
                $atributos['bodega_id'] = (int) $fila['bodega_id'];
            }

            if (array_key_exists('tipo_empaque_id', $fila)) {
                $empaque = $fila['tipo_empaque_id'] !== null ? (int) $fila['tipo_empaque_id'] : null;
                if ($empaque !== ($actual->tipo_empaque_id !== null ? (int) $actual->tipo_empaque_id : null)) {
                    if ($empaque !== null && ! TipoEmpaque::whereKey($empaque)->where('is_active', true)->exists()) {
                        throw ValidationException::withMessages(["$ruta.tipo_empaque_id" => 'El tipo de empaque no existe o está desactivado.']);
                    }
                    $atributos['tipo_empaque_id'] = $empaque;
                }
            }

            $cultivoId = (int) $actual->cultivo_id;
            $cultivoCambio = false;
            if (isset($fila['presentacion_id']) && (int) $fila['presentacion_id'] !== (int) $actual->presentacion_id) {
                $presentacion = $presentaciones->get((int) $fila['presentacion_id']);
                if (! $presentacion || ! $presentacion->is_active) {
                    throw ValidationException::withMessages(["$ruta.presentacion_id" => 'La presentación no existe o está desactivada.']);
                }
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
                $actuales = DB::table('programa_necesidad_variedad')->where('necesidad_id', $actual->id)->pluck('variedad_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
                $variedades = $mandaColor
                    ? $this->resolverVariedades($fila, $cultivoId, $servicio, $ruta)
                    : $this->exigirMismoGrupo($actuales, $cultivoId, $servicio, "$ruta.presentacion_id");
                if ($variedades === $actuales) {
                    $variedades = null;
                }
            }

            if ($this->mandaCliente($fila)) {
                [$clienteId, $nombre] = $this->resolverCliente($fila, $clientes, $ruta, $actual);
                $atributos['cliente_id'] = $clienteId;
                $atributos['cliente_nombre'] = $nombre;
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
            // siguientes. Bajar, quitar o cambiar solo datos de venta, no.
            if ($cultivoCambio) {
                $this->exigirTemporadaAbierta($programa, $cultivoId, $servicio, "$ruta.presentacion_id");
            } elseif ($subeCajas) {
                $this->exigirTemporadaAbierta($programa, $cultivoId, $servicio, "$ruta.cajas");
            }

            $salida[] = ['necesidad' => $actual, 'atributos' => $atributos, 'variedades' => $variedades];
        }

        return $salida;
    }

    private function exigirTemporadaAbierta(ProgramaSemanal $programa, int $cultivoId, ProgramaSemanalService $servicio, string $campo): void
    {
        if (! $servicio->temporadasDeSemana($programa)->contains(fn ($t) => (int) $t->cultivo_id === $cultivoId)) {
            throw ValidationException::withMessages([$campo => self::MENSAJE_TEMPORADA]);
        }
    }

    private function mandaCliente(array $fila): bool
    {
        return array_key_exists('cliente_id', $fila) || array_key_exists('cliente_nombre', $fila);
    }

    /**
     * Cliente del renglón: por id, el del CRM de Splendid by Porvenir (su
     * nombre manda); si no, el nombre escrito se liga al cliente del CRM que
     * se llame igual sin importar mayúsculas ni acentos, y si no hay, queda
     * como texto libre.
     *
     * @return array{0: ?int, 1: string}
     */
    private function resolverCliente(array $fila, ClientesCrmSbp $clientes, string $ruta, ?ProgramaNecesidad $actual = null): array
    {
        $clienteId = isset($fila['cliente_id']) ? (int) $fila['cliente_id'] : null;
        $nombre = $fila['cliente_nombre'] ?? null;

        if ($clienteId !== null) {
            $deCrm = $clientes->nombreDe($clienteId);
            if ($deCrm === null) {
                throw ValidationException::withMessages(["$ruta.cliente_id" => 'El cliente no es del CRM de Splendid by Porvenir.']);
            }

            return [$clienteId, $deCrm];
        }

        if ($nombre === null) {
            // Solo un cambio puede llegar sin nombre: desligar del CRM conserva el que tenía.
            if ($actual === null || ! array_key_exists('cliente_id', $fila) || array_key_exists('cliente_nombre', $fila)) {
                throw ValidationException::withMessages(["$ruta.cliente_nombre" => 'El cliente es obligatorio.']);
            }

            return [null, (string) $actual->cliente_nombre];
        }

        $coincide = $clientes->buscarPorNombre($nombre);

        return $coincide ? [$coincide['id'], $coincide['nombre']] : [null, $nombre];
    }

    /**
     * Variedades del color de la fila: las que manda, o las de su grupo de
     * balance, comprobadas contra el cultivo y que caigan en un solo grupo.
     *
     * @return list<int>
     */
    private function resolverVariedades(array $fila, int $cultivoId, ProgramaSemanalService $servicio, string $ruta): array
    {
        if (! empty($fila['variedad_ids'])) {
            return $this->exigirMismoGrupo(array_map('intval', $fila['variedad_ids']), $cultivoId, $servicio, "$ruta.variedad_ids");
        }

        $clave = (string) $fila['grupo_id'];
        if (str_starts_with($clave, 'v')) {
            $ids = [(int) substr($clave, 1)];
        } else {
            $grupo = GrupoBalance::with('variedades:id')->find((int) $clave);
            if (! $grupo || (int) $grupo->cultivo_id !== $cultivoId) {
                throw ValidationException::withMessages(["$ruta.grupo_id" => 'El grupo no existe en el cultivo de la presentación.']);
            }
            $ids = $grupo->variedades->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                throw ValidationException::withMessages(["$ruta.grupo_id" => 'El grupo no tiene variedades.']);
            }
        }

        return $this->exigirMismoGrupo($ids, $cultivoId, $servicio, "$ruta.grupo_id");
    }

    /**
     * Todas las variedades deben existir, ser del cultivo de la presentación
     * y caer en el mismo grupo de balance.
     *
     * @param  list<int>  $variedadIds
     * @return list<int> ordenadas
     */
    private function exigirMismoGrupo(array $variedadIds, int $cultivoId, ProgramaSemanalService $servicio, string $campo): array
    {
        $grupos = [];
        foreach ($variedadIds as $variedadId) {
            if ($servicio->cultivoDeVariedad($variedadId) !== $cultivoId) {
                throw ValidationException::withMessages([$campo => self::MENSAJE_GRUPO]);
            }
            $grupos[$servicio->grupoDeVariedad($variedadId)] = true;
        }

        if (count($grupos) !== 1) {
            throw ValidationException::withMessages([$campo => self::MENSAJE_GRUPO]);
        }

        sort($variedadIds);

        return array_values($variedadIds);
    }

    // ── Escritura ───────────────────────────────────────

    /**
     * @param  array{atributos: array<string, mixed>, variedades: list<int>}  $plan
     * @return int id del renglón creado
     */
    private function crear(ProgramaSemanal $programa, array $plan): int
    {
        $necesidad = ProgramaNecesidad::create($plan['atributos'] + [
            'programa_id' => $programa->id,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);
        $necesidad->variedades()->sync($plan['variedades']);

        return $necesidad->id;
    }

    /**
     * Sin cambio de valor no hay escritura ni aviso.
     *
     * @param  array{necesidad: ProgramaNecesidad, atributos: array<string, mixed>, variedades: ?list<int>}  $plan
     */
    private function aplicarCambio(array $plan): bool
    {
        $necesidad = $plan['necesidad'];
        $hubo = false;

        $necesidad->fill($plan['atributos']);
        if ($necesidad->isDirty()) {
            $necesidad->updated_by = Auth::id();
            $necesidad->save();
            $hubo = true;
        }
        if ($plan['variedades'] !== null) {
            $necesidad->variedades()->sync($plan['variedades']);
            $hubo = true;
        }

        return $hubo;
    }

    /**
     * @param  array<int, int>  $cajas  Seis cantidades, de lunes a sábado.
     * @return array<string, int>
     */
    private function atributosCajas(array $cajas): array
    {
        $salida = [];
        foreach ($cajas as $dia => $valor) {
            $salida["cajas_{$dia}"] = (int) $valor;
        }

        return $salida;
    }
}
