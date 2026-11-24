<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\Cultivo;
use App\Models\Module;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use App\Models\SalidaRezagaEmpaque;
use App\Models\SalidaRezagaEmpaqueDetalle;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\Temporada;
use App\Models\TipoCarga;
use App\Models\UserSubmodulePermission;
use App\Models\Variedad;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixtures de Elote Mochis en bins: un segundo cultivo («Elote») con su
 * temporada que opera en bins, los cuatro tipos de carga del flujo (bin de
 * campo y las tres clases de bin de rezaga) y un proceso de empaque en curso.
 * Se suma a los de CreatesReporteEmpaqueFixtures, que sigue siendo la
 * temporada «normal» (Mango, sin bins) para comparar que no cambia.
 */
trait CreatesEloteBinsFixtures
{
    use CreatesReporteEmpaqueFixtures;

    protected Cultivo $cultivoElote;
    protected Temporada $temporadaBins;
    protected Variedad $variedadElote;
    protected TipoCarga $binCampo;
    protected TipoCarga $binHoja;
    protected TipoCarga $binFresco;
    protected TipoCarga $binCuartoFrio;

    private int $contadorBins = 0;

    protected function setUpEloteBinsFixtures(): void
    {
        $this->setUpReporteEmpaqueFixtures();
        $this->relajarEnumsDeRezagaEnSqlite();

        $this->cultivoElote = Cultivo::create(['nombre' => 'Elote']);

        $this->temporadaBins = Temporada::create([
            'cultivo_id' => $this->cultivoElote->id,
            'nombre' => 'Elote Mochis 2026',
            'locacion' => 'Mochis',
            'folio_temporada' => $this->cultivoElote->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'opera_en_bins' => true,
            'user_id' => $this->actingUser->id,
        ]);

        $this->variedadElote = Variedad::create([
            'cultivo_id' => $this->cultivoElote->id,
            'nombre' => 'Elote blanco',
            'user_id' => $this->actingUser->id,
        ]);

        $this->binCampo = $this->crearTipoCargaBin('Bin de campo', 400, null);
        $this->binHoja = $this->crearTipoCargaBin('Bin de hoja', 150, 'hoja');
        $this->binFresco = $this->crearTipoCargaBin('Bin de fresco', 300, 'fresco');
        $this->binCuartoFrio = $this->crearTipoCargaBin('Bin de cuarto frío', 250, 'cuarto_frio');
    }

    private function crearTipoCargaBin(string $nombre, float $pesoKg, ?string $clase): TipoCarga
    {
        return TipoCarga::create([
            'cultivo_id' => $this->cultivoElote->id,
            'nombre' => $nombre,
            'peso_estimado_kg' => $pesoKg,
            'clase_rezaga' => $clase,
            'is_active' => true,
        ]);
    }

    /**
     * Proceso de empaque en curso de la temporada en bins, con su recepción
     * (bins de campo) y $bins disponibles para repartir en rezaga.
     */
    protected function crearProcesoBins(int $bins = 10): ProcesoEmpaque
    {
        $this->contadorBins++;
        $n = $this->contadorBins;

        $recepcion = RecepcionEmpaque::create([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'folio_recepcion' => "REC-BIN-{$n}",
            'fecha_recepcion' => '2026-02-01',
            'productor_id' => $this->productorPrincipal->id,
            'lote_id' => $this->lote->id,
            'variedad_id' => $this->variedadElote->id,
            'tipo_carga_id' => $this->binCampo->id,
            'cantidad_recibida' => $bins,
            'peso_recibido_kg' => $bins * 400,
            'peso_bascula' => $bins * 400,
            'status' => 'recibida',
        ]);

        return ProcesoEmpaque::create([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'recepcion_id' => $recepcion->id,
            'folio_proceso' => "PRO-BIN-{$n}",
            'tipo_carga_id' => $this->binCampo->id,
            'productor_id' => $this->productorPrincipal->id,
            'lote_id' => $this->lote->id,
            'cantidad_entrada' => $bins,
            'cantidad_disponible' => $bins,
            'peso_entrada_kg' => $bins * 400,
            'peso_disponible_kg' => $bins * 400,
            'fecha_entrada' => '2026-02-01',
            'status' => 'en_proceso',
        ]);
    }

    /**
     * Pallet de producción de la temporada en bins (fuera de cuarto frío y
     * sin peso de báscula), colgado de un proceso nuevo.
     */
    protected function crearProduccionBins(array $overrides = []): ProduccionEmpaque
    {
        $proceso = $this->crearProcesoBins();
        $this->contadorBins++;

        return ProduccionEmpaque::create(array_merge([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'folio_produccion' => 'PDN-BIN-'.$this->contadorBins,
            'fecha_produccion' => '2026-02-02',
            'variedad_id' => $this->variedadElote->id,
            'total_cajas' => 50,
            'peso_neto_kg' => 500,
            'status' => 'en_almacen',
            'en_cuarto_frio' => false,
        ], $overrides));
    }

    /**
     * Solo para el esquema de pruebas (SQLite): las migraciones que amplían
     * `rezaga_empaque.tipo_rezaga` a produccion/cuarto_frio/lavado/hidrotermico
     * corren únicamente en MySQL, así que en SQLite el enum sigue siendo el
     * CHECK original (descarte/merma/segunda/basura). Se relaja a string para
     * poder crear rezagas de bins. No toca ninguna migración ni el enum de MySQL.
     */
    private function relajarEnumsDeRezagaEnSqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        Schema::table('rezaga_empaque', function (Blueprint $table) {
            $table->string('tipo_rezaga', 20)->default('produccion')->change();
        });
    }

    /**
     * Crea una rezaga directamente en BD. Por defecto: 3 bins de hoja
     * (450 kg estimados) del proceso dado, pendiente.
     */
    protected function crearRezagaBins(ProcesoEmpaque $proceso, array $overrides = []): RezagaEmpaque
    {
        $this->contadorBins++;

        return RezagaEmpaque::create(array_merge([
            'temporada_id' => $proceso->temporada_id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'folio_rezaga' => 'REZ-TEST-'.str_pad((string) $this->contadorBins, 4, '0', STR_PAD_LEFT),
            'tipo_rezaga' => 'produccion',
            'subtipo_rezaga' => 'hoja',
            'fecha' => '2026-02-02',
            'tipo_carga_id' => $this->binHoja->id,
            'cantidad_bins' => 3,
            'cantidad_kg' => 450,
            'modo_registro' => 'general',
            'status' => 'pendiente',
        ], $overrides));
    }

    /**
     * Concede al usuario de pruebas los permisos del submódulo `salida-rezaga`
     * (el controlador los lee de user_submodule_permissions).
     */
    protected function concederPermisosSalidaRezaga(array $permisos = ['view', 'create', 'edit', 'delete']): void
    {
        $app = Application::firstOrCreate(
            ['enterprise_id' => $this->enterprise->id, 'slug' => 'operacion-agricola'],
            ['name' => 'Operación Agrícola', 'path' => '/splendidfarms/operacion-agricola', 'description' => 'operacion-agricola'],
        );
        $modulo = Module::firstOrCreate(['application_id' => $app->id, 'slug' => 'empaque'], ['name' => 'Empaque']);
        $sub = Submodule::firstOrCreate(['module_id' => $modulo->id, 'slug' => 'salida-rezaga'], ['name' => 'Salida de rezaga']);

        foreach ($permisos as $slug) {
            $tipo = SubmodulePermissionType::firstOrCreate(
                ['submodule_id' => $sub->id, 'slug' => $slug],
                ['name' => ucfirst($slug), 'is_active' => true],
            );
            UserSubmodulePermission::firstOrCreate(
                ['user_id' => $this->actingUser->id, 'submodule_id' => $sub->id, 'permission_type_id' => $tipo->id],
                ['is_granted' => true],
            );
        }
    }

    /**
     * Venta de rezaga con un renglón que consume $kg de la rezaga dada (y $bins
     * cuando es una venta en bins). No toca la rezaga: quien la use decide su restante.
     */
    protected function crearVentaDeRezaga(RezagaEmpaque $rezaga, float $kg, array $overrides = [], ?int $bins = null): SalidaRezagaEmpaqueDetalle
    {
        $this->contadorBins++;

        $venta = SalidaRezagaEmpaque::create(array_merge([
            'temporada_id' => $rezaga->temporada_id,
            'entity_id' => $this->entity->id,
            'folio_venta' => 'VTA-TEST-'.str_pad((string) $this->contadorBins, 4, '0', STR_PAD_LEFT),
            'comprador' => 'Comprador de prueba',
            'fecha_venta' => '2026-02-03',
            'total_peso_kg' => $kg,
        ], $overrides));

        return SalidaRezagaEmpaqueDetalle::create(array_merge([
            'venta_rezaga_id' => $venta->id,
            'rezaga_id' => $rezaga->id,
            'peso_kg' => $kg,
            'precio_kg' => 0,
            'monto' => 0,
        ], $bins === null ? [] : ['bins' => $bins]));
    }
}
