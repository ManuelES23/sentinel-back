<?php

namespace Tests\Feature\Compras;

use App\Models\RequisicionCampo;
use App\Services\Compras\AlcanceCompras;
use App\Services\Compras\PermisosCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class PermisosAlcanceComprasTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
    }

    public function test_cotizar_y_confirmar_por_permiso(): void
    {
        $compras = $this->crearUsuarioDeCampo();
        $campo = $this->crearUsuarioDeCampo([$this->almacenA]);
        $this->otorgarCotizar($compras);
        $this->otorgarConfirmar($compras);
        $permisos = app(PermisosCompras::class);

        $this->assertTrue($permisos->puedeCotizar($compras, $this->empresa));
        $this->assertTrue($permisos->puedeConfirmar($compras, $this->empresa));
        $this->assertFalse($permisos->puedeCotizar($campo, $this->empresa));
        $this->assertFalse($permisos->puedeConfirmar($campo, $this->empresa));
        $this->assertTrue($permisos->puedeCotizar($this->crearAdmin(), $this->empresa));
        $this->assertSame([$compras->id], $permisos->usuariosQueCotizan($this->empresa)->pluck('id')->all());
    }

    public function test_alcance_filtra_por_almacen_visible(): void
    {
        $encargadoA = $this->crearUsuarioDeCampo([$this->almacenA]);
        $reqA = $this->crearRequisicion($encargadoA, $this->almacenA);
        $reqB = $this->crearRequisicion($encargadoA, $this->almacenB);
        $legado = $this->crearRequisicion($encargadoA, $this->almacenA);
        $legado->update(['almacen_id' => null, 'enterprise_id' => null]);
        $alcance = app(AlcanceCompras::class);

        $ids = $alcance->aplicar(RequisicionCampo::query(), 'almacen_id', $encargadoA, $this->empresa)->pluck('id')->all();
        $this->assertSame([$reqA->id], $ids);
        $this->assertTrue($alcance->puedeVer($encargadoA, $this->empresa, $reqA, 'almacen_id'));
        $this->assertFalse($alcance->puedeVer($encargadoA, $this->empresa, $reqB, 'almacen_id'));
        $this->assertFalse($alcance->puedeVer($encargadoA, $this->empresa, $legado, 'almacen_id'));

        $compras = $this->crearUsuarioDeCampo();
        $this->otorgarVerTodos($compras);
        $todos = $alcance->aplicar(RequisicionCampo::query(), 'almacen_id', $compras, $this->empresa)->pluck('id')->sort()->values()->all();
        $this->assertSame([$reqA->id, $reqB->id, $legado->id], $todos);
    }
}
