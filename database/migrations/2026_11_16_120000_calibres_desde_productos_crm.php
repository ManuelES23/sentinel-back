<?php

use App\Services\Abastecimiento\EnlaceProductosCrm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los calibres de Splendid by Porvenir dejan de depender de Splendid Farms:
 *
 *  1. Los calibres que el CRM leía en vivo de Splendid Farms (`calibres`, por `crm_productos.variedad_id`)
 *     se copian UNA vez como calibres propios del producto (`crm_producto_calibres`), con su orden y si
 *     están activos; los que el producto ya tenía con ese nombre se respetan. Desde aquí el CRM solo lee
 *     los propios.
 *  2. Abastecimiento ya no tiene catálogo de calibres (`abs_variedad_calibres`): cada variedad se liga a un
 *     producto del CRM (`abs_variedades.producto_id`, propuesto por nombre) y sus calibres son los de ese
 *     producto. Las llaves `calibre_id` de convenios, precios, metas, liquidaciones y recepciones pasan a
 *     apuntar a `crm_producto_calibres`.
 *
 * Se detiene si `abs_variedad_calibres` ya tiene filas: sus ids no existen en `crm_producto_calibres`.
 */
return new class extends Migration
{
    /** @var list<string> tablas con `calibre_id` */
    private const TABLAS = [
        'abs_convenio_calibres',
        'abs_convenio_precios',
        'abs_convenio_metas',
        'abs_liquidacion_detalles',
        'abs_recepcion_calibres',
    ];

    public function up(): void
    {
        if (Schema::hasTable('abs_variedad_calibres') && DB::table('abs_variedad_calibres')->exists()) {
            throw new RuntimeException('abs_variedad_calibres tiene filas: sus ids no existen en crm_producto_calibres. Pásalas a mano a los calibres del producto del CRM antes de migrar.');
        }

        $this->copiarCalibresDeSplendidFarms();

        if (! Schema::hasColumn('abs_variedades', 'producto_id')) {
            Schema::table('abs_variedades', function (Blueprint $table) {
                $table->unsignedBigInteger('producto_id')->nullable()->after('cultivo_id');
                $table->foreign('producto_id')->references('id')->on('crm_productos')->nullOnDelete();
            });
        }

        $this->repuntar('crm_producto_calibres');
        Schema::dropIfExists('abs_variedad_calibres');

        EnlaceProductosCrm::aplicar(true);
    }

    /** Solo es seguro antes de que existan convenios por calibre. Los calibres copiados al CRM se quedan. */
    public function down(): void
    {
        if (! Schema::hasTable('abs_variedad_calibres')) {
            Schema::create('abs_variedad_calibres', function (Blueprint $table) {
                $table->id();
                $table->foreignId('variedad_id')->constrained('abs_variedades')->cascadeOnDelete();
                $table->string('nombre', 60);
                $table->unsignedInteger('orden')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['variedad_id', 'nombre'], 'abs_variedad_calibres_variedad_nombre_unique');
            });
        }

        $this->repuntar('abs_variedad_calibres');

        if (Schema::hasColumn('abs_variedades', 'producto_id')) {
            Schema::table('abs_variedades', function (Blueprint $table) {
                $table->dropForeign(['producto_id']);
                $table->dropColumn('producto_id');
            });
        }
    }

    private function repuntar(string $destino): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'calibre_id')) {
                continue;
            }
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['calibre_id']);
            });
            Schema::table($tabla, function (Blueprint $table) use ($destino) {
                $table->foreign('calibre_id')->references('id')->on($destino);
            });
        }
    }

    private function copiarCalibresDeSplendidFarms(): void
    {
        if (! Schema::hasColumn('crm_productos', 'variedad_id') || ! Schema::hasTable('calibres')) {
            return;
        }

        $productos = DB::table('crm_productos')->whereNotNull('variedad_id')->get(['id', 'variedad_id']);
        foreach ($productos as $producto) {
            $existentes = DB::table('crm_producto_calibres')->where('producto_id', $producto->id)->pluck('nombre')
                ->mapWithKeys(fn ($n) => [mb_strtolower(trim($n)) => true]);
            $agricolas = DB::table('calibres')->where('variedad_id', $producto->variedad_id)->whereNull('deleted_at')
                ->orderBy('order')->orderBy('nombre')->get(['nombre', 'order', 'is_active']);

            foreach ($agricolas as $calibre) {
                $nombre = trim((string) $calibre->nombre);
                $clave = mb_strtolower($nombre);
                if ($nombre === '' || isset($existentes[$clave])) {
                    continue;
                }
                DB::table('crm_producto_calibres')->insert([
                    'producto_id' => $producto->id,
                    'nombre' => mb_substr($nombre, 0, 100),
                    'orden' => min((int) $calibre->order, 32767),
                    'activo' => (bool) $calibre->is_active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $existentes[$clave] = true;
            }
        }
    }
};
