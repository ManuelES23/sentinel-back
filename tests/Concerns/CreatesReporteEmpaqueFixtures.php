<?php

namespace Tests\Concerns;

use App\Models\Cultivo;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Lote;
use App\Models\Productor;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\RecepcionEmpaque;
use App\Models\Temporada;
use App\Models\TipoCarga;
use App\Models\User;
use App\Models\Variedad;
use App\Models\ZonaCultivo;

/**
 * Fixtures para el submódulo Reportes > Empaque: cultivo → temporada →
 * dos productores, más un helper para crear una recepción con su cadena
 * proceso → producción (con o sin desglose por detalle).
 */
trait CreatesReporteEmpaqueFixtures
{
    protected User $actingUser;
    protected Enterprise $enterprise;
    protected Entity $entity;
    protected Cultivo $cultivo;
    protected Temporada $temporada;
    protected Variedad $variedad;
    protected TipoCarga $tipoCarga;
    protected ZonaCultivo $zonaCultivo;
    protected Lote $lote;
    protected Productor $productorPrincipal;
    protected Productor $productorSecundario;

    protected function setUpReporteEmpaqueFixtures(): void
    {
        $this->actingUser = User::factory()->create();

        $this->enterprise = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms',
            'description' => 'Empresa agrícola de prueba',
            'is_active' => true,
        ]);

        $branch = Branch::create([
            'enterprise_id' => $this->enterprise->id,
            'code' => 'SF-MAIN',
            'name' => 'Casa Matriz',
            'slug' => 'casa-matriz',
            'is_active' => true,
            'is_main' => true,
        ]);

        $entityType = EntityType::create([
            'code' => 'PLANTA',
            'name' => 'Planta Empacadora',
            'slug' => 'planta-empacadora',
            'is_active' => true,
        ]);

        $this->entity = Entity::create([
            'branch_id' => $branch->id,
            'entity_type_id' => $entityType->id,
            'code' => 'EMP-001',
            'name' => 'Empaque Principal',
            'slug' => 'empaque-principal',
            'is_active' => true,
        ]);

        $this->cultivo = Cultivo::create(['nombre' => 'Mango']);

        $this->temporada = Temporada::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Mango 2026',
            'locacion' => 'Sinaloa',
            'folio_temporada' => $this->cultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2026,
            'fecha_inicio' => '2026-01-01',
            'fecha_fin' => '2026-06-30',
            'user_id' => $this->actingUser->id,
        ]);

        $this->variedad = Variedad::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Ataulfo',
            'user_id' => $this->actingUser->id,
        ]);

        $this->tipoCarga = TipoCarga::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'Rejas',
            'peso_estimado_kg' => 20,
            'is_active' => true,
        ]);

        $this->productorPrincipal = Productor::create([
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'tipo' => Productor::TIPO_EXTERNO,
            'is_active' => true,
        ]);

        $this->productorSecundario = Productor::create([
            'nombre' => 'María',
            'apellido' => 'López',
            'tipo' => Productor::TIPO_EXTERNO,
            'is_active' => true,
        ]);

        $this->zonaCultivo = ZonaCultivo::create(['nombre' => 'Zona Norte', 'is_active' => true]);

        $this->lote = Lote::create([
            'zona_cultivo_id' => $this->zonaCultivo->id,
            'productor_id' => $this->productorPrincipal->id,
            'numero_lote' => 'L-001',
            'nombre' => 'Lote 1',
            'is_active' => true,
        ]);
    }

    /**
     * Crea una recepción con su proceso y producción ligados (sin desglose
     * por detalle — pallet simple). $overrides sobreescribe campos de
     * RecepcionEmpaque (ej. fecha_recepcion, productor_id, variedad_id).
     */
    protected function crearRecepcionConProduccion(Productor $productor, string $sufijo, array $overrides = []): array
    {
        $recepcion = RecepcionEmpaque::create(array_merge([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'folio_recepcion' => "REC-{$sufijo}",
            'fecha_recepcion' => '2026-02-01',
            'productor_id' => $productor->id,
            'lote_id' => $this->lote->id,
            'variedad_id' => $this->variedad->id,
            'tipo_carga_id' => $this->tipoCarga->id,
            'cantidad_recibida' => 100,
            'peso_recibido_kg' => 1000,
            'peso_bascula' => 1000,
            'status' => 'recibida',
        ], $overrides));

        $proceso = ProcesoEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'recepcion_id' => $recepcion->id,
            'folio_proceso' => "PRO-{$sufijo}",
            'tipo_carga_id' => $this->tipoCarga->id,
            'productor_id' => $productor->id,
            'lote_id' => $this->lote->id,
            'fecha_entrada' => '2026-02-01',
            'status' => 'en_piso',
        ]);

        $produccion = ProduccionEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'folio_produccion' => "PDN-{$sufijo}",
            'fecha_produccion' => '2026-02-02',
            'variedad_id' => $this->variedad->id,
            'total_cajas' => 50,
            'peso_neto_kg' => 500,
            'status' => 'en_almacen',
        ]);

        return compact('recepcion', 'proceso', 'produccion');
    }

    /**
     * Igual que crearRecepcionConProduccion(), pero el pallet queda con
     * desglose por detalle: 30 cajas del proceso de la recepción original y
     * 20 cajas de un proceso de $productorSecundarioDetalle (simula un
     * pallet multi-entrada — proceso_id del pallet sigue NOT NULL).
     */
    protected function crearRecepcionConProduccionMultiEntrada(
        Productor $productorPrincipal,
        string $sufijo,
        Productor $productorSecundarioDetalle,
    ): array {
        $base = $this->crearRecepcionConProduccion($productorPrincipal, $sufijo);

        $recepcionSecundaria = RecepcionEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'folio_recepcion' => "REC-{$sufijo}-B",
            'fecha_recepcion' => '2026-02-01',
            'productor_id' => $productorSecundarioDetalle->id,
            'lote_id' => $this->lote->id,
            'variedad_id' => $this->variedad->id,
            'tipo_carga_id' => $this->tipoCarga->id,
            'cantidad_recibida' => 100,
            'peso_recibido_kg' => 1000,
            'peso_bascula' => 1000,
            'status' => 'recibida',
        ]);

        $procesoSecundario = ProcesoEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'recepcion_id' => $recepcionSecundaria->id,
            'folio_proceso' => "PRO-{$sufijo}-B",
            'tipo_carga_id' => $this->tipoCarga->id,
            'productor_id' => $productorSecundarioDetalle->id,
            'lote_id' => $this->lote->id,
            'fecha_entrada' => '2026-02-01',
            'status' => 'en_piso',
        ]);

        ProduccionEmpaqueDetalle::create([
            'produccion_id' => $base['produccion']->id,
            'numero_entrada' => 1,
            'proceso_id' => $base['proceso']->id,
            'fecha_produccion' => '2026-02-02',
            'total_cajas' => 30,
            'peso_neto_kg' => 300,
        ]);
        ProduccionEmpaqueDetalle::create([
            'produccion_id' => $base['produccion']->id,
            'numero_entrada' => 2,
            'proceso_id' => $procesoSecundario->id,
            'fecha_produccion' => '2026-02-02',
            'total_cajas' => 20,
            'peso_neto_kg' => 200,
        ]);

        return $base + ['recepcionSecundaria' => $recepcionSecundaria, 'procesoSecundario' => $procesoSecundario];
    }
}
