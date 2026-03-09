<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\DiagnosticoIA;
use App\Models\Etapa;
use App\Services\DiagnosticoIAService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DiagnosticoIAController extends Controller
{
    /**
     * Analizar imagen de cultivo con IA
     *
     * Recibe imagen + contexto agrícola, envía a GPT-4o Vision,
     * guarda resultado y retorna diagnóstico.
     */
    public function analizar(Request $request): JsonResponse
    {
        $request->validate([
            'imagen' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240', // 10MB
            'temporada_id' => 'required|exists:temporadas,id',
            'visita_campo_id' => 'nullable|exists:visitas_campo,id',
            'etapa_id' => 'nullable|exists:etapas,id',
            'observaciones' => 'nullable|string|max:500',
        ]);

        // Guardar imagen
        $imagePath = $request->file('imagen')->store('diagnosticos-ia', 'public');
        $imageUrl = Storage::disk('public')->url($imagePath);

        // Construir contexto agrícola
        $contexto = $this->buildContexto($request);

        // Crear registro en procesando
        $diagnostico = DiagnosticoIA::create([
            'temporada_id' => $request->temporada_id,
            'user_id' => Auth::id(),
            'visita_campo_id' => $request->visita_campo_id,
            'etapa_id' => $request->etapa_id,
            'imagen_path' => $imagePath,
            'imagen_url' => $imageUrl,
            'contexto_agricola' => $contexto,
            'status' => DiagnosticoIA::STATUS_PROCESANDO,
        ]);

        // Llamar al servicio de IA
        $service = new DiagnosticoIAService();
        $resultado = $service->analizar($imagePath, $contexto);

        if ($resultado['success']) {
            $diagnostico->update([
                'diagnostico' => $resultado['diagnostico'],
                'plagas_detectadas' => $resultado['plagas_detectadas'],
                'enfermedades_detectadas' => $resultado['enfermedades_detectadas'],
                'estado_fenologico' => $resultado['estado_fenologico'],
                'recomendaciones' => $resultado['recomendaciones'],
                'nivel_urgencia' => $resultado['nivel_urgencia'],
                'confianza' => $resultado['confianza'],
                'tokens_usados' => $resultado['tokens_usados'],
                'status' => DiagnosticoIA::STATUS_COMPLETADO,
            ]);
        } else {
            $diagnostico->update([
                'status' => DiagnosticoIA::STATUS_ERROR,
                'error_message' => $resultado['error'],
            ]);
        }

        $diagnostico->refresh();
        $diagnostico->load(['user', 'etapa.lote', 'etapa.variedad']);

        return response()->json([
            'success' => $resultado['success'],
            'message' => $resultado['success'] ? 'Diagnóstico completado' : $resultado['error'],
            'data' => $diagnostico,
        ], $resultado['success'] ? 200 : 422);
    }

    /**
     * Historial de diagnósticos de la temporada
     */
    public function historial(Request $request): JsonResponse
    {
        $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
        ]);

        $diagnosticos = DiagnosticoIA::byTemporada($request->temporada_id)
            ->with(['user', 'etapa.lote', 'visitaCampo'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $diagnosticos,
        ]);
    }

    /**
     * Ver un diagnóstico específico
     */
    public function show(DiagnosticoIA $diagnostico): JsonResponse
    {
        $diagnostico->load(['user', 'etapa.lote', 'etapa.variedad', 'visitaCampo']);

        return response()->json([
            'success' => true,
            'data' => $diagnostico,
        ]);
    }

    /**
     * Construir array de contexto agrícola a partir de la etapa y temporada
     */
    protected function buildContexto(Request $request): array
    {
        $contexto = [];

        if ($request->etapa_id) {
            $etapa = Etapa::with(['lote.zona.productor', 'variedad.cultivo', 'tipoVariedad'])->find($request->etapa_id);

            if ($etapa) {
                $contexto['lote'] = $etapa->lote?->nombre;
                $contexto['superficie'] = $etapa->superficie;
                $contexto['fecha_siembra'] = $etapa->fecha_siembra_real?->format('Y-m-d')
                    ?? $etapa->fecha_siembra_estimada?->format('Y-m-d');

                if ($etapa->variedad) {
                    $contexto['variedad'] = $etapa->variedad->nombre;
                    if ($etapa->variedad->cultivo) {
                        $contexto['cultivo'] = $etapa->variedad->cultivo->nombre;
                    }
                }

                if ($etapa->tipoVariedad) {
                    $contexto['tipo_variedad'] = $etapa->tipoVariedad->nombre;
                }

                if ($etapa->lote?->zona) {
                    $contexto['ubicacion'] = $etapa->lote->zona->nombre;
                }
            }
        }

        // Temporada info
        if ($request->temporada_id) {
            $temporada = \App\Models\Temporada::find($request->temporada_id);
            if ($temporada) {
                $contexto['temporada'] = $temporada->nombre ?? $temporada->folio_temporada;
            }
        }

        if ($request->observaciones) {
            $contexto['observaciones'] = $request->observaciones;
        }

        return $contexto;
    }
}
