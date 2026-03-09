<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CosteoAgricola extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $table = 'costeos_agricolas';

    protected $fillable = [
        'temporada_id',
        'lote_id',
        'etapa_id',
        'tipo_fuente',
        'fuente_id',
        'product_id',
        'descripcion',
        'categoria',
        'cantidad',
        'unit_id',
        'costo_unitario',
        'costo_total',
        'fecha',
        'user_id',
        'notas',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'costo_unitario' => 'decimal:4',
        'costo_total' => 'decimal:2',
        'fecha' => 'date',
    ];

    const TIPO_FUENTE_REQUISICION = 'requisicion';
    const TIPO_FUENTE_ORDEN_COMPRA = 'orden_compra';
    const TIPO_FUENTE_MOVIMIENTO = 'movimiento_inventario';
    const TIPO_FUENTE_MANUAL = 'manual';

    const CATEGORIAS = [
        'fertilizante' => 'Fertilizante',
        'agroquimico' => 'Agroquímico',
        'semilla' => 'Semilla',
        'mano_de_obra' => 'Mano de Obra',
        'maquinaria' => 'Maquinaria',
        'riego' => 'Riego',
        'transporte' => 'Transporte',
        'empaque' => 'Empaque',
        'otro' => 'Otro',
    ];

    // ═══════ RELACIONES ═══════

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ═══════ SCOPES ═══════

    public function scopeByTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopeByLote($query, $loteId)
    {
        return $query->where('lote_id', $loteId);
    }

    public function scopeByEtapa($query, $etapaId)
    {
        return $query->where('etapa_id', $etapaId);
    }

    public function scopeByCategoria($query, $categoria)
    {
        return $query->where('categoria', $categoria);
    }

    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('fecha', [$desde, $hasta]);
    }
}
