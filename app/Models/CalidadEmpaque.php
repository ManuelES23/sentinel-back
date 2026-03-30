<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalidadEmpaque extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'calidad_empaque';

    protected $fillable = [
        'temporada_id', 'entity_id', 'tipo_evaluacion', 'evaluable_type',
        'evaluable_id', 'folio_evaluacion', 'fecha_evaluacion', 'resultado',
        'porcentaje_defectos', 'defectos_encontrados', 'temperatura',
        'humedad', 'observaciones', 'evaluado_por', 'created_by',
    ];

    protected $casts = [
        'porcentaje_defectos' => 'decimal:2',
        'temperatura' => 'decimal:1',
        'humedad' => 'decimal:1',
        'fecha_evaluacion' => 'date:Y-m-d',
    ];

    public function temporada() { return $this->belongsTo(Temporada::class); }
    public function entity() { return $this->belongsTo(Entity::class); }
    public function evaluable() { return $this->morphTo(); }
    public function evaluadoPor() { return $this->belongsTo(User::class, 'evaluado_por'); }
    public function creador() { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeByTemporada($query, $id) { return $query->where('temporada_id', $id); }
    public function scopeByTipoEvaluacion($query, $t) { return $query->where('tipo_evaluacion', $t); }
    public function scopeByResultado($query, $r) { return $query->where('resultado', $r); }
}
