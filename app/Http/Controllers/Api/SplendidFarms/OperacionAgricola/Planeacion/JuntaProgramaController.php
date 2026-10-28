<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Planeacion\Concerns\PermisoProgramaSemanal;
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

    /** Inventario inicial manual de un grupo en esta semana (crea o actualiza). */
    public function inventarioInicial(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('configurar');

        $datos = $request->validate([
            'grupo_clave' => ['required', 'string', 'regex:/^(\d+|v\d+)$/'],
            'cajas' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);
        $clave = $datos['grupo_clave'];

        if (! $this->esGrupoValido($clave, $servicio)) {
            throw ValidationException::withMessages(['grupo_clave' => 'El grupo de variedades no existe.']);
        }

        $existente = ProgramaInventarioInicial::where('programa_id', $programa->id)->where('grupo_clave', $clave)->first();
        $antes = $existente ? ['cajas' => $existente->cajas] : null;

        $fila = ProgramaInventarioInicial::updateOrCreate(
            ['programa_id' => $programa->id, 'grupo_clave' => $clave],
            ['cajas' => $datos['cajas']],
        );

        $registro->registrar(
            $programa,
            'inventario_inicial',
            $fila->id,
            $existente ? 'actualizado' : 'creado',
            $antes,
            ['grupo_clave' => $clave, 'cajas' => $fila->cajas],
        );

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
