<?php

namespace Tests\Concerns;

use App\Models\Cultivo;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\EmbarqueEmpaque;
use App\Models\EmbarqueEmpaqueDetalle;
use App\Models\Lote;
use App\Models\Productor;
use App\Models\ProcesoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\RecepcionEmpaque;
use App\Models\RezagaEmpaque;
use App\Models\SalidaCampoCosecha;
use App\Models\Temporada;
use App\Models\TipoCarga;
use App\Models\User;
use App\Models\Variedad;
use App\Models\ZonaCultivo;

/**
 * Fixtures para el submódulo Reportes > Productores: cultivo → temporada →
 * dos productores (principal y secundario), más un helper para crear una
 * cadena completa de movimientos (salida → recepción → proceso → producción
 * → embarque + rezaga) para cualquiera de los dos.
 */
trait CreatesReporteProductoresFixtures
{
    protected User $actingUser;
    protected Enterprise $enterprise;
    protected Entity $entity;
    protected Cultivo $cultivo;
    protected Temporada $temporada;
    protected Variedad $variedad;
    protected TipoCarga $tipoCarga;
    protected Productor $productorPrincipal;
    protected Productor $productorSecundario;
    protected ZonaCultivo $zonaCultivo;
    protected Lote $lote;

    protected function setUpReporteProductoresFixtures(): void
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

        // ZonaCultivo requiere productor_id (NOT NULL) pero no está en su
        // $fillable — se asigna directo para no depender de mass-assignment.
        $this->zonaCultivo = new ZonaCultivo(['nombre' => 'Zona Norte', 'is_active' => true]);
        $this->zonaCultivo->productor_id = $this->productorPrincipal->id;
        $this->zonaCultivo->save();

        $this->lote = Lote::create([
            'zona_cultivo_id' => $this->zonaCultivo->id,
            'productor_id' => $this->productorPrincipal->id,
            'numero_lote' => 'L-001',
            'nombre' => 'Lote 1',
            'is_active' => true,
        ]);

        $this->temporada->productores()->attach(
            [$this->productorPrincipal->id, $this->productorSecundario->id],
            ['is_active' => true],
        );
        $this->productorPrincipal->cultivos()->attach($this->cultivo->id, ['is_active' => true]);
        $this->productorSecundario->cultivos()->attach($this->cultivo->id, ['is_active' => true]);
    }

    /**
     * Crea la cadena completa salida→recepción→proceso→producción→embarque+rezaga
     * para $productor, con folios sufijados para poder llamarse varias veces.
     */
    protected function crearMovimientosCompletos(Productor $productor, string $sufijo): array
    {
        $salida = SalidaCampoCosecha::create([
            'temporada_id' => $this->temporada->id,
            'lote_id' => $this->lote->id,
            'tipo_carga_id' => $this->tipoCarga->id,
            'productor_id' => $productor->id,
            'variedad_id' => $this->variedad->id,
            'fecha' => '2026-02-01',
            'cantidad' => 100,
            'folio_salida' => "SAL-{$sufijo}",
            'status' => 'registrada',
        ]);

        $recepcion = RecepcionEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'salida_campo_id' => $salida->id,
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
        ]);

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

        $embarque = EmbarqueEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'folio_embarque' => "EMB-{$sufijo}",
            'cliente' => 'Cliente de prueba',
            'fecha_embarque' => '2026-02-05',
            'total_pallets' => 1,
            'total_cajas' => 50,
            'peso_total_kg' => 500,
            'status' => 'programado',
        ]);

        $detalleEmbarque = EmbarqueEmpaqueDetalle::create([
            'embarque_id' => $embarque->id,
            'produccion_id' => $produccion->id,
            'cajas' => 50,
            'peso_kg' => 500,
        ]);

        $rezaga = RezagaEmpaque::create([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->entity->id,
            'proceso_id' => $proceso->id,
            'folio_rezaga' => "REZ-{$sufijo}",
            'tipo_rezaga' => 'descarte',
            'fecha' => '2026-02-01',
            'cantidad_kg' => 30,
            'status' => 'pendiente',
        ]);

        return compact('salida', 'recepcion', 'proceso', 'produccion', 'embarque', 'detalleEmbarque', 'rezaga');
    }
}
