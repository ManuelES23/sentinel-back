<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Empaque;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\ProcesoEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use App\Models\TipoCarga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LavadoEmpaqueController extends Controller
{
    private array $eagerLoad = [
        'entity:id,name,code,usa_hidrotermico',
        'recepcion:id,folio_recepcion,fecha_recepcion,cantidad_recibida,peso_recibido_kg,peso_bascula,salida_campo_id,tipo_carga_id,lote_producto_terminado',
        'recepcion.salidaCampo:id,variedad_id',
        'recepcion.salidaCampo.variedad:id,nombre',
        'recepcion.tipoCarga:id,nombre,peso_estimado_kg',
        'tipoCarga:id,nombre,peso_estimado_kg',
        'productor:id,nombre,apellido',
        'lote:id,nombre,numero_lote,zona_cultivo_id',
        'lote.zonaCultivo:id,nombre',
        'etapa:id,nombre,variedad_id',
        'etapa.variedad:id,nombre',
        'creador:id,name',
    ];

    private array $recepcionEagerLoad = [
        'entity:id,name,code,usa_hidrotermico',
        'salidaCampo:id,variedad_id',
        'salidaCampo.variedad:id,nombre',
        'productor:id,nombre,apellido',
        'lote:id,nombre,numero_lote,zona_cultivo_id',
        'lote.zonaCultivo:id,nombre',
        'etapa:id,nombre,variedad_id',
        'etapa.variedad:id,nombre',
        'tipoCarga:id,nombre,peso_estimado_kg',
    ];

    /**
     * GET /lavado/pendientes — Recepciones con cantidad disponible para lavar
     */
    public function pendientes(Request $request): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($request->input('entity_id'));
        if ($gate) {
            return $gate;
        }

        $query = RecepcionEmpaque::with($this->recepcionEagerLoad)
            ->where('status', '!=', 'rechazada');

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->byEntity($request->entity_id);
        }

        $recepciones = $query->orderByDesc('fecha_recepcion')->orderByDesc('id')->get();

        // Lavado pipeline states that consume from piso
        $lavadoStates = ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion', 'en_proceso', 'procesado'];

        $pendientes = [];
        foreach ($recepciones as $rec) {
            $procesosVinculados = ProcesoEmpaque::where('recepcion_id', $rec->id)
                ->whereIn('status', $lavadoStates)
                ->get(['cantidad_entrada', 'peso_entrada_kg', 'modo_kilos']);

            $usadasCajasLegacy = (int) $procesosVinculados->where('modo_kilos', false)->sum('cantidad_entrada');
            $usadasCajasModoKilos = (int) $procesosVinculados->where('modo_kilos', true)->sum('cantidad_entrada');
            $usadasKgEnModoKilos = (float) $procesosVinculados->where('modo_kilos', true)->sum('peso_entrada_kg');

            $pesoBascula = (float) ($rec->peso_bascula ?? 0);
            $puedeModoKilos = ($rec->entity?->usa_hidrotermico ?? false) && $pesoBascula > 0;

            // Descuento proporcional de cajas legacy sobre el peso báscula
            $totalCajas = (int) $rec->cantidad_recibida;
            $usadasKgEnModoCajas = ($pesoBascula > 0 && $totalCajas > 0)
                ? round(($usadasCajasLegacy / $totalCajas) * $pesoBascula, 2)
                : 0.0;
            $usadasKgTotal = $usadasKgEnModoKilos + $usadasKgEnModoCajas;
            $pesoBasculaDisponibleKg = max(0, round($pesoBascula - $usadasKgTotal, 2));

            // Cajas disponibles: total menos consumidas en cualquier modo (modo_kilos descuenta cajas equivalentes)
            $disponibleCajas = max(0, $totalCajas - $usadasCajasLegacy - $usadasCajasModoKilos);

            // Reglas de visibilidad:
            //  - Si la recepción es candidata a modo_kilos: aparece pendiente solo si quedan kg disponibles.
            //  - Si no: aparece pendiente solo si quedan cajas disponibles.
            $aparecePendiente = $puedeModoKilos
                ? $pesoBasculaDisponibleKg > 0
                : $disponibleCajas > 0;

            if ($aparecePendiente) {
                $pesoUnitario = $rec->tipoCarga ? (float) $rec->tipoCarga->peso_estimado_kg : 0;
                $variedad = $rec->etapa?->variedad ?? $rec->salidaCampo?->variedad;

                $pendientes[] = [
                    'recepcion_id' => $rec->id,
                    'folio' => $rec->folio_recepcion,
                    'fecha_recepcion' => $rec->fecha_recepcion,
                    'productor' => $rec->productor,
                    'lote' => $rec->lote,
                    'etapa' => $rec->etapa,
                    'salida_campo' => $rec->salidaCampo,
                    'variedad' => $variedad,
                    'variedad_nombre' => $variedad?->nombre,
                    'tipo_carga' => $rec->tipoCarga,
                    'entity' => $rec->entity,
                    'cantidad_recibida' => $rec->cantidad_recibida,
                    'cantidad_usada' => $usadasCajasLegacy + $usadasCajasModoKilos,
                    'cantidad_disponible' => $disponibleCajas,
                    'peso_disponible_kg' => round($disponibleCajas * $pesoUnitario, 2),
                    'peso_bascula' => $pesoBascula,
                    'peso_bascula_usada_kg' => round($usadasKgTotal, 2),
                    'peso_bascula_disponible_kg' => $pesoBasculaDisponibleKg,
                    'puede_modo_kilos' => $puedeModoKilos,
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $pendientes]);
    }

    /**
     * POST /lavado/mover-a-lavado — Create proceso with status=lavando
     *
     * Soporta dos modos:
     *  - Modo cajas (default): se proporciona `cantidad` (entero).
     *  - Modo kilos: cuando entity.usa_hidrotermico=true Y recepcion.peso_bascula>0
     *    se debe proporcionar `cantidad_kg` (decimal). El proceso queda con
     *    `modo_kilos=true` y `peso_disponible_kg` como fuente de verdad.
     */
    public function moverALavado(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|exists:temporadas,id',
            'recepcion_id' => 'required|exists:recepciones_empaque,id',
            'cantidad' => 'nullable|integer|min:1',
            'cantidad_kg' => 'nullable|numeric|min:0.01',
        ]);

        if (empty($validated['cantidad']) && empty($validated['cantidad_kg'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debes proporcionar `cantidad` (cajas) o `cantidad_kg` (kilos)',
            ], 422);
        }

        $recepcion = RecepcionEmpaque::with(['tipoCarga', 'entity'])->findOrFail($validated['recepcion_id']);

        $gate = $this->ensureLavadoEnabledForEntity($recepcion->entity_id);
        if ($gate) {
            return $gate;
        }

        $usaHidrotermico = (bool) ($recepcion->entity?->usa_hidrotermico ?? false);
        $pesoBascula = (float) ($recepcion->peso_bascula ?? 0);
        $puedeModoKilos = $usaHidrotermico && $pesoBascula > 0;
        $modoKilos = $puedeModoKilos && !empty($validated['cantidad_kg']);

        $lavadoStates = ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion', 'en_proceso', 'procesado'];
        $procesosVinculados = ProcesoEmpaque::where('recepcion_id', $recepcion->id)
            ->whereIn('status', $lavadoStates)
            ->get(['cantidad_entrada', 'peso_entrada_kg', 'modo_kilos']);

        $pesoUnitario = $recepcion->tipoCarga ? (float) $recepcion->tipoCarga->peso_estimado_kg : 0;
        $folioProceso = $recepcion->folio_recepcion;
        $fechaHoy = now('America/Mexico_City')->toDateString();

        if ($modoKilos) {
            $usadasKgEnModoKilos = (float) $procesosVinculados->where('modo_kilos', true)->sum('peso_entrada_kg');
            $usadasCajasLegacy = (int) $procesosVinculados->where('modo_kilos', false)->sum('cantidad_entrada');
            $totalCajas = (int) $recepcion->cantidad_recibida;
            $usadasKgEnModoCajas = ($pesoBascula > 0 && $totalCajas > 0)
                ? round(($usadasCajasLegacy / $totalCajas) * $pesoBascula, 2)
                : 0.0;
            $usadasKgTotal = $usadasKgEnModoKilos + $usadasKgEnModoCajas;
            $disponibleKg = max(0, round($pesoBascula - $usadasKgTotal, 2));
            $solicitadoKg = (float) $validated['cantidad_kg'];

            if ($solicitadoKg > $disponibleKg) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Kilos solicitados ($solicitadoKg) exceden disponible ($disponibleKg)",
                ], 422);
            }

            // Cajas equivalentes (referencia informativa)
            $cantidadEquivalente = $pesoUnitario > 0
                ? (int) max(1, round($solicitadoKg / $pesoUnitario))
                : 0;

            $proceso = ProcesoEmpaque::create([
                'temporada_id' => $validated['temporada_id'],
                'entity_id' => $recepcion->entity_id,
                'recepcion_id' => $recepcion->id,
                'folio_proceso' => $folioProceso,
                'tipo_carga_id' => $recepcion->tipo_carga_id,
                'productor_id' => $recepcion->productor_id,
                'lote_id' => $recepcion->lote_id,
                'etapa_id' => $recepcion->etapa_id,
                'cantidad_entrada' => $cantidadEquivalente,
                'peso_entrada_kg' => $solicitadoKg,
                'cantidad_disponible' => $cantidadEquivalente,
                'peso_disponible_kg' => $solicitadoKg,
                'modo_kilos' => true,
                'fecha_entrada' => $fechaHoy,
                'fecha_lavado' => $fechaHoy,
                'status' => 'lavando',
                'created_by' => $request->user()->id,
            ]);

            $proceso->load($this->eagerLoad);

            return response()->json([
                'success' => true,
                'message' => "Folio movido a lavado ($solicitadoKg kg)",
                'data' => $proceso,
            ], 201);
        }

        // Modo cajas (default)
        $usadas = (int) $procesosVinculados->where('modo_kilos', false)->sum('cantidad_entrada');
        $disponible = (int) $recepcion->cantidad_recibida - $usadas;
        $cantidad = (int) $validated['cantidad'];

        if ($cantidad > $disponible) {
            return response()->json([
                'status' => 'error',
                'message' => "Cantidad solicitada ($cantidad) excede disponible ($disponible)",
            ], 422);
        }

        $proceso = ProcesoEmpaque::create([
            'temporada_id' => $validated['temporada_id'],
            'entity_id' => $recepcion->entity_id,
            'recepcion_id' => $recepcion->id,
            'folio_proceso' => $folioProceso,
            'tipo_carga_id' => $recepcion->tipo_carga_id,
            'productor_id' => $recepcion->productor_id,
            'lote_id' => $recepcion->lote_id,
            'etapa_id' => $recepcion->etapa_id,
            'cantidad_entrada' => $cantidad,
            'peso_entrada_kg' => $cantidad * $pesoUnitario,
            'cantidad_disponible' => $cantidad,
            'peso_disponible_kg' => $cantidad * $pesoUnitario,
            'modo_kilos' => false,
            'fecha_entrada' => $fechaHoy,
            'fecha_lavado' => $fechaHoy,
            'status' => 'lavando',
            'created_by' => $request->user()->id,
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => "Folio movido a lavado ($cantidad uds)",
            'data' => $proceso,
        ], 201);
    }

    /**
     * GET /lavado/pipeline — Folios grouped by lavado pipeline status
     */
    public function pipeline(Request $request): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($request->input('entity_id'));
        if ($gate) {
            return $gate;
        }

        // Auto-consolida duplicados de "lavado" para evitar filas repetidas del mismo folio.
        $this->consolidarDuplicadosLavado(
            $request->input('temporada_id'),
            $request->input('entity_id')
        );
        // Auto-consolida duplicados de "enfriando" para evitar filas repetidas del mismo folio.
        $this->consolidarDuplicadosEnfriando(
            $request->input('temporada_id'),
            $request->input('entity_id')
        );

        $query = ProcesoEmpaque::with($this->eagerLoad)
            ->whereIn('status', ['lavando', 'lavado', 'hidrotermico', 'enfriando', 'listo_produccion']);

        if ($request->filled('temporada_id')) {
            $query->byTemporada($request->temporada_id);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        $procesos = $query->orderByDesc('id')->get();

        $grouped = [
            'lavando' => $procesos->where('status', 'lavando')->values(),
            'lavado' => $procesos->where('status', 'lavado')->values(),
            'hidrotermico' => $procesos->where('status', 'hidrotermico')->values(),
            'enfriando' => $procesos->where('status', 'enfriando')->values(),
            'listo_produccion' => $procesos->where('status', 'listo_produccion')->values(),
        ];

        // Check if entity uses hidrotermico
        $usaHidrotermico = false;
        if ($request->filled('entity_id')) {
            $entity = Entity::find($request->entity_id);
            $usaHidrotermico = $entity?->usa_hidrotermico ?? false;
        }

        return response()->json([
            'success' => true,
            'data' => $grouped,
            'usa_hidrotermico' => $usaHidrotermico,
        ]);
    }

    /**
     * POST /lavado/{proceso}/completar-lavado
     * Accepts optional rezaga data to register in the same step
     */
    public function completarLavado(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($proceso->entity_id);
        if ($gate) {
            return $gate;
        }

        if ($proceso->status !== 'lavando') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden completar folios en estado "lavando"',
            ], 422);
        }

        // Validate optional rezaga
        $request->validate([
            'rezaga_kg' => 'nullable|numeric|min:0.01',
            'rezaga_unidades' => 'nullable|integer|min:0',
            'rezaga_unidades_pequenas' => 'nullable|integer|min:0',
            'subtipo_rezaga' => 'required_with:rezaga_kg|in:hoja,producto',
            'rezaga_motivo' => 'nullable|string|max:500',
            'rezaga_observaciones' => 'nullable|string|max:1000',
            // kg finales (campo principal en modo_kilos)
            'kg_finales' => 'nullable|numeric|min:0.01',
            // tipo de carga e informativo de cajas (opcionales, solo referencia)
            'tipo_carga_convertida_id' => 'nullable|exists:tipos_carga,id',
            'cantidad_convertida' => 'nullable|integer|min:1',
        ]);

        // En modo_kilos, si se captura un solo conteo de cajas de rezaga,
        // reutilizarlo también como unidades_pequenas para mantener consistencia.
        if ($proceso->modo_kilos
            && $request->filled('rezaga_unidades')
            && !$request->filled('rezaga_unidades_pequenas')) {
            $request->merge([
                'rezaga_unidades_pequenas' => $request->integer('rezaga_unidades'),
            ]);
        }

        // Register rezaga if provided
        if ($request->filled('rezaga_kg')) {
            $this->crearRezagaInterna($proceso, 'lavado', $request);
        }

        // Check if entity uses hidrotermico
        $entity = Entity::find($proceso->entity_id);
        $usaHidrotermico = $entity?->usa_hidrotermico ?? false;

        if ($usaHidrotermico) {
            $updatePayload = ['status' => 'lavado'];

            if ($proceso->modo_kilos) {
                if (!$request->filled('kg_finales')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Debes capturar los kilogramos finales que pasan al siguiente paso.',
                    ], 422);
                }

                $kgFinales = round((float) $request->input('kg_finales'), 2);
                $kgRezaga = round((float) $request->input('rezaga_kg', 0), 2);
                $kgProcesados = round($kgFinales + $kgRezaga, 2);
                $kgDisponiblesProceso = round((float) ($proceso->peso_disponible_kg ?? $proceso->peso_entrada_kg ?? 0), 2);

                if ($kgDisponiblesProceso > 0 && $kgProcesados > $kgDisponiblesProceso) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Los kg finales + rezaga exceden el peso disponible del proceso. Finales: {$kgFinales} kg, rezaga: {$kgRezaga} kg, total: {$kgProcesados} kg, disponible: {$kgDisponiblesProceso} kg",
                    ], 422);
                }

                // Los kg finales son el peso disponible para el siguiente paso
                $updatePayload['peso_disponible_kg'] = $kgFinales;

                // Tipo de carga y cajas son informativos (se guardan si se capturan)
                if ($request->filled('tipo_carga_convertida_id')) {
                    $tipoCargaConvertida = TipoCarga::findOrFail($request->integer('tipo_carga_convertida_id'));
                    $updatePayload['tipo_carga_id'] = $tipoCargaConvertida->id;
                }
                if ($request->filled('cantidad_convertida')) {
                    $updatePayload['cantidad_disponible'] = $request->integer('cantidad_convertida');
                }
            }

            $proceso->update($updatePayload);
            $mensaje = 'Lavado completado. Pendiente de hidrotérmico';
        } else {
            $proceso->update([
                'status' => 'listo_produccion',
                'fecha_listo_produccion' => now('America/Mexico_City')->toDateString(),
            ]);
            $proceso = $this->consolidarListoProduccion($proceso);
            $mensaje = 'Lavado completado. Listo para producción';
        }

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => $mensaje,
            'data' => $proceso,
        ]);
    }

    /**
     * POST /lavado/{proceso}/iniciar-hidrotermico
     *
     * Para folios en modo_kilos permite capturar kilos parciales.
     * Si kg_hidrotermico < peso_disponible_kg → se crea un registro hijo para el lote
     * parcial (status=hidrotermico) y el padre queda en "lavado" con los kg restantes.
     * Si se envían todos los kg (o folio no es modo_kilos) → comportamiento original.
     */
    public function iniciarHidrotermico(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($proceso->entity_id);
        if ($gate) {
            return $gate;
        }

        if ($proceso->status !== 'lavado') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se puede iniciar hidrotérmico en folios con status "lavado"',
            ], 422);
        }


        $modoKilos = (bool) $proceso->modo_kilos;

        if ($modoKilos) {
            $request->validate([
                'kg_hidrotermico' => 'nullable|numeric|min:0.01',
                'cajas_hidrotermico' => 'nullable|integer|min:1',
            ]);

            $kgDisponible   = round((float) $proceso->peso_disponible_kg, 2);
            $cajasDisponibles = (int) $proceso->cantidad_disponible;

            // Obtener promedio real del lote
            $recepcion = $proceso->recepcion;
            $pesoBascula = (float) ($recepcion->peso_bascula ?? 0);
            $cantidadRecibida = (int) ($recepcion->cantidad_recibida ?? 0);
            $pesoUnitarioReal = ($pesoBascula > 0 && $cantidadRecibida > 0)
                ? round($pesoBascula / $cantidadRecibida, 4)
                : 0;

            $cajasSolicitadas = $request->filled('cajas_hidrotermico')
                ? (int) $request->integer('cajas_hidrotermico')
                : null;
            $kgSolicitado = $request->filled('kg_hidrotermico')
                ? round((float) $request->input('kg_hidrotermico'), 2)
                : null;

            // Si solo se envía cajas, calcular kg usando promedio real
            if ($cajasSolicitadas !== null && $kgSolicitado === null && $pesoUnitarioReal > 0) {
                $kgSolicitado = round($cajasSolicitadas * $pesoUnitarioReal, 2);
            }
            // Si solo se envía kg, calcular cajas usando promedio real
            if ($kgSolicitado !== null && $cajasSolicitadas === null && $pesoUnitarioReal > 0) {
                $cajasSolicitadas = max(1, (int) round($kgSolicitado / $pesoUnitarioReal));
            }
            // Si ambos vienen, usar ambos tal cual

            // Validaciones
            if ($kgSolicitado === null || $kgSolicitado <= 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Debes capturar cajas o kilos a mover a hidrotérmico',
                ], 422);
            }
            if ($kgSolicitado > $kgDisponible) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Kilos solicitados ($kgSolicitado) exceden los disponibles ($kgDisponible)",
                ], 422);
            }
            if ($cajasSolicitadas !== null && $cajasSolicitadas > $cajasDisponibles) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Cajas solicitadas ($cajasSolicitadas) exceden las disponibles ($cajasDisponibles)",
                ], 422);
            }

            $fechaHoy = now('America/Mexico_City')->toDateString();

            // Lote COMPLETO: actualizar el mismo registro
            if ($kgSolicitado >= $kgDisponible) {
                $proceso->update([
                    'status'              => 'hidrotermico',
                    'fecha_hidrotermico'  => $fechaHoy,
                    'cantidad_disponible' => $cajasSolicitadas,
                    'peso_disponible_kg'  => $kgSolicitado,
                ]);

                $proceso->load($this->eagerLoad);

                return response()->json([
                    'success' => true,
                    'message' => "Tratamiento hidrotérmico iniciado ({$kgSolicitado} kg → {$cajasSolicitadas} cajas)",
                    'data'    => $proceso,
                ]);
            }

            // Lote PARCIAL: clonar a nuevo registro hijo, reducir padre
            $kgRestante = round($kgDisponible - $kgSolicitado, 2);

            DB::transaction(function () use ($proceso, $kgSolicitado, $cajasSolicitadas, $kgRestante, $fechaHoy, $request) {
                $cajasRestante = (int) $proceso->cantidad_disponible - $cajasSolicitadas;
                if ($cajasRestante < 0) {
                    $cajasRestante = 0;
                }

                // Hijo: lote que entra a hidrotérmico ahora
                ProcesoEmpaque::create([
                    'temporada_id'         => $proceso->temporada_id,
                    'entity_id'            => $proceso->entity_id,
                    'recepcion_id'         => $proceso->recepcion_id,
                    'folio_proceso'        => $proceso->folio_proceso,
                    'tipo_carga_id'        => $proceso->tipo_carga_id,
                    'productor_id'         => $proceso->productor_id,
                    'lote_id'              => $proceso->lote_id,
                    'etapa_id'             => $proceso->etapa_id,
                    'cantidad_entrada'     => $cajasSolicitadas,
                    'peso_entrada_kg'      => $kgSolicitado,
                    'cantidad_disponible'  => $cajasSolicitadas,
                    'peso_disponible_kg'   => $kgSolicitado,
                    'modo_kilos'           => true,
                    'fecha_entrada'        => $proceso->fecha_entrada,
                    'fecha_lavado'         => $proceso->fecha_lavado,
                    'fecha_hidrotermico'   => $fechaHoy,
                    'status'               => 'hidrotermico',
                    'created_by'           => $request->user()->id,
                ]);

                // Padre: reducir kg/cajas disponibles, mantener en "lavado"
                $proceso->update([
                    'cantidad_disponible' => $cajasRestante,
                    'peso_disponible_kg'  => $kgRestante,
                ]);
            });

            $proceso->load($this->eagerLoad);

            return response()->json([
                'success' => true,
                'message' => "Lote parcial enviado a hidrotérmico: {$kgSolicitado} kg ({$cajasSolicitadas} cajas). Pendiente: {$kgRestante} kg",
                'data'    => $proceso,
            ]);
        }

        // ── Flujo modo cajas: comportamiento original ────────────────────────
        $proceso->update([
            'status'             => 'hidrotermico',
            'fecha_hidrotermico' => now('America/Mexico_City')->toDateString(),
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Tratamiento hidrotérmico iniciado',
            'data'    => $proceso,
        ]);
    }

    /**
     * POST /lavado/{proceso}/completar-hidrotermico
     * Accepts optional rezaga data to register in the same step
     */
    public function completarHidrotermico(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($proceso->entity_id);
        if ($gate) {
            return $gate;
        }

        if ($proceso->status !== 'hidrotermico') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se puede completar hidrotérmico en folios con status "hidrotermico"',
            ], 422);
        }

        if (
            $request->filled('rezaga_kg') ||
            $request->filled('rezaga_unidades') ||
            $request->filled('rezaga_unidades_pequenas') ||
            $request->filled('subtipo_rezaga') ||
            $request->filled('rezaga_motivo') ||
            $request->filled('rezaga_observaciones')
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'El paso de hidrotérmico no permite captura de rezaga',
            ], 422);
        }

        $proceso->update([
            'status' => 'enfriando',
            'fecha_enfriamiento' => now('America/Mexico_City')->toDateString(),
        ]);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Hidrotérmico completado. En enfriamiento',
            'data' => $proceso,
        ]);
    }

    /**
     * POST /lavado/{proceso}/completar-enfriamiento
     */
    public function completarEnfriamiento(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($proceso->entity_id);
        if ($gate) {
            return $gate;
        }

        if ($proceso->status !== 'enfriando') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se puede completar enfriamiento en folios con status "enfriando"',
            ], 422);
        }

        $proceso->update([
            'status' => 'listo_produccion',
            'fecha_listo_produccion' => now('America/Mexico_City')->toDateString(),
        ]);

        $proceso = $this->consolidarListoProduccion($proceso);

        $proceso->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => 'Enfriamiento completado. Listo para producción',
            'data' => $proceso,
        ]);
    }

    /**
     * POST /lavado/{proceso}/rezaga — Register rezaga (informational, does NOT deduct)
     */
    public function registrarRezaga(Request $request, ProcesoEmpaque $proceso): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($proceso->entity_id);
        if ($gate) {
            return $gate;
        }

        if ($proceso->status !== 'lavando') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se puede registrar rezaga en la etapa de lavado',
            ], 422);
        }

        $validated = $request->validate([
            'subtipo_rezaga' => 'required|in:hoja,producto',
            'cantidad_kg' => 'required|numeric|min:0.01',
            'cantidad_unidades' => 'nullable|integer|min:0',
            'cantidad_unidades_pequenas' => 'nullable|integer|min:0',
            'motivo' => 'nullable|string|max:500',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $tipoRezaga = 'lavado';

        // Generate folio
        $folioRezaga = $this->generarFolioRezaga($proceso->temporada_id, $proceso->entity_id);

        $rezaga = RezagaEmpaque::create([
            'temporada_id' => $proceso->temporada_id,
            'entity_id' => $proceso->entity_id,
            'proceso_id' => $proceso->id,
            'folio_rezaga' => $folioRezaga,
            'tipo_rezaga' => $tipoRezaga,
            'subtipo_rezaga' => $validated['subtipo_rezaga'],
            'fecha' => now('America/Mexico_City')->toDateString(),
            'cantidad_kg' => $validated['cantidad_kg'],
            'cantidad_unidades_pequenas' => $validated['cantidad_unidades_pequenas'] ?? null,
            'motivo' => $validated['motivo'] ?? null,
            'status' => 'pendiente',
            'observaciones' => $validated['observaciones'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Update informational accumulators on proceso (does NOT deduct cantidad_disponible)
        $campo_kg = "rezaga_{$tipoRezaga}_kg";
        $campo_cantidad = "rezaga_{$tipoRezaga}_cantidad";
        $proceso->increment($campo_kg, $validated['cantidad_kg']);
        if (!empty($validated['cantidad_unidades'])) {
            $proceso->increment($campo_cantidad, $validated['cantidad_unidades']);
        }

        $rezaga->load([
            'proceso:id,folio_proceso',
            'proceso.productor:id,nombre,apellido',
            'creador:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Rezaga registrada (informativa)',
            'data' => $rezaga,
        ], 201);
    }

    /**
     * POST /lavado/{proceso}/devolver-piso — Undo: remove from lavado pipeline
     */
    public function devolverAPiso(ProcesoEmpaque $proceso): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($proceso->entity_id);
        if ($gate) {
            return $gate;
        }

        if ($proceso->status !== 'lavando') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden devolver folios en estado "lavando"',
            ], 422);
        }

        $folio = $proceso->folio_proceso;
        $proceso->forceDelete();

        return response()->json([
            'success' => true,
            'message' => "Folio $folio devuelto a piso",
        ]);
    }

    /**
     * POST /lavado/{proceso}/devolver-lavado
     *
     * Devuelve un folio de hidrotérmico a lavado (esperando hidrotérmico).
     */
    public function devolverALavado(ProcesoEmpaque $proceso): JsonResponse
    {
        $gate = $this->ensureLavadoEnabledForEntity($proceso->entity_id);
        if ($gate) {
            return $gate;
        }

        if ($proceso->status !== 'hidrotermico') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo se pueden devolver folios en estado "hidrotérmico"',
            ], 422);
        }

        $target = ProcesoEmpaque::query()
            ->where('id', '!=', $proceso->id)
            ->where('temporada_id', $proceso->temporada_id)
            ->where('entity_id', $proceso->entity_id)
            ->where('folio_proceso', $proceso->folio_proceso)
            ->where('status', 'lavado')
            ->orderBy('id')
            ->first();

        if (!$target) {
            $proceso->update([
                'status' => 'lavado',
                'fecha_hidrotermico' => null,
            ]);

            $proceso->load($this->eagerLoad);

            return response()->json([
                'success' => true,
                'message' => "Folio {$proceso->folio_proceso} devuelto a lavado (esperando hidrotérmico)",
                'data' => $proceso,
            ]);
        }

        DB::transaction(function () use (&$target, $proceso) {
            $target->update([
                'cantidad_entrada' => (int) $target->cantidad_entrada + (int) $proceso->cantidad_entrada,
                'peso_entrada_kg' => (float) $target->peso_entrada_kg + (float) $proceso->peso_entrada_kg,
                'cantidad_disponible' => (int) $target->cantidad_disponible + (int) $proceso->cantidad_disponible,
                'peso_disponible_kg' => (float) $target->peso_disponible_kg + (float) $proceso->peso_disponible_kg,
                'rezaga_lavado_kg' => (float) $target->rezaga_lavado_kg + (float) $proceso->rezaga_lavado_kg,
                'rezaga_lavado_cantidad' => (int) $target->rezaga_lavado_cantidad + (int) $proceso->rezaga_lavado_cantidad,
                'rezaga_hidrotermico_kg' => (float) $target->rezaga_hidrotermico_kg + (float) $proceso->rezaga_hidrotermico_kg,
                'rezaga_hidrotermico_cantidad' => (int) $target->rezaga_hidrotermico_cantidad + (int) $proceso->rezaga_hidrotermico_cantidad,
                'fecha_hidrotermico' => null,
            ]);

            RezagaEmpaque::where('proceso_id', $proceso->id)
                ->update(['proceso_id' => $target->id]);

            // Se oculta el registro devuelto para evitar duplicados en pipeline.
            $proceso->delete();

            $target->refresh();
        });

        $target->load($this->eagerLoad);

        return response()->json([
            'success' => true,
            'message' => "Folio {$target->folio_proceso} consolidado en lavado (esperando hidrotérmico)",
            'data' => $target,
        ]);
    }

    /**
     * Helper: create rezaga record during step completion
     */
    private function crearRezagaInterna(ProcesoEmpaque $proceso, string $tipoRezaga, Request $request): RezagaEmpaque
    {
        $folioRezaga = $this->generarFolioRezaga($proceso->temporada_id, $proceso->entity_id);

        $rezaga = RezagaEmpaque::create([
            'temporada_id' => $proceso->temporada_id,
            'entity_id' => $proceso->entity_id,
            'proceso_id' => $proceso->id,
            'folio_rezaga' => $folioRezaga,
            'tipo_rezaga' => $tipoRezaga,
            'subtipo_rezaga' => $request->input('subtipo_rezaga'),
            'fecha' => now('America/Mexico_City')->toDateString(),
            'cantidad_kg' => $request->input('rezaga_kg'),
            'cantidad_unidades_pequenas' => $request->input('rezaga_unidades_pequenas'),
            'motivo' => $request->input('rezaga_motivo'),
            'status' => 'pendiente',
            'observaciones' => $request->input('rezaga_observaciones'),
            'created_by' => $request->user()->id,
        ]);

        // Update informational accumulators
        $proceso->increment("rezaga_{$tipoRezaga}_kg", $request->input('rezaga_kg'));
        if ($request->filled('rezaga_unidades')) {
            $proceso->increment("rezaga_{$tipoRezaga}_cantidad", $request->input('rezaga_unidades'));
        }

        return $rezaga;
    }

    /**
     * Consolida folios parciales en listo_produccion para evitar duplicados del mismo folio.
     */
    private function consolidarListoProduccion(ProcesoEmpaque $proceso): ProcesoEmpaque
    {
        if ($proceso->status !== 'listo_produccion') {
            return $proceso;
        }

        $target = ProcesoEmpaque::query()
            ->where('id', '!=', $proceso->id)
            ->where('temporada_id', $proceso->temporada_id)
            ->where('entity_id', $proceso->entity_id)
            ->where('recepcion_id', $proceso->recepcion_id)
            ->where('folio_proceso', $proceso->folio_proceso)
            ->where('status', 'listo_produccion')
            ->orderBy('id')
            ->first();

        if (!$target) {
            return $proceso;
        }

        DB::transaction(function () use (&$target, $proceso) {
            $target->update([
                'cantidad_entrada' => (int) $target->cantidad_entrada + (int) $proceso->cantidad_entrada,
                'peso_entrada_kg' => (float) $target->peso_entrada_kg + (float) $proceso->peso_entrada_kg,
                'cantidad_disponible' => (int) $target->cantidad_disponible + (int) $proceso->cantidad_disponible,
                'peso_disponible_kg' => (float) $target->peso_disponible_kg + (float) $proceso->peso_disponible_kg,
                'rezaga_lavado_kg' => (float) $target->rezaga_lavado_kg + (float) $proceso->rezaga_lavado_kg,
                'rezaga_lavado_cantidad' => (int) $target->rezaga_lavado_cantidad + (int) $proceso->rezaga_lavado_cantidad,
                'rezaga_hidrotermico_kg' => (float) $target->rezaga_hidrotermico_kg + (float) $proceso->rezaga_hidrotermico_kg,
                'rezaga_hidrotermico_cantidad' => (int) $target->rezaga_hidrotermico_cantidad + (int) $proceso->rezaga_hidrotermico_cantidad,
                'fecha_listo_produccion' => now('America/Mexico_City')->toDateString(),
            ]);

            RezagaEmpaque::where('proceso_id', $proceso->id)
                ->update(['proceso_id' => $target->id]);

            // Soft delete: mantiene trazabilidad y evita mostrar duplicados en queries normales.
            $proceso->delete();

            $target->refresh();
        });

        return $target;
    }

    /**
     * Consolida registros duplicados en estado "lavado" por folio para
     * temporada+entidad. Mantiene un registro y fusiona cantidades/pesos.
     */
    private function consolidarDuplicadosLavado($temporadaId = null, $entityId = null): void
    {
        $this->consolidarDuplicadosPorStatus('lavado', $temporadaId, $entityId);
    }

    /**
     * Consolida registros duplicados en estado "enfriando" por folio para
     * temporada+entidad. Mantiene un registro y fusiona cantidades/pesos.
     */
    private function consolidarDuplicadosEnfriando($temporadaId = null, $entityId = null): void
    {
        $this->consolidarDuplicadosPorStatus('enfriando', $temporadaId, $entityId);
    }

    /**
     * Consolida registros duplicados por status y folio para temporada+entidad.
     */
    private function consolidarDuplicadosPorStatus(string $status, $temporadaId = null, $entityId = null): void
    {
        $duplicados = ProcesoEmpaque::query()
            ->where('status', $status)
            ->when($temporadaId, fn ($q) => $q->where('temporada_id', $temporadaId))
            ->when($entityId, fn ($q) => $q->where('entity_id', $entityId))
            ->select('temporada_id', 'entity_id', 'folio_proceso', DB::raw('COUNT(*) as total'))
            ->groupBy('temporada_id', 'entity_id', 'folio_proceso')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicados as $dup) {
            $registros = ProcesoEmpaque::query()
                ->where('status', $status)
                ->where('temporada_id', $dup->temporada_id)
                ->where('entity_id', $dup->entity_id)
                ->where('folio_proceso', $dup->folio_proceso)
                ->orderBy('id')
                ->get();

            if ($registros->count() <= 1) {
                continue;
            }

            /** @var ProcesoEmpaque $target */
            $target = $registros->first();
            $others = $registros->slice(1);

            DB::transaction(function () use ($registros, $target, $others) {
                $target->update([
                    'cantidad_entrada' => (int) $registros->sum('cantidad_entrada'),
                    'peso_entrada_kg' => (float) $registros->sum('peso_entrada_kg'),
                    'cantidad_disponible' => (int) $registros->sum('cantidad_disponible'),
                    'peso_disponible_kg' => (float) $registros->sum('peso_disponible_kg'),
                    'rezaga_lavado_kg' => (float) $registros->sum('rezaga_lavado_kg'),
                    'rezaga_lavado_cantidad' => (int) $registros->sum('rezaga_lavado_cantidad'),
                    'rezaga_hidrotermico_kg' => (float) $registros->sum('rezaga_hidrotermico_kg'),
                    'rezaga_hidrotermico_cantidad' => (int) $registros->sum('rezaga_hidrotermico_cantidad'),
                    'fecha_hidrotermico' => null,
                ]);

                $otherIds = $others->pluck('id')->values();
                if ($otherIds->isNotEmpty()) {
                    RezagaEmpaque::whereIn('proceso_id', $otherIds)
                        ->update(['proceso_id' => $target->id]);

                    ProcesoEmpaque::whereIn('id', $otherIds)->delete();
                }
            });
        }
    }

    private function generarFolioRezaga(int $temporadaId, int $entityId): string
    {
        $entityPad = str_pad((string) $entityId, 2, '0', STR_PAD_LEFT);
        $prefix = "REZ-{$entityPad}-";

        // Nota: la constraint unique de folio_rezaga es global (no por temporada),
        // por eso el contador se calcula globalmente por entity_id (no por temporada).
        $lastFolio = RezagaEmpaque::withTrashed()
            ->where('entity_id', $entityId)
            ->where('folio_rezaga', 'like', "{$prefix}%")
            ->orderByDesc('folio_rezaga')
            ->value('folio_rezaga');

        $nextNum = 1;
        if ($lastFolio) {
            $nextNum = (int) str_replace($prefix, '', $lastFolio) + 1;
        }

        // Loop de seguridad ante race conditions
        for ($i = 0; $i < 5; $i++) {
            $candidate = $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
            $exists = RezagaEmpaque::withTrashed()->where('folio_rezaga', $candidate)->exists();
            if (!$exists) {
                return $candidate;
            }
            $nextNum++;
        }

        return $prefix . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    }

    private function ensureLavadoEnabledForEntity($entityId): ?JsonResponse
    {
        if (!$entityId) {
            return response()->json([
                'status' => 'error',
                'message' => 'El módulo Lavado requiere entity_id',
            ], 422);
        }

        $entity = Entity::find($entityId);

        if (!$entity) {
            return response()->json([
                'status' => 'error',
                'message' => 'Entidad no encontrada',
            ], 404);
        }

        if (!$entity->usa_hidrotermico) {
            return response()->json([
                'status' => 'error',
                'message' => 'Acceso denegado: el módulo Lavado solo está disponible para empaques con hidrotérmico habilitado',
            ], 403);
        }

        return null;
    }
}
