<?php

namespace Tests\Unit\Abastecimiento;

use App\Models\Abastecimiento\ConvenioPrecio;
use App\Services\Abastecimiento\PrecioVigente;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Precios armados en memoria: el servicio puro no toca la base. */
class PrecioVigenteCalibreTest extends TestCase
{
    private function precio(int $id, int $tipo, ?int $calibre, string $desde, float $precio, bool $principal = false): ConvenioPrecio
    {
        $p = new ConvenioPrecio([
            'convenio_id' => 1,
            'tipo_carga_id' => $tipo,
            'calibre_id' => $calibre,
            'precio' => $precio,
            'vigencia_inicio' => $desde,
            'es_principal' => $principal,
        ]);
        $p->id = $id;

        return $p;
    }

    private function precios(): Collection
    {
        return collect([
            $this->precio(1, 1, null, '2026-01-01', 10),
            $this->precio(2, 1, 5, '2026-01-01', 20, true),
            $this->precio(3, 1, 5, '2026-03-01', 25),
            $this->precio(4, 1, 6, '2026-01-01', 30),
        ]);
    }

    public function test_calibre_nulo_ignora_precios_con_calibre(): void
    {
        $p = PrecioVigente::enColeccion($this->precios(), 1, '2026-06-01');

        $this->assertSame(1, $p->id);
        $this->assertSame(10.0, (float) $p->precio);
    }

    public function test_precio_del_calibre_vigente_por_fecha(): void
    {
        $antes = PrecioVigente::enColeccion($this->precios(), 1, '2026-02-01', 5);
        $despues = PrecioVigente::enColeccion($this->precios(), 1, '2026-03-01', 5);

        $this->assertSame(2, $antes->id);
        $this->assertSame(3, $despues->id);
        $this->assertSame(4, PrecioVigente::enColeccion($this->precios(), 1, '2026-02-01', 6)->id);
        // Un calibre sin precio especial toma el general (sin calibre).
        $this->assertSame(1, PrecioVigente::enColeccion($this->precios(), 1, '2026-02-01', 99)->id);
        // Sin general ni especial vigente no hay precio.
        $this->assertNull(PrecioVigente::enColeccion($this->precios(), 1, '2025-12-31', 99));
        $this->assertNull(PrecioVigente::enColeccion($this->precios(), 2, '2026-02-01', 5));
    }

    public function test_el_especial_es_fijo_aunque_cambie_el_general(): void
    {
        $precios = collect([
            $this->precio(1, 1, null, '2026-01-01', 150, true),
            $this->precio(2, 1, null, '2026-03-01', 160),
            $this->precio(3, 1, 7, '2026-01-01', 180),
        ]);

        // El general sube de 150 a 160; el especial del calibre 7 sigue en 180.
        $this->assertSame(150.0, (float) PrecioVigente::enColeccion($precios, 1, '2026-02-01', 8)->precio);
        $this->assertSame(160.0, (float) PrecioVigente::enColeccion($precios, 1, '2026-04-01', 8)->precio);
        $this->assertSame(180.0, (float) PrecioVigente::enColeccion($precios, 1, '2026-04-01', 7)->precio);
    }

    public function test_un_especial_que_empieza_despues_deja_el_general_antes(): void
    {
        $precios = collect([
            $this->precio(1, 1, null, '2026-01-01', 150, true),
            $this->precio(2, 1, 7, '2026-05-01', 180),
        ]);

        $this->assertSame(1, PrecioVigente::enColeccion($precios, 1, '2026-04-30', 7)->id);
        $this->assertSame(2, PrecioVigente::enColeccion($precios, 1, '2026-05-01', 7)->id);
    }

    public function test_principal_del_calibre_cae_al_general(): void
    {
        $precios = collect([
            $this->precio(1, 1, null, '2026-01-01', 150, true),
            $this->precio(2, 1, 7, '2026-01-01', 180),
        ]);

        $this->assertSame(2, PrecioVigente::principalEnColeccion($precios, '2026-02-01', 7)->id);
        $this->assertSame(1, PrecioVigente::principalEnColeccion($precios, '2026-02-01', 8)->id);
        // Antes de toda vigencia: la que empieza primero (el especial en empate).
        $this->assertSame(2, PrecioVigente::principalEnColeccion($precios, '2025-01-01', 7)->id);

        $tarde = collect([
            $this->precio(1, 1, null, '2026-02-01', 150, true),
            $this->precio(2, 1, 7, '2026-03-01', 180),
        ]);
        $this->assertSame(1, PrecioVigente::principalEnColeccion($tarde, '2026-01-01', 7)->id);
    }

    public function test_principal_del_calibre_usa_el_tipo_principal_del_convenio(): void
    {
        $precios = collect([
            $this->precio(1, 1, 5, '2026-01-01', 20),
            $this->precio(2, 2, 5, '2026-01-01', 40, true),
            $this->precio(3, 2, 6, '2026-01-01', 50),
            $this->precio(4, 1, 6, '2026-01-01', 30),
        ]);

        $this->assertSame(2, PrecioVigente::principalEnColeccion($precios, '2026-02-01', 5)->id);
        $this->assertSame(3, PrecioVigente::principalEnColeccion($precios, '2026-02-01', 6)->id);
        // Antes de la primera vigencia cae a la primera del tipo principal en ese calibre.
        $this->assertSame(3, PrecioVigente::principalEnColeccion($precios, '2025-01-01', 6)->id);
    }
}
