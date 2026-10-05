<?php

namespace App\Services\ActivosFijos;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\UserEnterpriseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Qué empresas ve una petición del módulo Activos Fijos. La empresa sale del
 * segmento de la URL (/api/{empresa}/administration/activos-fijos/...), no
 * del header X-Enterprise-Slug, y se valida contra user_enterprise_access.
 * Grupo Espléndido es la vista corporativa: ve los activos de todas las
 * empresas que tienen el módulo.
 */
class AlcanceActivos
{
    public const EMPRESA_CORPORATIVA = 'grupoesplendido';

    private const ATRIBUTO = 'activos_fijos.empresa';

    public function empresaActual(Request $request): Enterprise
    {
        if ($request->attributes->has(self::ATRIBUTO)) {
            return $request->attributes->get(self::ATRIBUTO);
        }

        $empresa = Enterprise::where('slug', $request->segment(2))->first();
        abort_unless($empresa, 404);

        $usuario = $request->user();
        $tieneAcceso = EnsureUserIsAdmin::esAdmin($usuario)
            || UserEnterpriseAccess::where('user_id', $usuario->id)
                ->where('enterprise_id', $empresa->id)
                ->where('is_active', true)
                ->exists();
        abort_unless($tieneAcceso, 403, 'No tienes acceso a esta empresa.');

        $request->attributes->set(self::ATRIBUTO, $empresa);

        return $empresa;
    }

    public function esCorporativo(Request $request): bool
    {
        return $this->empresaActual($request)->slug === self::EMPRESA_CORPORATIVA;
    }

    /** @return Collection<int, Enterprise> */
    public function empresasVisibles(Request $request): Collection
    {
        $actual = $this->empresaActual($request);

        if (! $this->esCorporativo($request)) {
            return collect([$actual]);
        }

        return Enterprise::query()
            ->whereHas('applications', fn ($a) => $a->where('slug', 'administration')
                ->whereHas('modules', fn ($m) => $m->where('slug', 'activos-fijos')))
            ->orderBy('name')
            ->get();
    }

    /** @return array<int> */
    public function idsVisibles(Request $request): array
    {
        return $this->empresasVisibles($request)->pluck('id')->all();
    }

    public function aplicar(Builder $query, Request $request): Builder
    {
        return $query->whereIn($query->getModel()->getTable().'.enterprise_id', $this->idsVisibles($request));
    }

    /** 404 y no 403: no se revela que el ID existe en otra empresa. */
    public function autorizarActivo(FixedAsset $activo, Request $request): void
    {
        abort_unless(in_array($activo->enterprise_id, $this->idsVisibles($request), true), 404);
    }

    /**
     * Empresa dueña de un alta. Fuera de GE siempre es la actual (se ignora
     * lo que mande el cliente). En GE es obligatorio elegirla entre las visibles.
     */
    public function empresaParaAlta(Request $request): Enterprise
    {
        if (! $this->esCorporativo($request)) {
            return $this->empresaActual($request);
        }

        $empresa = $this->empresasVisibles($request)->firstWhere('id', (int) $request->input('enterprise_id'));

        if (! $empresa) {
            throw ValidationException::withMessages(['enterprise_id' => 'Selecciona la empresa dueña del activo.']);
        }

        return $empresa;
    }
}
