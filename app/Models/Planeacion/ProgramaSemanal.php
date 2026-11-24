<?php

namespace App\Models\Planeacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Programa de una semana (de lunes a domingo), identificada por su lunes.
 * Los días de sus renglones van de 0 (lunes) a 6 (domingo).
 *
 * Domingo por proceso: `domingo_corte`, `domingo_empaque` y `domingo_embarques`
 * dicen si cada proceso trabaja el domingo (sin domingo, su día 6 queda en
 * cero). `trabaja_domingo` se conserva y guarda «alguno de los tres»; el
 * endpoint del domingo lo mantiene al día.
 */
class ProgramaSemanal extends Model
{
    /** Procesos con interruptor de domingo propio (columna `domingo_{proceso}`). */
    public const PROCESOS = ['corte', 'empaque', 'embarques'];

    protected $table = 'programas_semanales';

    protected $fillable = [
        'lunes', 'notas', 'trabaja_domingo', 'domingo_corte', 'domingo_empaque', 'domingo_embarques',
        'junta_registrada_at', 'junta_registrada_por',
    ];

    /** Igual que el default de las columnas: un programa recién creado ya los trae en memoria. */
    protected $attributes = [
        'trabaja_domingo' => true,
        'domingo_corte' => true,
        'domingo_empaque' => true,
        'domingo_embarques' => true,
    ];

    protected $casts = [
        'lunes' => 'date:Y-m-d',
        'trabaja_domingo' => 'boolean',
        'domingo_corte' => 'boolean',
        'domingo_empaque' => 'boolean',
        'domingo_embarques' => 'boolean',
        'junta_registrada_at' => 'datetime',
        'junta_registrada_por' => 'integer',
    ];

    /**
     * Columna de la bandera de un proceso. Un proceso desconocido es un error de
     * programación, no del usuario.
     */
    public static function columnaDomingo(string $proceso): string
    {
        if (! in_array($proceso, self::PROCESOS, true)) {
            throw new \InvalidArgumentException("Proceso de domingo desconocido: {$proceso}.");
        }

        return 'domingo_'.$proceso;
    }

    /** Si el proceso trabaja el domingo. Sin la columna (antes del migrate) se lee como «sí». */
    public function trabajaDomingo(string $proceso): bool
    {
        return (bool) ($this->getAttribute(self::columnaDomingo($proceso)) ?? true);
    }

    /** Si alguno de los tres procesos trabaja el domingo. */
    public function trabajaAlgunDomingo(): bool
    {
        foreach (self::PROCESOS as $proceso) {
            if ($this->trabajaDomingo($proceso)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{corte: bool, empaque: bool, embarques: bool} */
    public function domingoPorProceso(): array
    {
        $banderas = [];
        foreach (self::PROCESOS as $proceso) {
            $banderas[$proceso] = $this->trabajaDomingo($proceso);
        }

        return $banderas;
    }

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
