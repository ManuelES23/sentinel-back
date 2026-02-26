<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Temporada extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'cultivo_id',
        'nombre',
        'locacion',
        'folio_temporada',
        'año_inicio',
        'año_fin',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'fecha_cierre_real',
        'user_id',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_cierre_real' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con Cultivo
     */
    public function cultivo()
    {
        return $this->belongsTo(Cultivo::class);
    }

    /**
     * Relación con Usuario
     */
    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relación Many-to-Many con Productores (activos en esta temporada)
     */
    public function productores()
    {
        return $this->belongsToMany(Productor::class, 'temporada_productor')
            ->withPivot('notas', 'is_active')
            ->withTimestamps();
    }

    /**
     * Relación Many-to-Many con Zonas de Cultivo
     */
    public function zonasCultivo()
    {
        return $this->belongsToMany(ZonaCultivo::class, 'temporada_zona_cultivo')
            ->withPivot('superficie_asignada', 'notas', 'is_active')
            ->withTimestamps();
    }

    /**
     * Relación Many-to-Many con Lotes
     */
    public function lotes()
    {
        return $this->belongsToMany(Lote::class, 'temporada_lote')
            ->withPivot('cultivo_id', 'superficie_sembrada', 'fecha_siembra', 'fecha_cosecha_estimada', 'notas', 'is_active')
            ->withTimestamps();
    }

    /**
     * Scope para obtener productores activos en esta temporada
     */
    public function productoresActivos()
    {
        return $this->productores()->wherePivot('is_active', true);
    }

    /**
     * Scope para obtener zonas activas en esta temporada
     */
    public function zonasCultivoActivas()
    {
        return $this->zonasCultivo()->wherePivot('is_active', true);
    }

    /**
     * Scope para obtener lotes activos en esta temporada
     */
    public function lotesActivos()
    {
        return $this->lotes()->wherePivot('is_active', true);
    }

    /**
     * Generar el siguiente folio para un cultivo
     */
    public static function generarFolio($cultivoId)
    {
        // Obtener el último consecutivo para este cultivo
        /** @var Temporada|null $ultimaTemporada */
        /** @phpstan-ignore-next-line */
        $ultimaTemporada = self::where('cultivo_id', $cultivoId)
            ->orderBy('id', 'desc')
            ->first();

        if (! $ultimaTemporada) {
            // Primera temporada de este cultivo
            $consecutivo = 1;
        } else {
            // Extraer el consecutivo del último folio y sumar 1
            $partes = explode('-', $ultimaTemporada->folio_temporada);
            $consecutivo = isset($partes[1]) ? intval($partes[1]) + 1 : 1;
        }

        // Formato: CultivoID-Consecutivo (ej: 3-001)
        return $cultivoId.'-'.str_pad($consecutivo, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Asignar productor a esta temporada
     */
    public function asignarProductor($productorId, $notas = null)
    {
        return $this->productores()->attach($productorId, [
            'notas' => $notas,
            'is_active' => true,
        ]);
    }

    /**
     * Asignar zona de cultivo a esta temporada
     */
    public function asignarZonaCultivo($zonaCultivoId, $superficieAsignada = null, $notas = null)
    {
        return $this->zonasCultivo()->attach($zonaCultivoId, [
            'superficie_asignada' => $superficieAsignada,
            'notas' => $notas,
            'is_active' => true,
        ]);
    }

    /**
     * Asignar lote a esta temporada con cultivo
     */
    public function asignarLote($loteId, $cultivoId, $superficieSembrada = null, $fechaSiembra = null, $notas = null, $fechaCosechaEstimada = null)
    {
        return $this->lotes()->attach($loteId, [
            'cultivo_id' => $cultivoId,
            'superficie_sembrada' => $superficieSembrada,
            'fecha_siembra' => $fechaSiembra,
            'fecha_cosecha_estimada' => $fechaCosechaEstimada,
            'notas' => $notas,
            'is_active' => true,
        ]);
    }

    /**
     * Obtener resumen de la temporada
     */
    public function resumen()
    {
        return [
            'productores_activos' => $this->productoresActivos()->count(),
            'zonas_activas' => $this->zonasCultivoActivas()->count(),
            'lotes_activos' => $this->lotesActivos()->count(),
            'superficie_total_sembrada' => $this->lotesActivos()->sum('temporada_lote.superficie_sembrada'),
        ];
    }
}
