<?php

namespace Tests\Concerns;

use App\Models\DestinoEmbarque;
use App\Models\Enterprise;

/**
 * El alta de un embarque de Empaque exige un destino del catálogo
 * (`destinos_embarque`). Este helper crea (una vez por nombre) un destino activo
 * de la primera empresa de la prueba.
 */
trait CreatesDestinoEmbarque
{
    protected function destinoEmbarqueId(string $nombre = 'Destino de prueba', ?int $bodegaPlaneacionId = null): int
    {
        $enterpriseId = Enterprise::query()->orderBy('id')->value('id');
        $this->assertNotNull($enterpriseId, 'La prueba necesita una empresa antes de crear el destino.');

        return DestinoEmbarque::firstOrCreate(
            ['enterprise_id' => $enterpriseId, 'nombre' => $nombre],
            ['plan_bodega_destino_id' => $bodegaPlaneacionId, 'is_active' => true],
        )->id;
    }
}
