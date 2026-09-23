<?php

namespace App\Services\Inventory;

use App\Models\Brand;
use App\Models\Enterprise;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Alta de insumos agrícolas (agroquímicos y fertilizantes) en el catálogo de
 * artículos. La usan el comando de migración de productos de Aplicaciones y
 * el registro rápido desde el formulario de Aplicaciones.
 */
class CatalogoAgricolaService
{
    public const CATEGORIAS = ['agroquimico' => 'Agroquímicos', 'fertilizante' => 'Fertilizantes'];
    public const UNIDADES = ['agroquimico' => 'LT', 'fertilizante' => 'KG'];

    public static function normalizar(?string $texto): string
    {
        $t = Str::ascii(mb_strtolower(trim((string) $texto)));

        return (string) preg_replace('/\s+/', ' ', $t);
    }

    /**
     * Siguiente código PREFIJO-000N calculado en PHP (independiente del motor de BD).
     */
    public static function siguienteCodigo(string $modelo, string $prefijo, int $digitos): string
    {
        $query = $modelo::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelo), true)) {
            $query->withTrashed();
        }

        $maximo = $query->where('code', 'like', $prefijo . '-%')
            ->pluck('code')
            ->map(fn ($code) => (int) substr($code, strlen($prefijo) + 1))
            ->max() ?? 0;

        return $prefijo . '-' . str_pad((string) ($maximo + 1), $digitos, '0', STR_PAD_LEFT);
    }

    public function categoria(Enterprise $empresa, string $tipo): ProductCategory
    {
        $nombre = self::CATEGORIAS[$tipo];

        $categoria = ProductCategory::whereHas('enterprises', fn ($q) => $q->where('enterprises.id', $empresa->id))
            ->get()
            ->first(fn ($c) => self::normalizar($c->name) === self::normalizar($nombre));

        if (! $categoria) {
            $categoria = ProductCategory::create([
                'code' => self::siguienteCodigo(ProductCategory::class, 'CAT', 3),
                'name' => $nombre,
                'is_active' => true,
            ]);
            $categoria->enterprises()->attach($empresa->id);
        }

        return $categoria;
    }

    public function unidad(Enterprise $empresa, string $tipo): UnitOfMeasure
    {
        $codigo = self::UNIDADES[$tipo];
        $unidad = UnitOfMeasure::where('code', $codigo)->first();

        if (! $unidad) {
            throw new RuntimeException("No existe la unidad de medida {$codigo}.");
        }

        $unidad->enterprises()->syncWithoutDetaching([$empresa->id]);

        return $unidad;
    }

    public function marcaExistente(Enterprise $empresa, ?string $nombre): ?Brand
    {
        if (self::normalizar($nombre) === '') {
            return null;
        }

        return Brand::whereHas('enterprises', fn ($q) => $q->where('enterprises.id', $empresa->id))
            ->get()
            ->first(fn ($b) => self::normalizar($b->name) === self::normalizar($nombre));
    }

    public function marca(Enterprise $empresa, ?string $nombre): ?Brand
    {
        if (self::normalizar($nombre) === '') {
            return null;
        }

        $marca = $this->marcaExistente($empresa, $nombre);
        if (! $marca) {
            $marca = Brand::create([
                'code' => self::siguienteCodigo(Brand::class, 'MRC', 3),
                'name' => trim((string) $nombre),
                'is_active' => true,
            ]);
            $marca->enterprises()->attach($empresa->id);
        }

        return $marca;
    }

    /**
     * @return Collection<int, Product>
     */
    public function buscarDuplicados(Enterprise $empresa, string $nombre, ?Brand $marca): Collection
    {
        $objetivo = self::normalizar($nombre);

        return Product::forEnterprise($empresa->id)
            ->get(['products.id', 'products.name', 'products.brand_id'])
            ->filter(fn ($p) => self::normalizar($p->name) === $objetivo)
            ->filter(fn ($p) => ! $marca || ! $p->brand_id || (int) $p->brand_id === (int) $marca->id)
            ->values();
    }

    public function crearProducto(Enterprise $empresa, array $datos): Product
    {
        $tipo = $datos['tipo'];
        $esAgroquimico = $tipo === 'agroquimico';

        $producto = Product::create([
            'code' => self::siguienteCodigo(Product::class, 'PROD', 5),
            'name' => trim($datos['nombre']),
            'ingrediente_activo' => $datos['ingrediente_activo'] ?? null,
            'brand_id' => $this->marca($empresa, $datos['marca'] ?? null)?->id,
            'category_id' => $this->categoria($empresa, $tipo)->id,
            'unit_id' => $this->unidad($empresa, $tipo)->id,
            'product_type' => 'consumable',
            'track_inventory' => true,
            'track_lots' => $esAgroquimico,
            'track_expiry' => $esAgroquimico,
            'is_for_sale' => false,
            'is_active' => (bool) ($datos['activo'] ?? true),
            'requiere_revision' => true,
        ]);

        $producto->enterprises()->syncWithoutDetaching([$empresa->id]);

        return $producto;
    }

    public function tipoDe(Product $producto): ?string
    {
        $categoria = self::normalizar($producto->category?->name);

        foreach (self::CATEGORIAS as $tipo => $nombre) {
            if (self::normalizar($nombre) === $categoria) {
                return $tipo;
            }
        }

        return null;
    }
}
