<?php

namespace App\Services\Respaldos;

use App\Models\DatabaseBackup;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que hace respaldos:ejecutar cada minuto. Va por el programador y no por
 * la cola: retry_after (90 s) relanzaría en paralelo un volcado largo.
 */
class CicloRespaldos
{
    public const LATIDO_CACHE_KEY = 'backups.scheduler_heartbeat';
    public const MINUTOS_ATASCADO = 120;
    public const MINUTOS_LATIDO_VIVO = 5;
    public const DIAS_FALLIDOS = 30;

    public function __construct(
        private DatabaseBackupService $servicio,
        private SettingsService $ajustes,
    ) {}

    public function ejecutar(): void
    {
        $this->registrarLatido();
        $this->marcarAtascados();
        $this->crearProgramadoSiCorresponde();
        $this->procesarPendientes();
        $this->aplicarRetencion();
    }

    public function registrarLatido(): void
    {
        Cache::forever(self::LATIDO_CACHE_KEY, now()->toIso8601String());
    }

    public function ultimoLatido(): ?Carbon
    {
        $valor = Cache::get(self::LATIDO_CACHE_KEY);

        return $valor ? Carbon::parse($valor)->setTimezone(config('app.timezone')) : null;
    }

    public function programadorActivo(): bool
    {
        $latido = $this->ultimoLatido();
        if ($latido !== null && $latido->greaterThan(now()->subMinutes(self::MINUTOS_LATIDO_VIVO))) {
            return true;
        }

        // Mientras corre un volcado largo, withoutOverlapping bloquea las corridas
        // de cada minuto y el latido no se renueva; un volcado en curso (y no
        // atascado: pasadas 2 h lo marca fallido marcarAtascados) prueba que el
        // programador está vivo.
        return DatabaseBackup::where('status', DatabaseBackup::ESTADO_EN_PROCESO)
            ->where('started_at', '>=', now()->subMinutes(self::MINUTOS_ATASCADO))
            ->exists();
    }

    public function marcarAtascados(): int
    {
        $atascados = DatabaseBackup::where('status', DatabaseBackup::ESTADO_EN_PROCESO)
            ->where('started_at', '<', now()->subMinutes(self::MINUTOS_ATASCADO))
            ->get();

        foreach ($atascados as $backup) {
            $this->servicio->marcarFallido($backup, 'El proceso de respaldo se interrumpió (tiempo excedido).');
        }

        return $atascados->count();
    }

    public function crearProgramadoSiCorresponde(): ?DatabaseBackup
    {
        $config = $this->ajustes->backupConfig();
        if (! $config['enabled'] || now()->lt(today()->setTime($config['hour'], 0))) {
            return null;
        }
        if ($this->hayProgramadoEl(today())) {
            return null;
        }

        return DatabaseBackup::create([
            'type' => DatabaseBackup::TIPO_PROGRAMADO,
            'status' => DatabaseBackup::ESTADO_PENDIENTE,
        ]);
    }

    public function procesarPendientes(): int
    {
        $procesados = 0;
        // Se relee en cada vuelta: un manual creado mientras corre el anterior también entra.
        while ($backup = DatabaseBackup::where('status', DatabaseBackup::ESTADO_PENDIENTE)->orderBy('id')->first()) {
            $this->servicio->generar($backup);
            $procesados++;
        }

        return $procesados;
    }

    public function aplicarRetencion(): void
    {
        $conservar = $this->ajustes->backupConfig()['keep_scheduled'];

        DatabaseBackup::where('type', DatabaseBackup::TIPO_PROGRAMADO)
            ->where('status', DatabaseBackup::ESTADO_COMPLETADO)
            ->orderByDesc('id')
            ->skip($conservar)
            ->take(PHP_INT_MAX)
            ->get()
            // Si el archivo no se pudo borrar la fila se queda y se reintenta al minuto siguiente.
            ->each(function (DatabaseBackup $b) {
                $this->eliminar($b);
            });

        DatabaseBackup::where('status', DatabaseBackup::ESTADO_FALLIDO)
            ->where('created_at', '<', now()->subDays(self::DIAS_FALLIDOS))
            ->delete();
    }

    public function proximoProgramado(): ?Carbon
    {
        $config = $this->ajustes->backupConfig();
        if (! $config['enabled']) {
            return null;
        }

        $hoy = today()->setTime($config['hour'], 0);
        if ($hoy->greaterThan(now()) && ! $this->hayProgramadoEl(today())) {
            return $hoy;
        }

        return today()->addDay()->setTime($config['hour'], 0);
    }

    /**
     * Borra archivo y fila. Lo usan la retención y el controlador. Si el archivo
     * sigue en disco (el disco no lanza al fallar: típico de permisos) conserva
     * la fila para no dejar un volcado huérfano que nadie vea, y devuelve false.
     */
    public function eliminar(DatabaseBackup $backup): bool
    {
        $ruta = $backup->rutaRelativa();
        if ($ruta !== null) {
            // Si la carpeta no se puede recorrer, exists() daría false aunque el archivo siga ahí.
            if (! $this->directorioAccesible()) {
                Log::error('La carpeta de respaldos no es accesible para este proceso', ['backup_id' => $backup->id, 'filename' => $backup->filename]);

                return false;
            }
            $this->borrarArchivo($ruta);
            if (Storage::disk('local')->exists($ruta)) {
                Log::error('No se pudo borrar el archivo de respaldo', ['backup_id' => $backup->id, 'filename' => $backup->filename]);

                return false;
            }
        }
        $backup->delete();

        return true;
    }

    /** Falso si la carpeta existe pero este proceso no puede leerla ni entrar en ella (permisos). */
    protected function directorioAccesible(): bool
    {
        $dir = Storage::disk('local')->path(DatabaseBackup::DIRECTORIO);

        if (! is_dir($dir)) {
            return true;
        }

        // En Windows is_executable() es siempre falso para carpetas: solo se comprueba la lectura.
        return is_readable($dir) && (PHP_OS_FAMILY === 'Windows' || is_executable($dir));
    }

    protected function borrarArchivo(string $rutaRelativa): void
    {
        Storage::disk('local')->delete($rutaRelativa);
    }

    private function hayProgramadoEl(Carbon $dia): bool
    {
        return DatabaseBackup::where('type', DatabaseBackup::TIPO_PROGRAMADO)
            ->whereDate('created_at', $dia->toDateString())
            ->exists();
    }
}
