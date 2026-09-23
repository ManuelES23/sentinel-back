<?php

namespace App\Console\Commands;

use App\Models\AplicacionDetalle;
use App\Models\Enterprise;
use App\Models\ProductoAplicacion;
use App\Models\UnitOfMeasure;
use App\Services\Inventory\CatalogoAgricolaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Copia los productos del catálogo propio de Aplicaciones al catálogo de
 * artículos de una empresa. Idempotente: solo procesa filas sin product_id.
 * Con --dry-run muestra el reporte y revierte todo.
 */
class MigrarProductosAplicacion extends Command
{
    protected $signature = 'agro:migrar-productos-aplicacion {--empresa=splendidfarms : Slug de la empresa} {--dry-run : Solo mostrar el reporte}';

    protected $description = 'Migra productos_aplicacion al catálogo de artículos (products)';

    public function handle(CatalogoAgricolaService $catalogo): int
    {
        $empresa = Enterprise::where('slug', $this->option('empresa'))->first();
        if (! $empresa) {
            $this->error('No existe la empresa ' . $this->option('empresa'));

            return self::FAILURE;
        }

        foreach (CatalogoAgricolaService::UNIDADES as $codigo) {
            if (! UnitOfMeasure::where('code', $codigo)->exists()) {
                $this->error("No existe la unidad de medida {$codigo}; no se escribió nada.");

                return self::FAILURE;
            }
        }

        $reporte = ['creados' => 0, 'enlazados' => 0, 'ambiguos' => [], 'detalles' => 0];
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            ProductoAplicacion::whereNull('product_id')->orderBy('id')->get()
                ->each(function (ProductoAplicacion $pa) use ($catalogo, $empresa, &$reporte) {
                    $marca = $catalogo->marcaExistente($empresa, $pa->marca);
                    $coincidencias = $catalogo->buscarDuplicados($empresa, $pa->nombre, $marca);

                    if ($coincidencias->count() > 1) {
                        $reporte['ambiguos'][] = $pa->nombre;

                        return;
                    }

                    if ($coincidencias->isEmpty()) {
                        $producto = $catalogo->crearProducto($empresa, $pa->only(['nombre', 'ingrediente_activo', 'marca', 'tipo', 'activo']));
                        $reporte['creados']++;
                    } else {
                        $producto = $coincidencias->first();
                        $reporte['enlazados']++;
                    }

                    $pa->update(['product_id' => $producto->id]);
                    $reporte['detalles'] += AplicacionDetalle::where('producto_id', $pa->id)
                        ->whereNull('product_id')
                        ->update(['product_id' => $producto->id]);
                });

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Concepto', 'Cantidad'], [
            ['Artículos creados', $reporte['creados']],
            ['Enlazados a artículos existentes', $reporte['enlazados']],
            ['Ambiguos (sin cambios)', count($reporte['ambiguos'])],
            ['Detalles de aplicaciones actualizados', $reporte['detalles']],
        ]);

        foreach ($reporte['ambiguos'] as $nombre) {
            $this->warn("Ambiguo: {$nombre} (varios artículos con ese nombre; enlazar a mano en productos_aplicacion.product_id)");
        }

        if ($dryRun) {
            $this->info('Modo prueba: no se guardó ningún cambio.');
        }

        return self::SUCCESS;
    }
}
