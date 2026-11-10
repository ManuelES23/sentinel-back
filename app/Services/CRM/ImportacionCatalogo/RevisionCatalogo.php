<?php

namespace App\Services\CRM\ImportacionCatalogo;

use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmSublinea;
use App\Support\CRM\NombreCatalogo;
use Illuminate\Support\Facades\DB;

/**
 * Compara el Excel ya leído contra el catálogo de la empresa y cuenta qué pasaría al importar.
 * Solo lee la BD: nunca escribe (ni productos ni marcas, líneas o sublíneas).
 */
class RevisionCatalogo
{
    /**
     * Resumen de la revisión (con la primera fila como elegida en cada clave repetida).
     *
     * @param  array  $lectura  Salida de LectorCatalogo::leer()
     */
    public function revisar(int $empresaId, array $lectura): array
    {
        $resumen = $this->planificar($empresaId, $lectura, [])['resumen'];
        $resumen['huella'] = $this->huella($empresaId, $resumen);

        return $resumen;
    }

    /** Hash del resumen y del estado de los productos de la empresa (cuántos y el último cambio). */
    public function huella(int $empresaId, array $resumen): string
    {
        unset($resumen['huella']);
        $estado = CrmProducto::where('empresa_id', $empresaId)
            ->selectRaw('count(*) as total, max(updated_at) as ultimo')->first();

        return hash('sha256', json_encode($resumen, JSON_UNESCAPED_UNICODE).'|'.$estado->total.'|'.$estado->ultimo);
    }

    /**
     * Plan de importación: una fila por clave (la elegida o, por defecto, la primera) con su estado.
     *
     * @param  array<string,int>  $elegidas  clave (se normaliza aquí) => número de fila del Excel
     * @return array{resumen: array, filas: list<array>, faltantesIds: list<int>}
     */
    public function planificar(int $empresaId, array $lectura, array $elegidas): array
    {
        $elegidasNorm = [];
        foreach ($elegidas as $clave => $fila) {
            $elegidasNorm[NombreCatalogo::clave((string) $clave)] = (int) $fila;
        }

        // Grupos por clave normalizada, en el orden del Excel.
        $grupos = [];
        foreach ($lectura['filas'] as $fila) {
            $grupos[NombreCatalogo::clave($fila['clave'])][] = $fila;
        }

        $repetidas = [];
        $finales = [];
        foreach ($grupos as $claveNorm => $filas) {
            $elegida = $filas[0];
            if (count($filas) > 1) {
                $repetidas[] = [
                    'clave' => $filas[0]['clave'],
                    'filas' => array_map(fn ($f) => [
                        'fila' => $f['fila'], 'producto' => $f['producto'], 'marca' => $f['marca'], 'linea' => $f['linea'],
                    ], $filas),
                ];
                foreach ($filas as $f) {
                    if (isset($elegidasNorm[$claveNorm]) && $f['fila'] === $elegidasNorm[$claveNorm]) {
                        $elegida = $f;
                    }
                }
            }
            $finales[(string) $claveNorm] = $elegida;
        }

        // Estado actual de la empresa.
        $marcas = CrmMarca::where('empresa_id', $empresaId)->pluck('nombre_clave', 'id')->all();
        $lineas = CrmLinea::where('empresa_id', $empresaId)->pluck('nombre_clave', 'id')->all();
        $sublineas = [];   // id => nombre_clave
        $sublineaDe = [];  // "linea_id|nombre_clave" => id
        foreach (CrmSublinea::whereIn('linea_id', array_keys($lineas))->get(['id', 'linea_id', 'nombre_clave']) as $s) {
            $sublineas[$s->id] = $s->nombre_clave;
            $sublineaDe[$s->linea_id.'|'.$s->nombre_clave] = $s->id;
        }
        $lineaIdDe = array_flip($lineas);

        $existentes = [];
        foreach (DB::table('crm_productos')->where('empresa_id', $empresaId)->whereNotNull('clave_normalizada')
            ->get(['id', 'clave_normalizada', 'nombre', 'marca_id', 'linea_id', 'sublinea_id', 'unidad_medida', 'activo']) as $p) {
            $existentes[(string) $p->clave_normalizada] = $p;
        }

        $nuevos = $actualizados = $sinCambio = 0;
        $marcasNuevas = $lineasNuevas = $sublineasNuevas = [];
        $filasPlan = [];
        foreach ($finales as $claveNorm => $f) {
            $marcaK = NombreCatalogo::clave($f['marca']);
            $lineaK = NombreCatalogo::clave($f['linea']);
            $subK = $f['sublinea'] === null ? null : NombreCatalogo::clave($f['sublinea']);

            if (! in_array($marcaK, $marcas, true)) {
                $marcasNuevas[$marcaK] = true;
            }
            if (! in_array($lineaK, $lineas, true)) {
                $lineasNuevas[$lineaK] = true;
            }
            if ($subK !== null && ! isset($sublineaDe[($lineaIdDe[$lineaK] ?? 0).'|'.$subK])) {
                $sublineasNuevas[$lineaK.'|'.$subK] = true;
            }

            $e = $existentes[$claveNorm] ?? null;
            if ($e === null) {
                $estado = 'nuevo';
                $nuevos++;
            } else {
                $cambia = NombreCatalogo::limpio($e->nombre) !== NombreCatalogo::limpio($f['producto'])
                    || ($marcas[$e->marca_id] ?? null) !== $marcaK
                    || ($lineas[$e->linea_id] ?? null) !== $lineaK
                    || ($sublineas[$e->sublinea_id] ?? null) !== $subK
                    || ($lectura['tieneUnidad'] && $f['unidad'] !== null && $f['unidad'] !== $e->unidad_medida);
                $estado = $cambia ? 'actualizado' : 'sinCambio';
                $cambia ? $actualizados++ : $sinCambio++;
            }
            $filasPlan[] = $f + ['claveNorm' => $claveNorm, 'estado' => $estado, 'existente' => $e?->id, 'marcaK' => $marcaK, 'lineaK' => $lineaK, 'subK' => $subK];
        }

        // Productos activos con clave que el archivo no trae (ni siquiera en filas con problema).
        $presentes = array_flip(array_map('strval', array_merge(array_keys($grupos), $lectura['clavesConProblema'] ?? [])));
        $faltantesIds = [];
        foreach ($existentes as $claveNorm => $p) {
            if ($p->activo && ! isset($presentes[$claveNorm])) {
                $faltantesIds[] = $p->id;
            }
        }

        return [
            'resumen' => [
                'nuevos' => $nuevos,
                'actualizados' => $actualizados,
                'sinCambio' => $sinCambio,
                'conProblema' => count($lectura['problemas']),
                'marcasNuevas' => count($marcasNuevas),
                'lineasNuevas' => count($lineasNuevas),
                'sublineasNuevas' => count($sublineasNuevas),
                'repetidas' => $repetidas,
                'problemas' => $lectura['problemas'],
                'faltantes' => count($faltantesIds),
                'sinUnidad' => ! $lectura['tieneUnidad'],
            ],
            'filas' => $filasPlan,
            'faltantesIds' => $faltantesIds,
        ];
    }
}
