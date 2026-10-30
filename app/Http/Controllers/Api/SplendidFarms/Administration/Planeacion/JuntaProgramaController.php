<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoProgramaSemanal;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\ProgramaInventarioInicial;
use App\Models\Planeacion\ProgramaJunta;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Variedad;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Junta de la semana (foto del programa en ese momento) e inventario inicial
 * manual de un grupo.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class JuntaProgramaController extends Controller
{
    use PermisoProgramaSemanal;

    /**
     * Registra la junta: guarda la foto (la semana completa tal como está) y
     * fija la fecha desde la que se anotan los cambios. Se puede volver a
     * registrar: se conserva cada junta y manda la última.
     */
    public function registrar(ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('acuerdos');

        $permisos = $this->permisosUsuario();

        $semana = DB::transaction(function () use ($programa, $servicio, $permisos) {
            $ahora = now();
            $programa->update([
                'junta_registrada_at' => $ahora,
                'junta_registrada_por' => Auth::id(),
            ]);

            // La foto ya refleja la junta registrada.
            $servicio->olvidar();
            $semana = $servicio->semana($programa, $permisos);

            ProgramaJunta::create([
                'programa_id' => $programa->id,
                'registrada_por' => Auth::id(),
                'foto' => $semana,
                'created_at' => $ahora,
            ]);

            return $semana;
        });

        $registro->emitir($programa);

        return response()->json([
            'success' => true,
            'message' => 'Junta registrada.',
            'data' => $semana,
        ]);
    }

    /**
     * Inventario inicial manual de un grupo en esta semana (crea o actualiza).
     * Con `cajas: null` se quita y el grupo vuelve a arrastrar el final del
     * programa anterior.
     */
    public function inventarioInicial(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('configurar');

        $datos = $request->validate([
            'grupo_clave' => ['required', 'string', 'regex:/^(\d+|v\d+)$/'],
            'cajas' => ['present', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        // Sin ceros a la izquierda: «007» y «7» son el mismo grupo.
        $clave = $this->normalizarClave($datos['grupo_clave']);

        if (! $this->esGrupoValido($clave, $servicio)) {
            throw ValidationException::withMessages(['grupo_clave' => 'El grupo de variedades no existe.']);
        }

        if ($datos['cajas'] === null) {
            return $this->quitarInventarioInicial($programa, $clave, $servicio, $registro);
        }
        $cajas = (int) $datos['cajas'];

        $fila = DB::transaction(function () use ($programa, $clave, $cajas, $registro) {
            $existente = ProgramaInventarioInicial::where('programa_id', $programa->id)
                ->where('grupo_clave', $clave)
                ->lockForUpdate()
                ->first();

            // Sin cambio de valor no hay escritura ni historial.
            if ($existente && $existente->cajas === $cajas) {
                return $existente;
            }

            $fila = ProgramaInventarioInicial::updateOrCreate(
                ['programa_id' => $programa->id, 'grupo_clave' => $clave],
                ['cajas' => $cajas],
            );

            $registro->registrar(
                $programa,
                'inventario_inicial',
                $fila->id,
                $existente ? 'actualizado' : 'creado',
                ['grupo_clave' => $clave, 'cajas' => $existente?->cajas],
                ['grupo_clave' => $clave, 'cajas' => $cajas],
            );

            return $fila;
        });

        $servicio->olvidar();

        return response()->json([
            'success' => true,
            'message' => 'Inventario inicial guardado.',
            'data' => [
                'grupo_clave' => $clave,
                'cajas' => $fila->cajas,
                'balance' => $servicio->balanceDe($programa)[$clave] ?? null,
            ],
        ]);
    }

    /** Borra el inventario manual del grupo (si lo hay): queda en el historial y se avisa. */
    private function quitarInventarioInicial(ProgramaSemanal $programa, string $clave, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        DB::transaction(function () use ($programa, $clave, $registro) {
            $existente = ProgramaInventarioInicial::where('programa_id', $programa->id)
                ->where('grupo_clave', $clave)
                ->lockForUpdate()
                ->first();

            // Ya se arrastraba: no hay escritura ni historial.
            if (! $existente) {
                return;
            }

            $existente->delete();
            $registro->registrar(
                $programa,
                'inventario_inicial',
                $existente->id,
                'borrado',
                ['grupo_clave' => $clave, 'cajas' => $existente->cajas],
                null,
            );
        });

        $servicio->olvidar();

        return response()->json([
            'success' => true,
            'message' => 'Inventario inicial quitado: vuelve a arrastrarse de la semana anterior.',
            'data' => [
                'grupo_clave' => $clave,
                'cajas' => null,
                'balance' => $servicio->balanceDe($programa)[$clave] ?? null,
            ],
        ]);
    }

    /** «007» -> «7», «v007» -> «v7» (la clave ya pasó el formato `^(\d+|v\d+)$`). */
    private function normalizarClave(string $clave): string
    {
        return str_starts_with($clave, 'v')
            ? 'v'.(int) substr($clave, 1)
            : (string) (int) $clave;
    }

    /** Un grupo con id existente, o «v{id}» de una variedad que no pertenece a ningún grupo. */
    private function esGrupoValido(string $clave, ProgramaSemanalService $servicio): bool
    {
        if (ctype_digit($clave)) {
            return GrupoBalance::whereKey((int) $clave)->exists();
        }

        $variedadId = (int) substr($clave, 1);

        return Variedad::whereKey($variedadId)->exists() && $servicio->grupoDeVariedad($variedadId) === $clave;
    }
}
