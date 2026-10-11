<?php

namespace App\Services\Respaldos;

use App\Contracts\VolcadorBaseDatos;
use Druidfi\Mysqldump\Mysqldump;

/**
 * Volcado en PHP puro (druidfi/mysqldump-php): no depende del binario
 * mysqldump, que en producción no se puede verificar.
 */
class MysqlDumpPhpVolcador implements VolcadorBaseDatos
{
    /** Tablas de las que solo se respalda la estructura. */
    public const SOLO_ESTRUCTURA = ['jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'sessions'];

    /** @param array{host:string,port:string|int,database:string,username:string,password:?string} $conexion */
    public function __construct(private array $conexion) {}

    public function volcar(string $rutaAbsolutaDestino): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            $this->conexion['host'],
            $this->conexion['port'],
            $this->conexion['database'],
        );

        $volcado = new Mysqldump($dsn, $this->conexion['username'], $this->conexion['password'] ?? '', [
            'compress' => 'Gzip',
            'single-transaction' => true,
            'add-drop-table' => true,
            'add-drop-trigger' => true,
            'routines' => true,
            'default-character-set' => 'utf8mb4',
            'no-data' => self::SOLO_ESTRUCTURA,
        ]);

        $volcado->start($rutaAbsolutaDestino);
    }
}
