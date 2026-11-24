<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\DomingoPrograma;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Interruptores del domingo de la semana, uno por proceso. Exige
 * `tablero-semanal.editar-notas`.
 *
 * Cuerpo: `{ corte?, empaque?, embarques? }` (booleanos, al menos uno) o, por
 * compatibilidad con el front anterior, `{ trabaja_domingo }`, que fija los
 * tres (no se mezclan las dos formas). Apagar un proceso con datos en SU
 * domingo (corte: cortes con hectáreas; empaque: necesidades o sobrantes con
 * cajas; embarques: embarques con cargas) responde 422 con un mensaje por
 * proceso (en la clave del proceso, o en `trabaja_domingo` con la forma
 * vieja) y no cambia nada. Encender no tiene restricción. Sin cambio de valor
 * es un 200 sin escritura ni aviso; con cambio, un solo `emitir()`.
 * `trabaja_domingo` de la tabla se mantiene como «alguno de los tres».
 */
class DomingoProgramaController extends Controller
{
    use PermisoPlaneacion;

    public function guardar(Request $request, ProgramaSemanal $programa, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('tablero-semanal', 'editar-notas');

        $reglas = ['trabaja_domingo' => ['sometimes', 'boolean', 'prohibits:'.implode(',', ProgramaSemanal::PROCESOS)]];
        foreach (ProgramaSemanal::PROCESOS as $proceso) {
            $reglas[$proceso] = ['sometimes', 'boolean'];
        }
        $datos = $request->validate($reglas, [
            'trabaja_domingo.prohibits' => 'Manda trabaja_domingo o los interruptores por proceso, no ambos.',
        ]);

        $legado = array_key_exists('trabaja_domingo', $datos);
        $pedidos = [];
        foreach (ProgramaSemanal::PROCESOS as $proceso) {
            if ($legado) {
                $pedidos[$proceso] = (bool) $datos['trabaja_domingo'];
            } elseif (array_key_exists($proceso, $datos)) {
                $pedidos[$proceso] = (bool) $datos[$proceso];
            }
        }
        if ($pedidos === []) {
            throw ValidationException::withMessages([
                'domingo' => 'Indica qué proceso cambia: corte, empaque o embarques.',
            ]);
        }

        $fila = DB::transaction(function () use ($programa, $pedidos, $legado, $registro) {
            // El bloqueo de la fila del programa DEBE ser la primera sentencia
            // de la transacción: MySQL (REPEATABLE READ) toma la «foto» en la
            // primera lectura sin bloqueo. Las hojas releen la bandera de su
            // proceso con esta misma fila bloqueada antes de guardar algo en el
            // domingo, así que una captura simultánea espera a este cambio o
            // este conteo ve lo que esa captura ya confirmó. (SQLite en pruebas
            // no puede ejercitarlo.) Se escribe sobre la fila leída con el
            // bloqueo y no sobre el modelo ligado por la ruta: ese se cargó
            // antes y puede estar viejo.
            $fila = ProgramaSemanal::whereKey($programa->id)->lockForUpdate()->firstOrFail();

            $cambios = array_filter(
                $pedidos,
                fn (bool $valor, string $proceso) => $fila->trabajaDomingo($proceso) !== $valor,
                ARRAY_FILTER_USE_BOTH
            );
            // Sin cambio de valor no hay escritura ni aviso.
            if ($cambios === []) {
                return $fila;
            }

            $errores = [];
            foreach ($cambios as $proceso => $valor) {
                if ($valor) {
                    continue;
                }
                $partes = DomingoPrograma::datosDelDomingo($fila->id, $proceso);
                if ($partes !== []) {
                    $errores[$legado ? 'trabaja_domingo' : $proceso][] = DomingoPrograma::mensajeDeApagado($proceso, $partes);
                }
            }
            if ($errores !== []) {
                throw ValidationException::withMessages($errores);
            }

            foreach ($cambios as $proceso => $valor) {
                $fila->{ProgramaSemanal::columnaDomingo($proceso)} = $valor;
            }
            $fila->trabaja_domingo = $fila->trabajaAlgunDomingo();
            $fila->save();
            $registro->emitir($fila);

            return $fila;
        });

        $domingo = $fila->domingoPorProceso();

        return response()->json([
            'success' => true,
            'message' => $this->mensaje($domingo),
            'data' => [
                'trabaja_domingo' => $fila->trabajaAlgunDomingo(),
                'domingo' => $domingo,
            ],
        ]);
    }

    /** @param  array<string, bool>  $domingo */
    private function mensaje(array $domingo): string
    {
        $valores = array_unique(array_values($domingo));
        if (count($valores) === 1) {
            return $valores[0] ? 'La semana ahora se trabaja el domingo.' : 'La semana ahora no se trabaja el domingo.';
        }

        $partes = [];
        foreach ($domingo as $proceso => $trabaja) {
            $partes[] = DomingoPrograma::NOMBRES[$proceso].' '.($trabaja ? 'sí' : 'no');
        }

        return 'Domingo: '.implode(', ', $partes).'.';
    }
}
