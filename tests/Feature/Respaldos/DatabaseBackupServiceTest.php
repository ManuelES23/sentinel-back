<?php

namespace Tests\Feature\Respaldos;

use App\Contracts\VolcadorBaseDatos;
use App\Models\DatabaseBackup;
use App\Services\Respaldos\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\VolcadorFalso;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private VolcadorFalso $volcador;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->volcador = new VolcadorFalso;
        $this->app->instance(VolcadorBaseDatos::class, $this->volcador);
    }

    private function pendiente(): DatabaseBackup
    {
        return DatabaseBackup::create(['type' => DatabaseBackup::TIPO_MANUAL, 'status' => DatabaseBackup::ESTADO_PENDIENTE]);
    }

    public function test_genera_el_archivo_final_con_tamano_y_checksum(): void
    {
        $b = $this->pendiente();

        app(DatabaseBackupService::class)->generar($b);
        $b->refresh();

        $this->assertSame(DatabaseBackup::ESTADO_COMPLETADO, $b->status);
        $this->assertMatchesRegularExpression('/^sentinel_\d{4}-\d{2}-\d{2}_\d{6}_manual_'.$b->id.'\.sql\.gz$/', $b->filename);
        $ruta = Storage::disk('local')->path($b->rutaRelativa());
        $this->assertFileExists($ruta);
        $this->assertFileDoesNotExist($ruta.'.partial');
        $this->assertSame(filesize($ruta), $b->size_bytes);
        $this->assertSame(hash_file('sha256', $ruta), $b->checksum);
        $this->assertNotNull($b->started_at);
        $this->assertNotNull($b->finished_at);
        $this->assertNull($b->error);
    }

    public function test_si_el_volcado_falla_queda_fallido_sin_partial_ni_detalles_internos(): void
    {
        $this->volcador->falla = new RuntimeException('SQLSTATE[HY000] Access denied for user root password secreta');
        $b = $this->pendiente();

        app(DatabaseBackupService::class)->generar($b);
        $b->refresh();

        $this->assertSame(DatabaseBackup::ESTADO_FALLIDO, $b->status);
        $this->assertSame('No se pudo generar el respaldo. Revisa el log del servidor para más detalle.', $b->error);
        $this->assertStringNotContainsString('secreta', $b->error);
        $this->assertFileDoesNotExist(Storage::disk('local')->path($b->rutaRelativa()).'.partial');
        $this->assertNotNull($b->finished_at);
    }

    public function test_espacio_insuficiente_no_llama_al_volcador(): void
    {
        $this->app->instance(DatabaseBackupService::class, new class($this->volcador) extends DatabaseBackupService
        {
            protected function espacioLibre(string $directorio): float
            {
                return 10 * 1024 * 1024; // 10 MB < 500 MB mínimos
            }
        });
        $b = $this->pendiente();

        app(DatabaseBackupService::class)->generar($b);
        $b->refresh();

        $this->assertSame(DatabaseBackup::ESTADO_FALLIDO, $b->status);
        $this->assertSame('Espacio insuficiente en el servidor para generar el respaldo.', $b->error);
        $this->assertSame(0, $this->volcador->llamadas);
    }

    public function test_el_minimo_de_espacio_es_el_doble_del_ultimo_respaldo_si_es_mayor(): void
    {
        DatabaseBackup::create([
            'type' => 'manual', 'status' => 'completado', 'filename' => 'previo.sql.gz',
            'size_bytes' => 400 * 1024 * 1024,
        ]);
        $this->app->instance(DatabaseBackupService::class, new class($this->volcador) extends DatabaseBackupService
        {
            protected function espacioLibre(string $directorio): float
            {
                return 700 * 1024 * 1024; // > 500 MB pero < 2 × 400 MB
            }
        });
        $b = $this->pendiente();

        app(DatabaseBackupService::class)->generar($b);

        $this->assertSame(DatabaseBackup::ESTADO_FALLIDO, $b->fresh()->status);
    }
}
