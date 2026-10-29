<?php

namespace App\Models\Planeacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Programa de una semana (de lunes a sábado), identificada por su lunes.
 * Los días de sus renglones van de 0 (lunes) a 5 (sábado).
 */
class ProgramaSemanal extends Model
{
    protected $table = 'programas_semanales';

    protected $fillable = ['lunes', 'junta_registrada_at', 'junta_registrada_por'];

    protected $casts = [
        'lunes' => 'date:Y-m-d',
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

    public function viajes(): HasMany
    {
        return $this->hasMany(ProgramaViaje::class, 'programa_id');
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
