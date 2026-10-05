<?php

namespace App\Console\Commands;

use App\Models\AssetCategory;
use App\Models\Brand;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\FixedAsset;
use App\Services\ActivosFijos\GeneradorCodigoActivo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa el catálogo de Activos Fijos exportado de Sentinel 2.0 (Excel)
 * hacia el módulo nuevo de Activos Fijos.
 *
 * Columnas esperadas (fila 2 = encabezados):
 *   A: Código | B: Imagen | C: Nombre | D: Num. Serie | E: Entidad
 *   F: Tipo Activo | G: Subtipo Activo | H: Marca | I: Estado
 *   J: Fecha Compra (texto relativo, ej. "hace 3 años") | K: Observaciones
 *
 * Todo se importa dentro de UNA empresa (--empresa=slug): los activos quedan con
 * su enterprise_id, las entidades se buscan solo entre las sucursales de esa
 * empresa y las marcas nuevas se asocian a ella. Las filas sin código reciben
 * el siguiente consecutivo de la empresa (con su prefijo).
 *
 * Idempotente: usa el "Código" original como `code` en fixed_assets, así
 * que volver a correr el comando actualiza en vez de duplicar.
 *
 * Uso:
 *   php artisan assets:import-legacy "C:\ruta\Activos.xlsx" --empresa=splendidfarms
 *   php artisan assets:import-legacy "C:\ruta\Activos.xlsx" --empresa=splendidfarms --dry-run
 */
class ImportLegacyFixedAssets extends Command
{
    protected $signature = 'assets:import-legacy
        {path : Ruta al archivo .xlsx exportado de Sentinel 2.0}
        {--empresa= : Slug de la empresa dueña de los activos (splendidfarms, splendidbyporvenir, grupoesplendido)}
        {--dry-run : Solo mostrar qué se haría, sin escribir en la base de datos}';

    protected $description = 'Importa activos fijos desde el Excel de Sentinel 2.0';

    /** Texto de Estado (Excel) => slug de estado en el sistema nuevo */
    private const STATUS_MAP = [
        'disponible' => 'disponible',
        'en uso' => 'en_uso',
        'mantenimiento' => 'en_mantenimiento',
        'en mantenimiento' => 'en_mantenimiento',
        'fuera de servicio' => 'fuera_de_servicio',
        'baja' => 'baja',
        'resguardo' => 'resguardo',
        'en resguardo' => 'resguardo',
    ];

    /** Nombre de "Entidad" en el Excel => nombre real de Entity en el sistema */
    private const ENTITY_MAP = [
        'los mochis' => 'Oficina Mochis',
        'charay' => 'Empaque Charay',
    ];

    private array $stats = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'categories_created' => 0,
        'brands_created' => 0,
        'dates_unknown' => 0,
    ];

    private array $warnings = [];

    private ?Enterprise $empresa = null;

    public function handle(): int
    {
        $slug = $this->option('empresa');
        if (! $slug) {
            $this->error('Indica la empresa dueña de los activos con --empresa=slug.');
            return self::FAILURE;
        }

        $this->empresa = Enterprise::where('slug', $slug)->first();
        if (! $this->empresa) {
            $this->error("La empresa '{$slug}' no existe.");
            return self::FAILURE;
        }

        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (!is_file($path)) {
            $this->error("No se encontró el archivo: {$path}");
            return self::FAILURE;
        }

        $this->info($dryRun ? '🔎 Modo simulación (dry-run), no se escribirá nada...' : '🚚 Importando activos fijos...');

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $seenCodes = [];

        foreach ($rows as $idx => $row) {
            if ($idx <= 2) {
                continue; // fila 1: título, fila 2: encabezados
            }

            $codigo = trim((string) ($row['A'] ?? ''));
            $nombre = trim((string) ($row['C'] ?? ''));
            if ($codigo === '' && $nombre === '') {
                continue; // fila vacía
            }

            // El Excel trae códigos duplicados por error de captura en el
            // sistema anterior; si no los distinguimos, la segunda fila
            // sobrescribiría a la primera al hacer match por "code".
            if ($codigo !== '') {
                if (isset($seenCodes[$codigo])) {
                    $seenCodes[$codigo]++;
                    $original = $codigo;
                    $codigo = $codigo.'-'.$seenCodes[$codigo];
                    $this->warnings[] = "Fila {$idx}: código '{$original}' estaba duplicado en el Excel (equipo distinto); se importó como '{$codigo}'. Revísalo y corrígelo si quieres un código propio.";
                } else {
                    $seenCodes[$codigo] = 1;
                }
            }

            $this->importRow($idx, [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'serie' => trim((string) ($row['D'] ?? '')),
                'entidad' => trim((string) ($row['E'] ?? '')),
                'tipo' => trim((string) ($row['F'] ?? '')),
                'subtipo' => trim((string) ($row['G'] ?? '')),
                'marca' => trim((string) ($row['H'] ?? '')),
                'estado' => trim((string) ($row['I'] ?? '')),
                'fecha' => trim((string) ($row['J'] ?? '')),
                'observaciones' => trim((string) ($row['K'] ?? '')),
            ], $dryRun);
        }

        $this->newLine();
        $this->info('✅ Importación terminada');
        $this->table(['Métrica', 'Cantidad'], [
            ['Activos creados', $this->stats['created']],
            ['Activos actualizados', $this->stats['updated']],
            ['Filas omitidas', $this->stats['skipped']],
            ['Tipos/Subtipos nuevos creados', $this->stats['categories_created']],
            ['Marcas nuevas creadas', $this->stats['brands_created']],
            ['Fechas de compra no interpretables', $this->stats['dates_unknown']],
        ]);

        if (!empty($this->warnings)) {
            $this->newLine();
            $this->warn('Avisos:');
            foreach (array_unique($this->warnings) as $w) {
                $this->line("  - {$w}");
            }
        }

        return self::SUCCESS;
    }

    private function importRow(int $idx, array $r, bool $dryRun): void
    {
        // Resolver entidad (obligatoria)
        $entityKey = mb_strtolower($r['entidad']);
        $entityRealName = self::ENTITY_MAP[$entityKey] ?? null;
        $entity = $entityRealName
            ? Entity::where('name', $entityRealName)
                ->whereHas('branch', fn ($q) => $q->where('enterprise_id', $this->empresa->id))
                ->first()
            : null;

        if (!$entity) {
            $this->warnings[] = "Fila {$idx} ({$r['codigo']}): entidad '{$r['entidad']}' no reconocida, fila omitida.";
            $this->stats['skipped']++;
            return;
        }

        // Resolver / crear Tipo y Subtipo de Activo
        $category = $r['tipo'] !== '' ? $this->findOrCreateCategory($r['tipo'], null, $dryRun) : null;
        $subcategory = ($r['subtipo'] !== '' && $category)
            ? $this->findOrCreateCategory($r['subtipo'], $category->id, $dryRun)
            : null;

        // Resolver / crear Marca
        $brand = $r['marca'] !== '' ? $this->findOrCreateBrand($r['marca'], $dryRun) : null;

        // Estado
        $statusKey = mb_strtolower($r['estado']);
        $status = self::STATUS_MAP[$statusKey] ?? 'en_uso';
        if ($r['estado'] !== '' && !isset(self::STATUS_MAP[$statusKey])) {
            $this->warnings[] = "Fila {$idx} ({$r['codigo']}): estado '{$r['estado']}' desconocido, se usó 'en_uso'.";
        }

        // Fecha de compra aproximada a partir del texto relativo
        $purchaseDate = $this->parseRelativeDate($r['fecha']);
        if ($r['fecha'] !== '' && $purchaseDate === null) {
            $this->stats['dates_unknown']++;
        }

        $payload = [
            'enterprise_id' => $this->empresa->id,
            'name' => $r['nombre'],
            'serial_number' => $r['serie'] ?: null,
            'entity_id' => $entity->id,
            'branch_id' => $entity->branch_id,
            'category_id' => $category?->id,
            'subcategory_id' => $subcategory?->id,
            'brand_id' => $brand?->id,
            'status' => $status,
            'purchase_date' => $purchaseDate,
            'observations' => $r['observaciones'] !== '' && $r['observaciones'] !== '-' ? $r['observaciones'] : null,
            'is_active' => true,
        ];

        if ($dryRun) {
            $this->line(sprintf(
                '[%s] %s -> %s | %s / %s | %s | %s | %s | compra: %s',
                $r['codigo'] !== '' ? $r['codigo'] : '(código automático)',
                $entity->name,
                $r['nombre'],
                $category->name ?? '-',
                $subcategory->name ?? '-',
                $brand->name ?? '-',
                $status,
                $r['serie'] ?: 's/n',
                $purchaseDate ?? 'desconocida',
            ));
            return;
        }

        $existing = $r['codigo'] !== ''
            ? FixedAsset::withTrashed()->where('code', $r['codigo'])->first()
            : null;
        if ($existing) {
            $existing->update($payload);
            $this->stats['updated']++;
        } else {
            if ($r['codigo'] !== '') {
                $payload['code'] = $r['codigo'];
                FixedAsset::create($payload);
            } else {
                // Sin código en el Excel: consecutivo de la empresa, reservado
                // y usado dentro de la misma transacción.
                DB::transaction(function () use ($payload) {
                    $payload['code'] = app(GeneradorCodigoActivo::class)->siguiente($this->empresa);
                    FixedAsset::create($payload);
                });
            }
            $this->stats['created']++;
        }
    }

    private function findOrCreateCategory(string $name, ?int $parentId, bool $dryRun): ?AssetCategory
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $existing = AssetCategory::where('name', $name)->where('parent_id', $parentId)->first();
        if ($existing) {
            return $existing;
        }

        if ($dryRun) {
            $fake = new AssetCategory(['name' => $name, 'parent_id' => $parentId]);
            $fake->id = null;
            return $fake;
        }

        $parentIcon = $parentId ? AssetCategory::find($parentId)?->icon : null;

        $category = AssetCategory::create([
            'code' => DB::transaction(fn () => app(GeneradorCodigoActivo::class)->siguienteTipo()),
            'name' => $name,
            'parent_id' => $parentId,
            'icon' => $parentIcon ?: 'Package',
            'is_active' => true,
        ]);

        $this->stats['categories_created']++;

        return $category;
    }

    private function findOrCreateBrand(string $name, bool $dryRun): ?Brand
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $existing = Brand::forEnterprise($this->empresa->id)->whereRaw('LOWER(brands.name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing;
        }

        if ($dryRun) {
            $fake = new Brand(['name' => $name]);
            $fake->id = null;
            return $fake;
        }

        $prefix = 'MRC';
        $last = Brand::withTrashed()
            ->where('code', 'like', $prefix.'-%')
            ->orderByRaw('CAST(SUBSTRING(code, '.(strlen($prefix) + 2).') AS UNSIGNED) DESC')
            ->first();
        $next = $last ? ((int) substr($last->code, strlen($prefix) + 1)) + 1 : 1;

        $brand = Brand::create([
            'code' => $prefix.'-'.str_pad($next, 3, '0', STR_PAD_LEFT),
            'name' => $name,
            'is_active' => true,
        ]);

        $brand->enterprises()->syncWithoutDetaching([$this->empresa->id]);

        $this->stats['brands_created']++;

        return $brand;
    }

    /**
     * Convierte texto relativo en español ("hace 3 años", "hace 6 meses")
     * en una fecha aproximada. Valores absurdos (ej. "hace 1825 años", que
     * es basura heredada del sistema anterior) se descartan como null.
     */
    private function parseRelativeDate(string $text): ?string
    {
        $text = mb_strtolower(trim($text));
        if ($text === '' || $text === '-') {
            return null;
        }

        if (preg_match('/hace\s+(\d+)\s+a[nñ]o/u', $text, $m)) {
            $years = (int) $m[1];
            if ($years >= 100) {
                return null; // dato basura (ej. "hace 1825 años")
            }
            return now()->subYears($years)->toDateString();
        }

        if (preg_match('/hace\s+(\d+)\s+mes/u', $text, $m)) {
            return now()->subMonths((int) $m[1])->toDateString();
        }

        return null;
    }
}
