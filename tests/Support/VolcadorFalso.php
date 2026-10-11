<?php

namespace Tests\Support;

use App\Contracts\VolcadorBaseDatos;
use Throwable;

/** Sustituye el volcado real (MySQL) en las pruebas, que corren en SQLite. */
class VolcadorFalso implements VolcadorBaseDatos
{
    public int $llamadas = 0;

    public ?Throwable $falla = null;

    public function volcar(string $rutaAbsolutaDestino): void
    {
        $this->llamadas++;

        if ($this->falla) {
            // Simula un volcado que se corta a medias.
            file_put_contents($rutaAbsolutaDestino, 'parcial');
            throw $this->falla;
        }

        file_put_contents($rutaAbsolutaDestino, gzencode("CREATE TABLE `x` (`id` int);\n"));
    }
}
