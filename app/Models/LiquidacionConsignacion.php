<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiquidacionConsignacion extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $table = 'liquidaciones_consignacion';

    protected $fillable = [
        'folio_liquidacion',
        'convenio_compra_id',
        'temporada_id',
        'productor_id',
        'periodo_inicio',
        'periodo_fin',
        'total_salidas',
        'total_kilos',
        'total_cantidad',
        'precio_unitario_utilizado',
        'porcentaje_productor',
        'monto_bruto',
        'porcentaje_rezaga_aplicado',
        'descuento_rezaga',
        'monto_productor_calculado',
        'monto_ajustado',
        'monto_final',
        'motivo_ajuste',
        'notas',
        'status',
        'created_by',
    ];

    protected $casts = [
        'total_kilos' => 'decimal:2',
        'total_cantidad' => 'integer',
        'total_salidas' => 'integer',
        'precio_unitario_utilizado' => 'decimal:4',
        'porcentaje_productor' => 'decimal:2',
        'monto_bruto' => 'decimal:2',
        'porcentaje_rezaga_aplicado' => 'decimal:2',
        'descuento_rezaga' => 'decimal:2',
        'monto_productor_calculado' => 'decimal:2',
        'monto_ajustado' => 'decimal:2',
        'monto_final' => 'decimal:2',
        'periodo_inicio' => 'date:Y-m-d',
        'periodo_fin' => 'date:Y-m-d',
    ];

    // ── Constantes ──────────────────────────────────────

    const STATUS_BORRADOR = 'borrador';
    const STATUS_REVISADA = 'revisada';
    const STATUS_APROBADA = 'aprobada';
    const STATUS_PAGADA = 'pagada';
    const STATUS_CANCELADA = 'cancelada';

    // ── Relaciones ──────────────────────────────────────

    public function convenioCompra(): BelongsTo
    {
        return $this->belongsTo(ConvenioCompra::class);
    }

    public function temporada(): BelongsTo
    {
        return $this->belongsTo(Temporada::class);
    }

    public function productor(): BelongsTo
    {
        return $this->belongsTo(Productor::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(LiquidacionConsignacionDetalle::class, 'liquidacion_id');
    }

    // ── Scopes ──────────────────────────────────────────

    public function scopePorConvenio($query, $convenioId)
    {
        return $query->where('convenio_compra_id', $convenioId);
    }

    public function scopePorProductor($query, $productorId)
    {
        return $query->where('productor_id', $productorId);
    }

    public function scopePorTemporada($query, $temporadaId)
    {
        return $query->where('temporada_id', $temporadaId);
    }

    public function scopePorStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    // ── Métodos de negocio ──────────────────────────────

    /**
     * Calcular liquidación a partir de las salidas de campo del convenio en el período.
     * Busca las salidas vinculadas al convenio dentro del rango de fechas,
     * aplica el precio vigente del convenio y calcula el monto del productor.
     */
    public function calcularDesdeSalidas(): self
    {
        $convenio = $this->convenioCompra;

        // Obtener salidas del convenio en el período
        $salidas = SalidaCampoCosecha::where('convenio_compra_id', $this->convenio_compra_id)
            ->where('eliminado', false)
            ->whereBetween('fecha', [$this->periodo_inicio, $this->periodo_fin])
            ->with('tipoCarga:id,nombre')
            ->get();

        // Limpiar detalles previos
        $this->detalles()->delete();

        $totalKilos = 0;
        $totalCantidad = 0;
        $totalSubtotal = 0;

        foreach ($salidas as $salida) {
            // Buscar precio vigente para este tipo de carga
            $precio = $convenio->precioVigente($salida->tipo_carga_id, $salida->fecha);
            $precioUnitario = $precio ? (float) $precio->precio_unitario : 0;

            $subtotal = $salida->cantidad * $precioUnitario;
            $totalSubtotal += $subtotal;
            $totalKilos += (float) ($salida->peso_neto_kg ?? 0);
            $totalCantidad += $salida->cantidad;

            $this->detalles()->create([
                'salida_campo_id' => $salida->id,
                'tipo_carga_id' => $salida->tipo_carga_id,
                'concepto' => sprintf(
                    'Salida %s - %s (%s)',
                    $salida->folio_salida,
                    $salida->fecha->format('d/m/Y'),
                    $salida->tipoCarga->nombre ?? 'N/A'
                ),
                'cantidad' => $salida->cantidad,
                'peso_kg' => $salida->peso_neto_kg ?? 0,
                'precio_unitario' => $precioUnitario,
                'subtotal' => $subtotal,
            ]);
        }

        // Obtener precio/porcentaje del convenio para el cálculo global
        $precioRef = $convenio->precios()
            ->where('is_active', true)
            ->orderByDesc('vigencia_inicio')
            ->first();

        $porcentaje = $precioRef ? (float) $precioRef->porcentaje_productor : 100;
        $porcentajeRezaga = (float) ($convenio->porcentaje_rezaga ?? 0);
        $descuentoRezaga = $totalSubtotal * ($porcentajeRezaga / 100);
        $montoDespuesRezaga = $totalSubtotal - $descuentoRezaga;

        $this->total_salidas = $salidas->count();
        $this->total_kilos = $totalKilos;
        $this->total_cantidad = $totalCantidad;
        $this->precio_unitario_utilizado = $precioRef ? (float) $precioRef->precio_unitario : 0;
        $this->porcentaje_productor = $porcentaje;
        $this->monto_bruto = $totalSubtotal;
        $this->porcentaje_rezaga_aplicado = $porcentajeRezaga;
        $this->descuento_rezaga = $descuentoRezaga;
        $this->monto_productor_calculado = $montoDespuesRezaga * ($porcentaje / 100);
        $this->monto_final = $this->monto_ajustado ?? $this->monto_productor_calculado;

        return $this;
    }

    /**
     * Aplicar ajuste manual al monto.
     */
    public function aplicarAjuste(float $montoAjustado, ?string $motivo = null): self
    {
        $this->monto_ajustado = $montoAjustado;
        $this->motivo_ajuste = $motivo;
        $this->monto_final = $montoAjustado;

        return $this;
    }
}
