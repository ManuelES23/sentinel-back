<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lista propia de clientes de Planeación (`plan_clientes`) y la liga
 * `programa_necesidades.plan_cliente_id`, con la carga inicial.
 *
 * La carga es idempotente (se puede correr otra vez): (1) los clientes del
 * CRM de Splendid by Porvenir (sin los eliminados) y (2) los nombres que ya
 * usan las necesidades sin liga, por id ascendente. Se deduplica por clave (el
 * primero gana: el del CRM y, si no, el de la necesidad más antigua) y cada
 * necesidad sin liga se liga por clave a su cliente. Las ya ligadas no se
 * tocan ni registran su nombre (tras un renombrado sería el viejo).
 * `cliente_nombre` no se reescribe.
 *
 * La clave es una copia exacta de `ClientesPlaneacion::clave` para que esta
 * migración no cambie si el servicio cambia (una prueba compara las dos).
 */
return new class extends Migration
{
    private const LOTE = 500;

    public function up(): void
    {
        if (! Schema::hasTable('plan_clientes')) {
            Schema::create('plan_clientes', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 255);
                $table->string('nombre_clave', 255)->unique();
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('programa_necesidades', 'plan_cliente_id')) {
            Schema::table('programa_necesidades', function (Blueprint $table) {
                $table->foreignId('plan_cliente_id')->nullable()->after('cliente_nombre')
                    ->constrained('plan_clientes')->nullOnDelete();
            });
        }

        $this->cargar();
    }

    public function down(): void
    {
        if (Schema::hasColumn('programa_necesidades', 'plan_cliente_id')) {
            Schema::table('programa_necesidades', function (Blueprint $table) {
                $table->dropConstrainedForeignId('plan_cliente_id');
            });
        }

        Schema::dropIfExists('plan_clientes');
    }

    /** Copia exacta de `ClientesPlaneacion::clave`. */
    public function clave(string $nombre): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', trim($nombre)));

        return mb_substr(trim(Str::lower(Str::ascii($limpio))), 0, 255);
    }

    private function limpiar(string $nombre): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', trim($nombre))), 0, 255);
    }

    private function cargar(): void
    {
        /** @var array<string, int> $porClave clave => id */
        $porClave = [];
        DB::table('plan_clientes')->orderBy('id')->select(['id', 'nombre_clave'])
            ->chunkById(self::LOTE, function ($filas) use (&$porClave) {
                foreach ($filas as $f) {
                    $porClave[$f->nombre_clave] = (int) $f->id;
                }
            });

        $agregar = function (?string $nombre) use (&$porClave): ?int {
            $clave = $this->clave((string) $nombre);
            if ($clave === '') {
                return null;
            }
            if (! isset($porClave[$clave])) {
                $ahora = now();
                $porClave[$clave] = (int) DB::table('plan_clientes')->insertGetId([
                    'nombre' => $this->limpiar((string) $nombre),
                    'nombre_clave' => $clave,
                    'activo' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            return $porClave[$clave];
        };

        // (1) Clientes del CRM de Splendid by Porvenir.
        if (Schema::hasTable('enterprises') && Schema::hasTable('crm_clientes')) {
            $empresaId = DB::table('enterprises')->where('slug', 'splendidbyporvenir')->value('id');
            if ($empresaId) {
                DB::table('crm_clientes')
                    ->where('empresa_id', $empresaId)
                    ->when(Schema::hasColumn('crm_clientes', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->select(['id', 'nombre'])
                    ->chunkById(self::LOTE, function ($filas) use ($agregar) {
                        foreach ($filas as $f) {
                            $agregar($f->nombre);
                        }
                    });
            }
        }

        // (2) Nombres de las necesidades SIN liga, de la más antigua a la más nueva, y la liga.
        // Una necesidad ya ligada no registra su nombre: tras un renombrado su
        // texto puede ser el viejo y volvería a aparecer como cliente nuevo.
        DB::table('programa_necesidades')
            ->whereNull('plan_cliente_id')
            ->select(['id', 'cliente_nombre'])
            ->chunkById(self::LOTE, function ($filas) use ($agregar) {
                /** @var array<int, list<int>> $ligas plan_cliente_id => ids de necesidades */
                $ligas = [];
                foreach ($filas as $f) {
                    $planId = $agregar($f->cliente_nombre);
                    if ($planId !== null) {
                        $ligas[$planId][] = (int) $f->id;
                    }
                }
                foreach ($ligas as $planId => $ids) {
                    DB::table('programa_necesidades')
                        ->whereIn('id', $ids)
                        ->whereNull('plan_cliente_id')
                        ->update(['plan_cliente_id' => $planId]);
                }
            });
    }
};
