<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AjustePesoRezaga extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'ajuste_peso_rezaga';

    protected $fillable = [
        'temporada_id',
        'entity_id',
        'rezaga_empaque_id',
        'folio_ajuste',
        'fecha_ajuste',
        'kg_antes',
        'kg_despues',
        'motivo',
        'observaciones',
        'created_by',
    ];

    protected $casts = [
        'kg_antes'    => 'decimal:2',
        'kg_despues'  => 'decimal:2',
        'kg_perdido'  => 'decimal:2',
        'fecha_ajuste' => 'date:Y-m-d',
    ];

    /* ── Relaciones ── */
    public function temporada()    { return $this->belongsTo(Temporada::class); }
    public function entity()       { return $this->belongsTo(Entity::class); }
    public function rezaga()       { return $this->belongsTo(RezagaEmpaque::class, 'rezaga_empaque_id'); }
    public function creador()      { return $this->belongsTo(User::class, 'created_by'); }

    /* ── Scopes ── */
    public function scopeByTemporada($query, $id)  { return $query->where('temporada_id', $id); }
    public function scopeByEntity($query, $id)      { return $query->where('entity_id', $id); }
    public function scopeByRezaga($query, $id)      { return $query->where('rezaga_empaque_id', $id); }
}
