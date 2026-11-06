<?php

namespace Tests\Feature\Abastecimiento;

use App\Services\Abastecimiento\TransaccionDinero;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * `TransaccionDinero::ejecutar` (arreglo final C1). Sin RefreshDatabase a propósito: el reintento solo ocurre en
 * una transacción de primer nivel, y RefreshDatabase envuelve cada prueba en una. En SQLite el `SET TRANSACTION
 * ISOLATION LEVEL READ COMMITTED` no se ejecuta y `lockForUpdate` no hace nada: lo que sí se prueba aquí es el
 * valor devuelto, el reintento ante un interbloqueo y que no se reintenta lo demás.
 */
class TransaccionDineroTest extends TestCase
{
    private function interbloqueo(): QueryException
    {
        return new QueryException(
            'sqlite', 'update abs_liquidaciones set status = ?', [],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
        );
    }

    public function test_devuelve_el_valor_y_en_sqlite_no_cambia_el_aislamiento(): void
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $this->assertSame(0, DB::transactionLevel());
        $valor = TransaccionDinero::ejecutar(function () {
            $this->assertSame(1, DB::transactionLevel());

            return ['ok' => 42];
        });

        $this->assertSame(['ok' => 42], $valor);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertEmpty(array_filter($sql, fn ($s) => stripos($s, 'isolation') !== false));
    }

    public function test_el_aislamiento_se_fija_en_mysql_y_mariadb_y_no_en_sqlite(): void
    {
        $this->assertTrue(TransaccionDinero::usaAislamiento('mysql'));
        $this->assertTrue(TransaccionDinero::usaAislamiento('mariadb'));
        $this->assertFalse(TransaccionDinero::usaAislamiento('sqlite'));
        $this->assertFalse(TransaccionDinero::usaAislamiento('pgsql'));
    }

    public function test_reintenta_una_vez_ante_un_interbloqueo_y_devuelve_el_segundo_intento(): void
    {
        $llamadas = 0;
        $valor = TransaccionDinero::ejecutar(function () use (&$llamadas) {
            $llamadas++;
            if ($llamadas === 1) {
                throw $this->interbloqueo();
            }

            return 'segundo';
        }, 3);

        $this->assertSame(2, $llamadas);
        $this->assertSame('segundo', $valor);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_agotados_los_intentos_relanza_el_interbloqueo(): void
    {
        $llamadas = 0;
        try {
            TransaccionDinero::ejecutar(function () use (&$llamadas) {
                $llamadas++;
                throw $this->interbloqueo();
            }, 3);
            $this->fail('Debió relanzar el interbloqueo.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Deadlock', $e->getMessage());
        }

        $this->assertSame(3, $llamadas);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_otra_excepcion_no_se_reintenta(): void
    {
        $llamadas = 0;
        try {
            TransaccionDinero::ejecutar(function () use (&$llamadas) {
                $llamadas++;
                throw new RuntimeException('falla de negocio');
            }, 3);
            $this->fail('Debió relanzar la excepción.');
        } catch (RuntimeException $e) {
            $this->assertSame('falla de negocio', $e->getMessage());
        }

        $this->assertSame(1, $llamadas);
    }

    public function test_dentro_de_una_transaccion_abierta_no_reintenta(): void
    {
        $llamadas = 0;
        DB::beginTransaction();
        try {
            TransaccionDinero::ejecutar(function () use (&$llamadas) {
                $llamadas++;
                throw $this->interbloqueo();
            }, 3);
            $this->fail('Debió relanzar.');
        } catch (\Throwable) {
            // Laravel lo convierte en DeadlockException al estar anidada; lo que importa es que no se reintentó.
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame(1, $llamadas);
    }
}
