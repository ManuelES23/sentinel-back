<?php

namespace App\Services\CRM\ImportacionCatalogo;

use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmSublinea;
use App\Support\CRM\NombreCatalogo;
use Illuminate\Support\Facades\DB;

/**
 * Aplica el Excel al catálogo de la empresa, todo en una transacción. El Excel manda: pisa nombre, marca,
 * línea, sublínea y (si trae UNIDAD) unidad de los productos que ya existen. Nunca borra productos.
 */
class ImportadorCatalogo
{
    private const LOTE = 500;

    private RevisionCatalogo $revision;

    public function __construct(?RevisionCatalogo $revision = null)
    {
        $this->revision = $revision ?? new RevisionCatalogo();
    }

    /**
     * @param  array<string,int>  $elegidas  clave => fila del Excel para cada grupo repetido
     * @return array resumen aplicado (mismos contadores que la revisión, más `desactivados`)
     */
    public function importar(int $empresaId, array $lectura, array $elegidas, bool $desactivarFaltantes): array
    {
        return DB::transaction(function () use ($empresaId, $lectura, $elegidas, $desactivarFaltantes) {
            $plan = $this->revision->planificar($empresaId, $lectura, $elegidas);
            $ahora = now();

            // Marcas, líneas y sublíneas que faltan (pocas decenas): por modelo, que normaliza los nombres.
            $marcas = CrmMarca::where('empresa_id', $empresaId)->pluck('id', 'nombre_clave')->all();
            $lineas = CrmLinea::where('empresa_id', $empresaId)->pluck('id', 'nombre_clave')->all();
            $sublineas = [];
            foreach (CrmSublinea::whereIn('linea_id', array_values($lineas))->get(['id', 'linea_id', 'nombre_clave']) as $s) {
                $sublineas[$s->linea_id.'|'.$s->nombre_clave] = $s->id;
            }

            foreach ($plan['filas'] as $f) {
                if (! isset($marcas[$f['marcaK']])) {
                    $marcas[$f['marcaK']] = CrmMarca::create(['empresa_id' => $empresaId, 'nombre' => $f['marca'], 'activo' => true])->id;
                }
                if (! isset($lineas[$f['lineaK']])) {
                    $lineas[$f['lineaK']] = CrmLinea::create(['empresa_id' => $empresaId, 'nombre' => $f['linea'], 'activo' => true])->id;
                }
                if ($f['subK'] !== null && ! isset($sublineas[$lineas[$f['lineaK']].'|'.$f['subK']])) {
                    $sublineas[$lineas[$f['lineaK']].'|'.$f['subK']] = CrmSublinea::create([
                        'linea_id' => $lineas[$f['lineaK']], 'nombre' => $f['sublinea'], 'activo' => true,
                    ])->id;
                }
            }

            // Las actualizaciones van por el modelo (Loggable deja el rastro de auditoría); los nuevos, en bloque.
            $porActualizar = CrmProducto::where('empresa_id', $empresaId)
                ->whereIn('id', array_filter(array_column($plan['filas'], 'existente')))
                ->get()->keyBy('id');

            $nuevos = [];
            foreach ($plan['filas'] as $f) {
                if ($f['estado'] === 'sinCambio') {
                    continue;
                }
                $lineaId = $lineas[$f['lineaK']];
                $campos = [
                    'nombre' => NombreCatalogo::limpio($f['producto']),
                    'marca_id' => $marcas[$f['marcaK']],
                    'linea_id' => $lineaId,
                    'sublinea_id' => $f['subK'] === null ? null : $sublineas[$lineaId.'|'.$f['subK']],
                ];
                if ($lectura['tieneUnidad'] && $f['unidad'] !== null) {
                    $campos['unidad_medida'] = $f['unidad'];
                }

                if ($f['estado'] === 'actualizado') {
                    // La clave no cambia: es por la que se empareja.
                    $porActualizar[$f['existente']]->update($campos);

                    continue;
                }

                // Insert en bloque: aquí se calculan las columnas que el modelo normaliza al guardar.
                $clave = NombreCatalogo::limpio($f['clave']);
                $nuevos[] = $campos + [
                    'empresa_id' => $empresaId,
                    'clave' => $clave,
                    'clave_normalizada' => NombreCatalogo::clave($clave),
                    'unidad_medida' => $campos['unidad_medida'] ?? 'pieza',
                    'precio' => 0,
                    'activo' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
            foreach (array_chunk($nuevos, self::LOTE) as $lote) {
                DB::table('crm_productos')->insert($lote);
            }

            $desactivados = 0;
            if ($desactivarFaltantes) {
                foreach (array_chunk($plan['faltantesIds'], self::LOTE) as $ids) {
                    foreach (CrmProducto::where('empresa_id', $empresaId)->whereIn('id', $ids)->get() as $producto) {
                        $producto->update(['activo' => false]);
                        $desactivados++;
                    }
                }
            }

            return $plan['resumen'] + ['desactivados' => $desactivados];
        });
    }
}
