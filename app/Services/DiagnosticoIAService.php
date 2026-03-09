<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Servicio de Diagnóstico Agrícola con IA (OpenAI GPT-4o Vision)
 *
 * Analiza imágenes de cultivos y regresa diagnóstico estructurado:
 * plagas, enfermedades, estado fenológico y recomendaciones.
 */
class DiagnosticoIAService
{
    protected string $apiKey;
    protected string $model;
    protected string $apiUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', '');
        $this->model = config('services.openai.model', 'gpt-4o');
    }

    /**
     * Analizar una imagen de cultivo
     *
     * @param string $imagePath  Ruta al archivo de imagen (storage)
     * @param array  $contexto   Contexto agrícola opcional
     * @return array Resultado del diagnóstico
     */
    public function analizar(string $imagePath, array $contexto = []): array
    {
        if (empty($this->apiKey)) {
            return $this->errorResult('API key de OpenAI no configurada. Agrega OPENAI_API_KEY en .env');
        }

        try {
            // Leer imagen y convertir a base64
            $imageData = $this->getImageBase64($imagePath);
            if (!$imageData) {
                return $this->errorResult('No se pudo leer la imagen');
            }

            // Construir el prompt con contexto
            $systemPrompt = $this->buildSystemPrompt();
            $userPrompt = $this->buildUserPrompt($contexto);

            // Llamar a OpenAI
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => $userPrompt,
                                ],
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => "data:{$imageData['mime']};base64,{$imageData['data']}",
                                        'detail' => 'high',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'max_tokens' => 2000,
                    'temperature' => 0.3,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (!$response->successful()) {
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return $this->errorResult('Error del servicio de IA: ' . $response->status());
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;
            $tokensUsed = $data['usage']['total_tokens'] ?? null;

            if (!$content) {
                return $this->errorResult('Respuesta vacía del modelo');
            }

            $parsed = json_decode($content, true);
            if (!$parsed) {
                return $this->errorResult('No se pudo parsear la respuesta del modelo');
            }

            return [
                'success' => true,
                'diagnostico' => $parsed['diagnostico'] ?? 'Sin diagnóstico disponible',
                'plagas_detectadas' => $parsed['plagas_detectadas'] ?? [],
                'enfermedades_detectadas' => $parsed['enfermedades_detectadas'] ?? [],
                'estado_fenologico' => $parsed['estado_fenologico'] ?? null,
                'recomendaciones' => $parsed['recomendaciones'] ?? [],
                'nivel_urgencia' => $parsed['nivel_urgencia'] ?? 'bajo',
                'confianza' => $parsed['confianza'] ?? null,
                'tokens_usados' => $tokensUsed,
            ];
        } catch (\Exception $e) {
            Log::error('DiagnosticoIA error', ['error' => $e->getMessage()]);
            return $this->errorResult('Error procesando imagen: ' . $e->getMessage());
        }
    }

    /**
     * Prompt del sistema para el rol de agrónomo experto
     */
    protected function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Eres un ingeniero agrónomo experto en fitopatología, entomología agrícola y fisiología vegetal, 
especializado en cultivos de México (berries, hortalizas, granos, frutales).

Tu tarea es analizar fotografías de cultivos y proporcionar un diagnóstico profesional.

DEBES responder SIEMPRE en formato JSON con esta estructura exacta:
{
  "diagnostico": "Descripción general clara y concisa del estado del cultivo observado en la imagen",
  "plagas_detectadas": [
    {
      "nombre": "Nombre común de la plaga",
      "nombre_cientifico": "Nombre científico si es identificable",
      "severidad": "baja|media|alta|critica",
      "descripcion": "Cómo se manifiesta en la imagen"
    }
  ],
  "enfermedades_detectadas": [
    {
      "nombre": "Nombre de la enfermedad",
      "agente_causal": "Hongo/Bacteria/Virus si se identifica",
      "severidad": "baja|media|alta|critica",
      "descripcion": "Síntomas visibles"
    }
  ],
  "estado_fenologico": "Estado fenológico observado (ej: vegetativo, floración, fructificación, maduración, etc.)",
  "recomendaciones": [
    {
      "tipo": "preventiva|curativa|nutricional|manejo",
      "accion": "Acción específica recomendada",
      "producto_sugerido": "Producto o ingrediente activo sugerido (si aplica)",
      "urgencia": "inmediata|corto_plazo|mediano_plazo",
      "notas": "Consideraciones adicionales"
    }
  ],
  "nivel_urgencia": "bajo|medio|alto|critico",
  "confianza": 85.0,
  "observaciones_adicionales": "Cualquier observación relevante sobre condiciones del cultivo, suelo, riego, etc."
}

Reglas:
- Si NO detectas plagas o enfermedades, deja los arrays vacíos y di que el cultivo se ve saludable
- El nivel de confianza debe reflejar qué tan seguro estás del diagnóstico (0-100)
- Las recomendaciones deben ser prácticas y aplicables en campo
- Si la imagen no es de un cultivo agrícola, indícalo en el diagnóstico
- Si necesitas más información para un diagnóstico más preciso, menciónalo en observaciones_adicionales
- Responde en español mexicano profesional
PROMPT;
    }

    /**
     * Prompt del usuario con contexto agrícola
     */
    protected function buildUserPrompt(array $contexto): string
    {
        $prompt = "Analiza esta imagen de cultivo y proporciona un diagnóstico completo.\n\n";

        if (!empty($contexto)) {
            $prompt .= "CONTEXTO AGRÍCOLA:\n";

            if (!empty($contexto['cultivo'])) {
                $prompt .= "- Cultivo: {$contexto['cultivo']}\n";
            }
            if (!empty($contexto['variedad'])) {
                $prompt .= "- Variedad: {$contexto['variedad']}\n";
            }
            if (!empty($contexto['etapa_fenologica'])) {
                $prompt .= "- Etapa fenológica reportada: {$contexto['etapa_fenologica']}\n";
            }
            if (!empty($contexto['lote'])) {
                $prompt .= "- Lote: {$contexto['lote']}\n";
            }
            if (!empty($contexto['superficie'])) {
                $prompt .= "- Superficie: {$contexto['superficie']} ha\n";
            }
            if (!empty($contexto['fecha_siembra'])) {
                $prompt .= "- Fecha de siembra: {$contexto['fecha_siembra']}\n";
            }
            if (!empty($contexto['temporada'])) {
                $prompt .= "- Temporada: {$contexto['temporada']}\n";
            }
            if (!empty($contexto['ubicacion'])) {
                $prompt .= "- Ubicación: {$contexto['ubicacion']}\n";
            }
            if (!empty($contexto['observaciones'])) {
                $prompt .= "- Observaciones del ingeniero: {$contexto['observaciones']}\n";
            }

            $prompt .= "\nUsa este contexto para dar un diagnóstico más preciso y recomendaciones específicas para este cultivo y condiciones.\n";
        }

        return $prompt;
    }

    /**
     * Leer imagen y convertir a base64
     */
    protected function getImageBase64(string $imagePath): ?array
    {
        // Intentar desde storage
        if (Storage::disk('public')->exists($imagePath)) {
            $content = Storage::disk('public')->get($imagePath);
            $mimeType = Storage::disk('public')->mimeType($imagePath);
        }
        // Intentar como ruta absoluta
        elseif (file_exists($imagePath)) {
            $content = file_get_contents($imagePath);
            $mimeType = mime_content_type($imagePath);
        }
        // Intentar desde storage directamente (path completo)  
        elseif (Storage::exists($imagePath)) {
            $content = Storage::get($imagePath);
            $mimeType = Storage::mimeType($imagePath);
        } else {
            return null;
        }

        return [
            'data' => base64_encode($content),
            'mime' => $mimeType ?: 'image/jpeg',
        ];
    }

    /**
     * Resultado de error estandarizado
     */
    protected function errorResult(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'diagnostico' => null,
            'plagas_detectadas' => [],
            'enfermedades_detectadas' => [],
            'estado_fenologico' => null,
            'recomendaciones' => [],
            'nivel_urgencia' => null,
            'confianza' => null,
            'tokens_usados' => null,
        ];
    }
}
