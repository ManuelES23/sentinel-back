<?php

namespace App\Models\Planeacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Programa de una semana (de lunes a domingo), identificada por su lunes.
 * Los días de sus renglones van de 0 (lunes) a 6 (domingo). Con
 * `trabaja_domingo = false` el día 6 queda en cero y lo que se empacaría el
 * domingo se empaca el sábado (ver PlanSemana::necesidadesPorEmpaque).
 */
class ProgramaSemanal extends Model
{
    protected $table = 'programas_semanales';

    protected $fillable = ['lunes', 'notas', 'trabaja_domingo', 'junta_registrada_at', 'junta_registrada_por'];

    /** Igual que el default de la columna: un programa recién creado ya lo trae en memoria. */
    protected $attributes = [
        'trabaja_domingo' => true,
    ];

    protected $casts = [
        'lunes' => 'date:Y-m-d',
        'trabaja_domingo' => 'boolean',
        'junta_registrada_at' => 'datetime',
        'junta_registrada_por' => 'integer',
    ];

    public function cortes(): HasMany
    {
        return $this->hasMany(ProgramaCorte::class, 'programa_id');
    }

    public function necesidades(): HasMany
    {
        return $this->hasMany(ProgramaNecesidad::class, 'programa_id');
    }

    public function sobrantes(): HasMany
    {
        return $this->hasMany(ProgramaSobrante::class, 'programa_id');
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(ProgramaViaje::class, 'programa_id');
    }

    public function embarques(): HasMany
    {
        return $this->hasMany(ProgramaEmbarque::class, 'programa_id');
    }

    public function acuerdos(): HasMany
    {
        return $this->hasMany(ProgramaAcuerdo::class, 'programa_id');
    }

    public function rendimientos(): HasMany
    {
        return $this->hasMany(ProgramaRendimiento::class, 'programa_id');
    }

    public function inventariosIniciales(): HasMany
    {
        return $this->hasMany(ProgramaInventarioInicial::class, 'programa_id');
    }

    public function juntas(): HasMany
    {
        return $this->hasMany(ProgramaJunta::class, 'programa_id');
    }

    public function cambios(): HasMany
    {
        return $this->hasMany(ProgramaCambio::class, 'programa_id');
    }

    public function juntaRegistradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'junta_registrada_por');
    }
}
