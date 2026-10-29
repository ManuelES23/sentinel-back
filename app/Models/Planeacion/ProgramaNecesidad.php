<?php

namespace App\Models\Planeacion;

use App\Models\Cultivo;
use App\Models\CRM\CrmCliente;
use App\Models\Variedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Renglón de necesidad (ventas): cajas por día, de lunes (cajas_0) a sábado
 * (cajas_5), que pide un cliente en una bodega, con una o más variedades
 * aceptadas del mismo grupo de balance.
 */
class ProgramaNecesidad extends Model
{
    protected $table = 'programa_necesidades';

    protected $fillable = [
        'programa_id',
        'bodega_id',
        'cliente_id',
        'cliente_nombre',
        'cultivo_id',
        'presentacion_id',
        'tipo_empaque_id',
        'cajas_0',
        'cajas_1',
        'cajas_2',
        'cajas_3',
        'cajas_4',
        'cajas_5',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'programa_id' => 'integer',
        'bodega_id' => 'integer',
        'cliente_id' => 'integer',
        'cultivo_id' => 'integer',
        'presentacion_id' => 'integer',
        'tipo_empaque_id' => 'integer',
        'cajas_0' => 'integer',
        'cajas_1' => 'integer',
        'cajas_2' => 'integer',
        'cajas_3' => 'integer',
        'cajas_4' => 'integer',
        'cajas_5' => 'integer',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(BodegaDestino::class, 'bodega_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(CrmCliente::class, 'cliente_id');
    }

    public function cultivo(): BelongsTo
    {
        return $this->belongsTo(Cultivo::class);
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(Presentacion::class, 'presentacion_id');
    }

    public function tipoEmpaque(): BelongsTo
    {
        return $this->belongsTo(TipoEmpaque::class, 'tipo_empaque_id');
    }

    public function variedades(): BelongsToMany
    {
        return $this->belongsToMany(Variedad::class, 'programa_necesidad_variedad', 'necesidad_id', 'variedad_id');
    }

    /**
     * Las seis cantidades de la semana como arreglo [lunes … sábado].
     *
     * @return array<int, int>
     */
    public function cajasPorDia(): array
    {
        return array_map(fn (int $dia) => (int) $this->{"cajas_{$dia}"}, range(0, 5));
    }
}
