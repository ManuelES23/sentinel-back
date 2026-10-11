<?php

namespace App\Services\Respaldos;

use App\Models\DatabaseBackup;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

        return $latido !== null && $latido->greaterThan(now()->subMinutes(self::MINUTOS_LATIDO_VIVO));
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
            ->each(fn (DatabaseBackup $b) => $this->eliminar($b));

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

    /** Borra archivo y fila. Lo usan la retención y el controlador. */
    public function eliminar(DatabaseBackup $backup): void
    {
        if ($backup->rutaRelativa()) {
            Storage::disk('local')->delete($backup->rutaRelativa());
        }
        $backup->delete();
    }

    private function hayProgramadoEl(Carbon $dia): bool
    {
        return DatabaseBackup::where('type', DatabaseBackup::TIPO_PROGRAMADO)
            ->whereDate('created_at', $dia->toDateString())
            ->exists();
    }
}
