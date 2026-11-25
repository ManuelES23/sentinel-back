<?php

namespace App\Models;

use App\Traits\Loggable;
use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoCarga extends Model
{
    use HasFactory, Loggable;

    protected $table = 'tipos_carga';

    /** Clases de bin de rezaga (Elote en bins); null = tipo de carga normal. */
    public const CLASES_REZAGA = ['hoja', 'fresco', 'cuarto_frio'];

    protected $fillable = [
        'cultivo_id',
        'nombre',
        'categoria_caja',
        'peso_estimado_kg',
        'clase_rezaga',
        'descripcion',
        'is_active',
    ];

    protected $casts = [
        'peso_estimado_kg' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // ── Relaciones ──────────────────────────────────────

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }

    public function salidasCampo(): HasMany
    {
        return $this->hasMany(SalidaCampoCosecha::class, 'tipo_carga_id');
    }

    // ── Rezaga en bins ──────────────────────────────────

    /**
     * Regla de validación para el `tipo_carga_id` de salidas de campo y
     * recepciones: solo acepta tipos de carga SIN clase de rezaga (un bin de
     * rezaga no se cosecha ni se recibe). Va después de `exists:tipos_carga,id`
     * (con `bail`) para que un id inexistente siga dando el mensaje por defecto.
     */
    public static function reglaBinDeCampo(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $esRezaga = static::query()->whereKey($value)->whereNotNull('clase_rezaga')->exists();

            if ($esRezaga) {
                $fail('Ese tipo de carga es un bin de rezaga; elige un bin de campo.');
            }
        };
    }

    /**
     * Tipo y subtipo de rezaga que le corresponden a la clase de este tipo de
     * carga, o null si no tiene clase (o es desconocida).
     */
    public function mapeoRezaga(): ?array
    {
        return static::mapeoDeClase($this->clase_rezaga);
    }

    /**
     * Tipo y subtipo de rezaga de una clase (hoja|fresco|cuarto_frio), o null
     * si la clase es desconocida. La usan tanto la clase propia del tipo de
     * carga como la clase elegida aparte en la captura.
     */
    public static function mapeoDeClase(?string $clase): ?array
    {
        return match ($clase) {
            'hoja' => ['tipo_rezaga' => 'produccion', 'subtipo_rezaga' => 'hoja'],
            'fresco' => ['tipo_rezaga' => 'produccion', 'subtipo_rezaga' => 'producto'],
            'cuarto_frio' => ['tipo_rezaga' => 'cuarto_frio', 'subtipo_rezaga' => 'producto'],
            default => null,
        };
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCultivo($query, $cultivoId)
    {
        return $query->where('cultivo_id', $cultivoId);
    }
}
