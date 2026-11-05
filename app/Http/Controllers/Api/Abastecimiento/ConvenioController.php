<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioMeta;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\Variedad;
use App\Models\Enterprise;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\ReglasConvenio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Convenios de abastecimiento con sus precios por tipo de carga y vigencia
 * (submódulo `convenios`). Las reglas de volumen y de estados llegan después;
 * aquí el volumen pactado y las metas se guardan tal como vienen.
 */
class ConvenioController extends AbastecimientoController
{
    private const SUBMODULO = 'convenios';

    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa);

        $convenio = DB::transaction(function () use ($request, $empresa, $datos) {
            $convenio = Convenio::create($this->columnas($datos) + [
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'CV'),
                'status' => 'borrador',
                'created_by' => $request->user()?->id,
            ]);

            $this->guardarPrecios($convenio, $datos['precios']);
            $this->guardarMetas($convenio, $datos['metas'] ?? []);

            return $convenio;
        });

        $data = $this->presentar($convenio);
        $this->difundir('convenio', 'created', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Convenio creado.', 'data' => $data], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $convenio = Convenio::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $this->validar($request, $empresa);

        DB::transaction(function () use ($convenio, $datos) {
            $convenio->update($this->columnas($datos));

            // Los precios se reemplazan por la lista enviada; las metas solo si vienen.
            $this->guardarPrecios($convenio, $datos['precios']);
            if (array_key_exists('metas', $datos)) {
                $this->guardarMetas($convenio, $datos['metas'] ?? []);
            }
        });

        $data = $this->presentar($convenio->refresh());
        $this->difundir('convenio', 'updated', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Convenio actualizado.', 'data' => $data]);
    }

    /** Convenios no cancelados del mismo productor y variedad cuyas fechas se tocan con el rango. */
    public function encimados(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $datos = $request->validate([
            'productor_id' => ['required', 'integer'],
            'variedad_id' => ['required', 'integer'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'excluir_id' => ['nullable', 'integer'],
        ]);

        $convenios = Convenio::deEmpresa($empresa->id)
            ->where('productor_id', $datos['productor_id'])
            ->where('variedad_id', $datos['variedad_id'])
            ->where('status', '!=', 'cancelado')
            ->whereDate('fecha_inicio', '<=', $datos['fecha_fin'])
            ->whereDate('fecha_fin', '>=', $datos['fecha_inicio'])
            ->when(! empty($datos['excluir_id']), fn ($q) => $q->where('id', '!=', $datos['excluir_id']))
            ->orderBy('fecha_inicio')->orderBy('id')
            ->get(['id', 'folio', 'fecha_inicio', 'fecha_fin', 'status']);

        return response()->json([
            'success' => true,
            'data' => $convenios->map(fn (Convenio $c) => [
                'id' => $c->id,
                'folio' => $c->folio,
                'fecha_inicio' => $c->fecha_inicio->format('Y-m-d'),
                'fecha_fin' => $c->fecha_fin->format('Y-m-d'),
                'status' => $c->status,
            ])->values(),
        ]);
    }

    /** Valida el cuerpo y deja `cultivo_id` (el de la temporada) y los precios normalizados. */
    private function validar(Request $request, Enterprise $empresa): array
    {
        $viva = fn (string $tabla) => Rule::exists($tabla, 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');

        $datos = $request->validate([
            'temporada_id' => ['required', 'integer', $viva('abs_temporadas')],
            'productor_id' => ['required', 'integer', $viva('abs_productores')],
            'variedad_id' => ['required', 'integer'],
            'modalidad' => ['required', Rule::in(Convenio::MODALIDADES)],
            'unidad' => ['required', Rule::in(Convenio::UNIDADES)],
            'moneda' => ['required', Rule::in(Convenio::MONEDAS)],
            'porcentaje_rezaga' => ['required', 'numeric', 'between:0,100'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'modo_volumen' => ['required', Rule::in(Convenio::MODOS_VOLUMEN)],
            'volumen_total' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'metas' => ['nullable', 'array'],
            'metas.*.semana' => ['required', 'date_format:Y-m-d', 'distinct'],
            'metas.*.cantidad' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'precios' => ['required', 'array', 'min:1'],
            'precios.*.tipo_carga_id' => ['required', 'integer', $viva('abs_tipos_carga')],
            'precios.*.precio' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'precios.*.porcentaje_productor' => ['nullable', 'numeric', 'between:0,100'],
            'precios.*.vigencia_inicio' => ['required', 'date_format:Y-m-d'],
            'precios.*.es_principal' => ['nullable', 'boolean'],
            'precios.*.notas' => ['nullable', 'string', 'max:500'],
        ]);

        // El cultivo es el de la temporada y la variedad debe ser de ese cultivo.
        $temporada = Temporada::deEmpresa($empresa->id)->findOrFail($datos['temporada_id']);
        $variedadValida = Variedad::where('cultivo_id', $temporada->cultivo_id)->whereKey($datos['variedad_id'])->exists();
        if (! $variedadValida) {
            throw ValidationException::withMessages([
                'variedad_id' => 'La variedad no pertenece al cultivo de la temporada.',
            ]);
        }
        $datos['cultivo_id'] = $temporada->cultivo_id;

        ReglasConvenio::validarPrecios($datos['precios'], $datos['modalidad']);
        $datos['precios'] = ReglasConvenio::normalizarPrecios($datos['precios'], $datos['modalidad']);

        return $datos;
    }

    private function columnas(array $datos): array
    {
        return collect($datos)->only([
            'temporada_id', 'productor_id', 'cultivo_id', 'variedad_id', 'modalidad', 'unidad', 'moneda',
            'porcentaje_rezaga', 'fecha_inicio', 'fecha_fin', 'modo_volumen', 'volumen_total', 'notas',
        ])->all();
    }

    /**
     * Deja exactamente la lista enviada: los renglones que no vienen se borran
     * (borrado lógico) y uno que choca con un renglón borrado del mismo tipo y
     * vigencia lo restaura en lugar de insertar (el índice único cuenta los borrados).
     */
    private function guardarPrecios(Convenio $convenio, array $filas): void
    {
        $conservados = [];

        foreach ($filas as $fila) {
            $precio = ConvenioPrecio::withTrashed()
                ->where('convenio_id', $convenio->id)
                ->where('tipo_carga_id', $fila['tipo_carga_id'])
                ->whereDate('vigencia_inicio', $fila['vigencia_inicio'])
                ->first();

            if ($precio === null) {
                $precio = ConvenioPrecio::create($fila + ['convenio_id' => $convenio->id]);
            } else {
                if ($precio->trashed()) {
                    $precio->restore();
                }
                $precio->update($fila);
            }
            $conservados[] = $precio->id;
        }

        ConvenioPrecio::where('convenio_id', $convenio->id)
            ->whereNotIn('id', $conservados)
            ->get()
            ->each->delete();
    }

    private function guardarMetas(Convenio $convenio, array $metas): void
    {
        $convenio->metas()->delete();

        foreach ($metas as $meta) {
            ConvenioMeta::create([
                'convenio_id' => $convenio->id,
                'semana' => $meta['semana'],
                'cantidad' => $meta['cantidad'],
            ]);
        }
    }

    private function presentar(Convenio $c): array
    {
        $c->load(['precios.tipoCarga:id,nombre', 'metas']);

        return [
            'id' => $c->id,
            'folio' => $c->folio,
            'temporada_id' => $c->temporada_id,
            'productor_id' => $c->productor_id,
            'cultivo_id' => $c->cultivo_id,
            'variedad_id' => $c->variedad_id,
            'modalidad' => $c->modalidad,
            'unidad' => $c->unidad,
            'moneda' => $c->moneda,
            'porcentaje_rezaga' => $c->porcentaje_rezaga,
            'fecha_inicio' => $c->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $c->fecha_fin?->format('Y-m-d'),
            'modo_volumen' => $c->modo_volumen,
            'volumen_total' => $c->volumen_total,
            'status' => $c->status,
            'notas' => $c->notas,
            'precios' => $c->precios
                ->sortBy([['es_principal', 'desc'], ['tipo_carga_id', 'asc'], ['vigencia_inicio', 'asc']])
                ->map(fn (ConvenioPrecio $p) => [
                    'id' => $p->id,
                    'tipo_carga_id' => $p->tipo_carga_id,
                    'tipo_carga' => $p->tipoCarga ? ['id' => $p->tipoCarga->id, 'nombre' => $p->tipoCarga->nombre] : null,
                    'precio' => $p->precio,
                    'porcentaje_productor' => $p->porcentaje_productor,
                    'vigencia_inicio' => $p->vigencia_inicio->format('Y-m-d'),
                    'es_principal' => $p->es_principal,
                    'notas' => $p->notas,
                ])->values()->all(),
            'metas' => $c->metas
                ->sortBy(fn (ConvenioMeta $m) => $m->semana->format('Y-m-d'))
                ->map(fn (ConvenioMeta $m) => [
                    'semana' => $m->semana->format('Y-m-d'),
                    'cantidad' => $m->cantidad,
                ])->values()->all(),
        ];
    }
}
