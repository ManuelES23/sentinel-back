<?php

namespace Tests\Feature\Respaldos;

use App\Services\Respaldos\MysqlDumpPhpVolcador;
use Tests\TestCase;

/**
 * @group mysql
 *
 * Requiere BACKUP_TEST_DB_HOST, BACKUP_TEST_DB_PORT, BACKUP_TEST_DB_DATABASE,
 * BACKUP_TEST_DB_USERNAME y BACKUP_TEST_DB_PASSWORD. Sin ellas se salta:
 * la suite normal corre en SQLite y la librería solo habla MySQL.
 */
class MysqlDumpPhpVolcadorTest extends TestCase
{
    public function test_genera_un_gzip_con_create_table(): void
    {
        if (! getenv('BACKUP_TEST_DB_DATABASE')) {
            $this->markTestSkipped('Sin MySQL de pruebas configurado (BACKUP_TEST_DB_*).');
        }

        $volcador = new MysqlDumpPhpVolcador([
            'host' => getenv('BACKUP_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('BACKUP_TEST_DB_PORT') ?: '3306',
            'database' => getenv('BACKUP_TEST_DB_DATABASE'),
            'username' => getenv('BACKUP_TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('BACKUP_TEST_DB_PASSWORD') ?: '',
        ]);

        $destino = sys_get_temp_dir().'/volcado_prueba_'.uniqid().'.sql.gz';
        try {
            $volcador->volcar($destino);
            $sql = gzdecode(file_get_contents($destino));
            $this->assertStringContainsString('CREATE TABLE', $sql);
        } finally {
            @unlink($destino);
        }
    }
}
