<?php

namespace Tests\Feature\Respaldos;

use App\Models\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_archivo_disponible_solo_si_esta_completado_y_existe_en_disco(): void
    {
        $b = DatabaseBackup::create([
            'type' => DatabaseBackup::TIPO_MANUAL,
            'status' => DatabaseBackup::ESTADO_COMPLETADO,
            'filename' => 'sentinel_2026-10-10_020000_manual_1.sql.gz',
        ]);

        $this->assertSame('backups/sentinel_2026-10-10_020000_manual_1.sql.gz', $b->rutaRelativa());
        $this->assertFalse($b->archivo_disponible);

        Storage::disk('local')->put($b->rutaRelativa(), 'x');
        $this->assertTrue($b->fresh()->archivo_disponible);

        $b->update(['status' => DatabaseBackup::ESTADO_FALLIDO]);
        $this->assertFalse($b->fresh()->archivo_disponible);
    }

    public function test_duracion_y_en_curso(): void
    {
        $this->freezeTime();

        $this->assertFalse(DatabaseBackup::hayEnCurso());

        $b = DatabaseBackup::create([
            'type' => DatabaseBackup::TIPO_MANUAL,
            'status' => DatabaseBackup::ESTADO_EN_PROCESO,
            'started_at' => now()->subSeconds(42),
            'finished_at' => now(),
        ]);

        $this->assertTrue(DatabaseBackup::hayEnCurso());
        $this->assertSame(42, $b->duracion_segundos);
        $this->assertNull(DatabaseBackup::create(['type' => 'manual', 'status' => 'pendiente'])->duracion_segundos);
    }
}
