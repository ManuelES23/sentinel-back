<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * PUT convenios/{id} cuando el convenio ya tiene recepciones vivas: lo ya capturado
 * (fechas, precios vigentes, productor, variedad y temporada) no se puede dejar huérfano.
 */
class ConvenioEdicionConRecepcionesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private const SIN_CAMBIO = 'No se puede cambiar: el convenio ya tiene recepciones.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function url(Convenio $c): string
    {
        return "/api/splendidbyporvenir/administration/abastecimiento/convenios/{$c->id}";
    }

    /** Cuerpo que repite el convenio tal cual (sin metas: se conservan las guardadas). */
    private function cuerpoDe(Convenio $c, array $over = []): array
    {
        $c->load('precios');

        return array_merge([
            'temporada_id' => $c->temporada_id,
            'productor_id' => $c->productor_id,
            'variedad_id' => $c->variedad_id,
            'modalidad' => $c->modalidad,
            'unidad' => $c->unidad,
            'moneda' => $c->moneda,
            'porcentaje_rezaga' => 0,
            'fecha_inicio' => $c->fecha_inicio->format('Y-m-d'),
            'fecha_fin' => $c->fecha_fin->format('Y-m-d'),
            'modo_volumen' => $c->modo_volumen,
            'volumen_total' => $c->volumen_total,
            'precios' => $c->precios->map(fn ($p) => [
                'tipo_carga_id' => $p->tipo_carga_id,
                'precio' => $p->precio,
                'vigencia_inicio' => $p->vigencia_inicio->format('Y-m-d'),
                'es_principal' => $p->es_principal,
            ])->all(),
        ], $over);
    }

    /** Convenio activo (2026-06-01 a 2026-06-28) con una recepción el 10 de junio. */
    private function convenioConRecepcion(): Convenio
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);
        $this->crearRecepcion($c, ['fecha' => '2026-06-10']);

        return $c;
    }

    private function tipoExtra(string $nombre): TipoCarga
    {
        return TipoCarga::create(['enterprise_id' => $this->sbp->id, 'nombre' => $nombre, 'is_active' => true]);
    }

    public function test_fecha_inicio_que_deja_fuera_una_recepcion_422(): void
    {
        $c = $this->convenioConRecepcion();

        $this->putJson($this->url($c), $this->cuerpoDe($c, ['fecha_inicio' => '2026-06-15']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_inicio'])
            ->assertJsonPath('errors.fecha_inicio.0', 'No se puede: hay recepciones fuera de las nuevas fechas del convenio.');
        $this->assertSame('2026-06-01', $c->fresh()->fecha_inicio->format('Y-m-d'));
    }

    public function test_fecha_fin_que_deja_fuera_una_recepcion_422(): void
    {
        $c = $this->convenioConRecepcion();

        $this->putJson($this->url($c), $this->cuerpoDe($c, ['fecha_fin' => '2026-06-09']))
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_fin'])
            ->assertJsonPath('errors.fecha_fin.0', 'No se puede: hay recepciones fuera de las nuevas fechas del convenio.');
        $this->assertSame('2026-06-28', $c->fresh()->fecha_fin->format('Y-m-d'));
    }

    public function test_precios_que_dejan_una_recepcion_sin_precio_vigente_422(): void
    {
        $c = $this->convenioConRecepcion();
        $tipo = $c->precios->first()->tipo_carga_id;

        // La única vigencia empezaría el 15 de junio: la recepción del 10 se quedaría sin precio.
        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'precios' => [['tipo_carga_id' => $tipo, 'precio' => 100, 'vigencia_inicio' => '2026-06-15', 'es_principal' => true]],
        ]))
            ->assertStatus(422)->assertJsonValidationErrors(['precios'])
            ->assertJsonPath('errors.precios.0', 'No se puede: el precio dejaría recepciones ya capturadas sin precio vigente.');

        // Quitar el tipo de carga de la recepción tampoco vale.
        $otro = $this->tipoExtra('Granel');
        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'precios' => [['tipo_carga_id' => $otro->id, 'precio' => 100, 'vigencia_inicio' => '2026-06-01', 'es_principal' => true]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['precios']);
    }

    public function test_productor_variedad_y_temporada_no_cambian_con_recepciones(): void
    {
        $c = $this->convenioConRecepcion();
        $otroProductor = Productor::create(['enterprise_id' => $this->sbp->id, 'tipo' => 'productor', 'nombre' => 'Otro', 'is_active' => true]);
        $otraVariedad = Variedad::where('cultivo_id', $c->cultivo_id)->where('id', '!=', $c->variedad_id)->firstOrFail();
        $otraTemporada = Temporada::create([
            'enterprise_id' => $this->sbp->id, 'cultivo_id' => $c->cultivo_id, 'nombre' => 'Otra temporada',
            'fecha_inicio' => '2026-05-01', 'fecha_fin' => '2026-09-30', 'estado' => 'activa',
        ]);

        foreach ([
            'productor_id' => $otroProductor->id,
            'variedad_id' => $otraVariedad->id,
            'temporada_id' => $otraTemporada->id,
        ] as $campo => $valor) {
            $this->putJson($this->url($c), $this->cuerpoDe($c, [$campo => $valor]))
                ->assertStatus(422)->assertJsonValidationErrors([$campo])
                ->assertJsonPath("errors.{$campo}.0", self::SIN_CAMBIO);
        }
    }

    public function test_ediciones_permitidas_con_recepciones(): void
    {
        $c = $this->convenioConRecepcion();

        // Guardar sin cambios.
        $this->putJson($this->url($c), $this->cuerpoDe($c))->assertOk();

        // Notas y rezaga.
        $this->putJson($this->url($c), $this->cuerpoDe($c, ['notas' => 'ajuste', 'porcentaje_rezaga' => 3]))
            ->assertOk()->assertJsonPath('data.notas', 'ajuste');

        // Ampliar fechas.
        $this->putJson($this->url($c), $this->cuerpoDe($c, ['fecha_inicio' => '2026-05-25', 'fecha_fin' => '2026-07-05']))
            ->assertOk();
        $this->assertSame('2026-07-05', $c->fresh()->fecha_fin->format('Y-m-d'));

        // Acortar sin dejar fuera la recepcion (con las metas ajustadas a las fechas nuevas).
        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'fecha_inicio' => '2026-06-08', 'fecha_fin' => '2026-06-14',
            'metas' => [['semana' => '2026-06-08', 'cantidad' => 100]],
        ]))->assertOk();
    }

    public function test_agregar_tipos_de_carga_y_vigencias_nuevas_con_recepciones(): void
    {
        $c = $this->convenioConRecepcion();
        $tipo = $c->precios->first()->tipo_carga_id;
        $otro = $this->tipoExtra('Granel');

        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'precios' => [
                ['tipo_carga_id' => $tipo, 'precio' => 100, 'vigencia_inicio' => '2026-06-01', 'es_principal' => true],
                ['tipo_carga_id' => $tipo, 'precio' => 120, 'vigencia_inicio' => '2026-06-20', 'es_principal' => true],
                ['tipo_carga_id' => $otro->id, 'precio' => 90, 'vigencia_inicio' => '2026-06-05', 'es_principal' => false],
            ],
        ]))->assertOk()->assertJsonCount(3, 'data.precios');
    }

    public function test_una_recepcion_borrada_no_bloquea_los_cambios(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'activo']);
        $this->crearRecepcion($c, ['fecha' => '2026-06-10'])->delete();
        $otroProductor = Productor::create(['enterprise_id' => $this->sbp->id, 'tipo' => 'productor', 'nombre' => 'Otro', 'is_active' => true]);

        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'productor_id' => $otroProductor->id, 'fecha_inicio' => '2026-06-15',
            'metas' => [['semana' => '2026-06-15', 'cantidad' => 100], ['semana' => '2026-06-22', 'cantidad' => 100]],
        ]))->assertOk();
    }

    public function test_precio_mayor_al_maximo_de_la_columna_422(): void
    {
        $c = $this->crearConvenio($this->sbp, ['status' => 'borrador']);
        $tipo = $c->precios->first()->tipo_carga_id;

        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'precios' => [['tipo_carga_id' => $tipo, 'precio' => 100000000, 'vigencia_inicio' => '2026-06-01', 'es_principal' => true]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['precios.0.precio']);

        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'precios' => [['tipo_carga_id' => $tipo, 'precio' => 99999999.9999, 'vigencia_inicio' => '2026-06-01', 'es_principal' => true]],
        ]))->assertOk();
    }

    /** Las reglas por calibre (plan 2) no tocan a los clásicos: la cobertura sigue por (tipo de carga, fecha). */
    public function test_los_clasicos_con_recepciones_siguen_igual(): void
    {
        $c = $this->convenioConRecepcion();
        $tipo = $c->precios->first()->tipo_carga_id;

        // Una vigencia que deja la recepción del 10 sin precio: mismo 422 y nada de `calibres`.
        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'precios' => [['tipo_carga_id' => $tipo, 'precio' => 100, 'vigencia_inicio' => '2026-06-11', 'es_principal' => true]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['precios'])
            ->assertJsonMissingValidationErrors(['calibres'])
            ->assertJsonPath('errors.precios.0', 'No se puede: el precio dejaría recepciones ya capturadas sin precio vigente.');

        // Una vigencia nueva después de la recepción se acepta y el convenio sigue clásico.
        $this->putJson($this->url($c), $this->cuerpoDe($c, [
            'precios' => [
                ['tipo_carga_id' => $tipo, 'precio' => 100, 'vigencia_inicio' => '2026-06-01', 'es_principal' => true],
                ['tipo_carga_id' => $tipo, 'precio' => 110, 'vigencia_inicio' => '2026-06-12', 'es_principal' => true],
            ],
        ]))->assertOk()->assertJsonPath('data.usa_calibres', false)->assertJsonPath('data.calibres', []);
        $this->assertFalse($c->fresh()->usa_calibres);
    }
}
