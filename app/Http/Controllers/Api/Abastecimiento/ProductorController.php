<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Productor;
use App\Models\Enterprise;
use App\Services\Abastecimiento\SaldoProductor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Productores y empaques que surten a la empresa (submódulo `productores`).
 * El nombre NO es único: un posible duplicado solo se avisa (`posibles-duplicados`)
 * y nunca bloquea el alta; por eso aquí no aplica la restauración de filas borradas.
 */
class ProductorController extends AbastecimientoController
{
    private const SUBMODULO = 'productores';

    private const MENSAJE_EN_USO = 'Ya se usó en un convenio: desactívalo en lugar de borrarlo.';

    private const POR_PAGINA = 50;

    private const POR_PAGINA_MAX = 200;

    private const MAX_DUPLICADOS = 5;

    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::in(Productor::TIPOS)],
        ]);

        $query = Productor::deEmpresa($empresa->id)
            ->with('cultivos:id,nombre')
            ->withCount('convenios')
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->input('tipo')))
            ->when($request->boolean('activos'), fn ($q) => $q->where('is_active', true));

        if ($request->filled('search')) {
            // '!' como carácter de escape: funciona igual en MySQL y SQLite.
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $request->input('search')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw("abs_productores.nombre LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("abs_productores.rfc LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("abs_productores.contacto LIKE ? ESCAPE '!'", [$term]);
            });
        }

        $porPagina = min(max((int) $request->input('per_page', self::POR_PAGINA), 1), self::POR_PAGINA_MAX);
        $pagina = $query->orderBy('nombre')->orderBy('id')->paginate($porPagina);
        // Saldo de la página completa en una sola llamada (nunca uno por productor) y solo para quien ve dinero.
        $saldos = $this->verDinero($request, $empresa)
            ? SaldoProductor::porProductores($empresa, $pagina->getCollection()->pluck('id')->all())
            : null;
        $pagina->getCollection()->transform(fn (Productor $p) => $this->presentar($p) + ['saldo' => $saldos === null ? null : self::saldoDe($saldos, $p->id)]);

        return response()->json(['success' => true, 'data' => $pagina]);
    }

    /** Activos para selectores, sin paginar. */
    public function list(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');

        $productores = Productor::deEmpresa($empresa->id)
            ->where('is_active', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'tipo']);
        $saldos = $this->verDinero($request, $empresa) ? SaldoProductor::porProductores($empresa, $productores->pluck('id')->all()) : null;

        return response()->json([
            'success' => true,
            'data' => $productores->map(fn (Productor $p) => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'tipo' => $p->tipo,
                'saldo' => $saldos === null ? null : self::saldoDe($saldos, $p->id),
            ])->values()->all(),
        ]);
    }

    /** El saldo es dinero: solo con Abonos o Liquidaciones (`view`); el administrador siempre. */
    private function verDinero(Request $request, Enterprise $empresa): bool
    {
        $usuario = $request->user();

        return $this->ctx->puede($usuario, $empresa, 'abonos', 'view') || $this->ctx->puede($usuario, $empresa, 'liquidaciones', 'view');
    }

    /**
     * Saldo de un productor por moneda (`SaldoProductor`): `[{moneda, por_pagar, anticipos_sin_aplicar, sin_liquidar}]`,
     * vacío si no tiene movimiento.
     */
    private static function saldoDe(Collection $saldos, int $productorId): array
    {
        return collect($saldos->get($productorId, []))->sortKeys()->map(fn (array $s) => [
            'moneda' => $s['moneda'],
            'por_pagar' => $s['por_pagar'],
            'anticipos_sin_aplicar' => $s['anticipos_sin_aplicar'],
            'sin_liquidar' => $s['sin_liquidar'],
        ])->values()->all();
    }

    /** Mismo RFC o mismo nombre (sin acentos ni mayúsculas). Solo avisa. */
    public function posiblesDuplicados(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:150'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'excluir_id' => ['nullable', 'integer'],
        ]);

        $nombre = $this->normalizarNombre($datos['nombre'] ?? '');
        $rfc = mb_strtoupper(trim($datos['rfc'] ?? ''));
        if ($nombre === '' && $rfc === '') {
            return response()->json(['success' => true, 'data' => []]);
        }

        $candidatos = Productor::deEmpresa($empresa->id)
            ->when(! empty($datos['excluir_id']), fn ($q) => $q->where('id', '!=', (int) $datos['excluir_id']))
            ->orderBy('id')
            ->get(['id', 'nombre', 'rfc']);

        $coincidencias = [];
        foreach ($candidatos as $c) {
            $motivo = null;
            if ($rfc !== '' && mb_strtoupper((string) $c->rfc) === $rfc) {
                $motivo = 'rfc';
            } elseif ($nombre !== '' && $this->normalizarNombre($c->nombre) === $nombre) {
                $motivo = 'nombre';
            }
            if ($motivo) {
                $coincidencias[] = ['id' => $c->id, 'nombre' => $c->nombre, 'rfc' => $c->rfc, 'motivo' => $motivo];
            }
        }

        // Primero las de RFC (más fuertes), luego las de nombre.
        usort($coincidencias, fn ($a, $b) => [$a['motivo'] === 'rfc' ? 0 : 1, $a['id']] <=> [$b['motivo'] === 'rfc' ? 0 : 1, $b['id']]);

        return response()->json(['success' => true, 'data' => array_slice($coincidencias, 0, self::MAX_DUPLICADOS)]);
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa->id);

        $productor = DB::transaction(function () use ($empresa, $datos) {
            $cultivoIds = $datos['cultivo_ids'] ?? [];
            unset($datos['cultivo_ids']);

            $productor = Productor::create($datos + ['enterprise_id' => $empresa->id, 'is_active' => $datos['is_active'] ?? true]);
            $productor->cultivos()->sync($cultivoIds);

            return $productor;
        });

        $data = $this->presentar($productor->refresh()->load('cultivos:id,nombre')->loadCount('convenios'));
        $this->difundir('productor', 'created', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Productor creado.', 'data' => $data], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $productor = Productor::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $this->validar($request, $empresa->id);

        DB::transaction(function () use ($productor, $datos) {
            $cultivoIds = $datos['cultivo_ids'] ?? null;
            unset($datos['cultivo_ids']);

            $productor->update($datos);
            // Sin la clave se conservan los cultivos; con [] se quitan todos.
            if ($cultivoIds !== null) {
                $productor->cultivos()->sync($cultivoIds);
            }
        });

        $data = $this->presentar($productor->refresh()->load('cultivos:id,nombre')->loadCount('convenios'));
        $this->difundir('productor', 'updated', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Productor actualizado.', 'data' => $data]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        $productor = Productor::deEmpresa($empresa->id)->findOrFail($id);

        if ($productor->convenios()->exists()) {
            throw ValidationException::withMessages(['id' => self::MENSAJE_EN_USO]);
        }

        DB::transaction(function () use ($productor) {
            $productor->cultivos()->detach();
            $productor->delete();
        });

        $this->difundir('productor', 'deleted', ['id' => $productor->id], $empresa);

        return response()->json(['success' => true, 'message' => 'Productor eliminado.', 'data' => ['id' => $productor->id]]);
    }

    private function validar(Request $request, int $empresaId): array
    {
        // El RFC se guarda en mayúsculas y sin espacios en los bordes.
        if (is_string($request->input('rfc'))) {
            $request->merge(['rfc' => mb_strtoupper(trim($request->input('rfc')))]);
        }

        return $request->validate([
            'tipo' => ['required', Rule::in(Productor::TIPOS)],
            'nombre' => ['required', 'string', 'max:150'],
            'rfc' => ['nullable', 'string', 'min:12', 'max:13'],
            'contacto' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'ubicacion' => ['nullable', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'cultivo_ids' => ['sometimes', 'array', 'max:100'],
            'cultivo_ids.*' => [
                'integer', 'distinct',
                Rule::exists('abs_cultivos', 'id')->where('enterprise_id', $empresaId)->whereNull('deleted_at'),
            ],
        ]);
    }

    /** Minúsculas, sin acentos y con los espacios colapsados. */
    private function normalizarNombre(string $nombre): string
    {
        return preg_replace('/\s+/u', ' ', trim(mb_strtolower(Str::ascii($nombre)))) ?? '';
    }

    private function presentar(Productor $p): array
    {
        $convenios = (int) ($p->convenios_count ?? 0);

        return [
            'id' => $p->id,
            'tipo' => $p->tipo,
            'nombre' => $p->nombre,
            'rfc' => $p->rfc,
            'contacto' => $p->contacto,
            'telefono' => $p->telefono,
            'email' => $p->email,
            'ubicacion' => $p->ubicacion,
            'notas' => $p->notas,
            'is_active' => (bool) $p->is_active,
            'cultivos' => $p->cultivos->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre])->values()->all(),
            'convenios_count' => $convenios,
            'en_uso' => $convenios > 0,
        ];
    }
}
