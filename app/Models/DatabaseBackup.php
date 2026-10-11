<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Respaldo de la base de datos (archivo .sql.gz en storage/app/private/backups).
 * Lo genera el comando respaldos:ejecutar; el panel solo crea filas pendientes.
 */
class DatabaseBackup extends Model
{
    public const TIPO_MANUAL = 'manual';
    public const TIPO_PROGRAMADO = 'programado';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_EN_PROCESO = 'en_proceso';
    public const ESTADO_COMPLETADO = 'completado';
    public const ESTADO_FALLIDO = 'fallido';

    public const ESTADOS_EN_CURSO = [self::ESTADO_PENDIENTE, self::ESTADO_EN_PROCESO];

    public const DIRECTORIO = 'backups';

    protected $fillable = [
        'filename', 'type', 'status', 'size_bytes', 'checksum',
        'started_at', 'finished_at', 'error', 'created_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $appends = ['archivo_disponible', 'duracion_segundos'];

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rutaRelativa(): ?string
    {
        return $this->filename ? self::DIRECTORIO.'/'.$this->filename : null;
    }

    public static function hayEnCurso(): bool
    {
        return static::whereIn('status', self::ESTADOS_EN_CURSO)->exists();
    }

    public function getArchivoDisponibleAttribute(): bool
    {
        return $this->status === self::ESTADO_COMPLETADO
            && $this->rutaRelativa() !== null
            && Storage::disk('local')->exists($this->rutaRelativa());
    }

    public function getDuracionSegundosAttribute(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
