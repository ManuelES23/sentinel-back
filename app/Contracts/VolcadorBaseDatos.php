<?php

namespace App\Contracts;

/**
 * Vuelca la base de datos completa a un archivo .sql.gz.
 * Debe dejar el archivo completo o lanzar una excepción.
 */
interface VolcadorBaseDatos
{
    public function volcar(string $rutaAbsolutaDestino): void;
}
