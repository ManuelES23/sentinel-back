<?php

namespace App\Models\Planeacion;

use App\Models\Cultivo;
use App\Models\Variedad;
use App\Services\Planeacion\CalculadoraBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Renglón de sobrante (Programa de empaque): cajas por día, de lunes (cajas_0) a
 * domingo (cajas_6), que se empacan para una bodega con una o más variedades del
 * mismo grupo de balance. Como una necesidad, pero sin cliente y con tipo de
 * empaque obligatorio.
 */
class ProgramaSobrante extends Model
{
    protected $table = 'programa_sobrantes';

    protected $fillable = [
        'programa_id',
        'bodega_id',
        'cultivo_id',
        'presentacion_id',
        'tipo_empaque_id',
        'cajas_0',
        'cajas_1',
        'cajas_2',
        'cajas_3',
        'cajas_4',
        'cajas_5',
        'cajas_6',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'programa_id' => 'integer',
        'bodega_id' => 'integer',
        'cultivo_id' => 'integer',
        'presentacion_id' => 'integer',
        'tipo_empaque_id' => 'integer',
        'cajas_0' => 'integer',
        'cajas_1' => 'integer',
        'cajas_2' => 'integer',
        'cajas_3' => 'integer',
        'cajas_4' => 'integer',
        'cajas_5' => 'integer',
        'cajas_6' => 'integer',
    ];

    public function programa(): BelongsTo
    {
        return $this->belongsTo(ProgramaSemanal::class, 'programa_id');
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(BodegaDestino::class, 'bodega_id');
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
        return $this->belongsToMany(Variedad::class, 'programa_sobrante_variedad', 'sobrante_id', 'variedad_id');
    }

    /**
     * Las siete cantidades de la semana como arreglo [lunes … domingo].
     *
     * @return array<int, int>
     */
    public function cajasPorDia(): array
    {
        return array_map(fn (int $dia) => (int) $this->{"cajas_{$dia}"}, range(0, CalculadoraBalance::DIAS - 1));
    }
}
