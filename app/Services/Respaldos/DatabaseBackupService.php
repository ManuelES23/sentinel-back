<?php

namespace App\Services\Respaldos;

use App\Contracts\VolcadorBaseDatos;
use App\Models\DatabaseBackup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Genera un respaldo: comprueba espacio, vuelca a .partial y renombra al
 * terminar, para que un archivo a medias nunca aparezca como listo.
 */
class DatabaseBackupService
{
    public const ESPACIO_MINIMO_BYTES = 500 * 1024 * 1024;

    private const MENSAJE_ESPACIO = 'Espacio insuficiente en el servidor para generar el respaldo.';
    private const MENSAJE_GENERICO = 'No se pudo generar el respaldo. Revisa el log del servidor para más detalle.';

    public function __construct(private VolcadorBaseDatos $volcador) {}

    public function generar(DatabaseBackup $backup): void
    {
        try {
            $disco = Storage::disk('local');
            $disco->makeDirectory(DatabaseBackup::DIRECTORIO);

            $backup->forceFill([
                'filename' => sprintf('sentinel_%s_%s_%d.sql.gz', now()->format('Y-m-d_His'), $backup->type, $backup->id),
                'status' => DatabaseBackup::ESTADO_EN_PROCESO,
                'started_at' => now(),
                'finished_at' => null,
                'error' => null,
            ])->save();

            $final = $disco->path($backup->rutaRelativa());
            $parcial = $final.'.partial';

            if ($this->espacioLibre(dirname($final)) < $this->espacioRequerido()) {
                $this->marcarFallido($backup, self::MENSAJE_ESPACIO);

                return;
            }

            @set_time_limit(0);
            $this->volcador->volcar($parcial);

            if (! is_file($parcial) || filesize($parcial) === 0) {
                throw new RuntimeException('El volcado terminó sin generar contenido.');
            }
            if (! rename($parcial, $final)) {
                throw new RuntimeException("No se pudo renombrar {$parcial}.");
            }

            $backup->forceFill([
                'status' => DatabaseBackup::ESTADO_COMPLETADO,
                'size_bytes' => filesize($final),
                'checksum' => hash_file('sha256', $final),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::error('Respaldo de BD fallido', ['backup_id' => $backup->id, 'error' => $e->getMessage()]);
            $this->marcarFallido($backup, self::MENSAJE_GENERICO);
        }
    }

    public function marcarFallido(DatabaseBackup $backup, string $mensaje): void
    {
        if ($backup->rutaRelativa()) {
            @unlink(Storage::disk('local')->path($backup->rutaRelativa()).'.partial');
        }

        $backup->forceFill([
            'status' => DatabaseBackup::ESTADO_FALLIDO,
            'error' => $mensaje,
            'finished_at' => now(),
        ])->save();
    }

    /** El doble del último respaldo completado, con un piso de 500 MB. */
    private function espacioRequerido(): float
    {
        $ultimo = (int) DatabaseBackup::where('status', DatabaseBackup::ESTADO_COMPLETADO)
            ->latest('id')
            ->value('size_bytes');

        return max(self::ESPACIO_MINIMO_BYTES, 2 * $ultimo);
    }

    /** Si el sistema no informa el espacio libre, no se bloquea el respaldo. */
    protected function espacioLibre(string $directorio): float
    {
        $libre = @disk_free_space($directorio);

        return $libre === false ? INF : $libre;
    }
}
