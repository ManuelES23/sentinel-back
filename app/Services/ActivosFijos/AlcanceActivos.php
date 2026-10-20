<?php

namespace App\Services\ActivosFijos;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetDisposal;
use App\Models\User;
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

    public const MENSAJE_SIN_ACCESO = 'No tienes acceso a esta empresa.';

    private const ATRIBUTO = 'activos_fijos.empresa';

    private const ATRIBUTO_VISIBLES = 'activos_fijos.empresas_visibles';

    public function empresaActual(Request $request): Enterprise
    {
        if ($request->attributes->has(self::ATRIBUTO)) {
            return $request->attributes->get(self::ATRIBUTO);
        }

        $empresa = Enterprise::where('slug', $request->segment(2))->first();
        abort_unless($empresa, 404);

        abort_unless(self::tieneAcceso($request->user(), $empresa), 403, self::MENSAJE_SIN_ACCESO);

        $this->recordarEmpresa($request, $empresa);

        return $empresa;
    }

    /** Admin del sistema o acceso activo en user_enterprise_access. */
    public static function tieneAcceso(?User $usuario, Enterprise $empresa): bool
    {
        if (! $usuario) {
            return false;
        }

        return EnsureUserIsAdmin::esAdmin($usuario)
            || UserEnterpriseAccess::where('user_id', $usuario->id)
                ->where('enterprise_id', $empresa->id)
                ->where('is_active', true)
                ->exists();
    }

    /**
     * Deja la empresa YA VALIDADA (existe y el usuario tiene acceso) como la
     * empresa actual de la petición; `empresaActual` ya no vuelve a consultar.
     * Lo usa el middleware EmpresaDeLaRuta: solo debe llamarse después de
     * comprobar `tieneAcceso`.
     */
    public function recordarEmpresa(Request $request, Enterprise $empresa): void
    {
        $request->attributes->set(self::ATRIBUTO, $empresa);
    }

    public function esCorporativo(Request $request): bool
    {
        return $this->empresaActual($request)->slug === self::EMPRESA_CORPORATIVA;
    }

    /** @return Collection<int, Enterprise> */
    public function empresasVisibles(Request $request): Collection
    {
        // Se calcula una vez por petición (el resumen llama a `aplicar` una docena de veces).
        if ($request->attributes->has(self::ATRIBUTO_VISIBLES)) {
            return $request->attributes->get(self::ATRIBUTO_VISIBLES);
        }

        $actual = $this->empresaActual($request);

        $visibles = ! $this->esCorporativo($request)
            ? collect([$actual])
            : Enterprise::query()
                ->whereHas('applications', fn ($a) => $a->where('slug', 'administration')
                    ->whereHas('modules', fn ($m) => $m->where('slug', 'activos-fijos')))
                ->orderBy('name')
                ->get();

        $request->attributes->set(self::ATRIBUTO_VISIBLES, $visibles);

        return $visibles;
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

    /** 404 y no 403, igual que con los activos: no se revela que la asignación existe. */
    public function autorizarAsignacion(FixedAssetAssignment $asignacion, Request $request): void
    {
        abort_unless(in_array($asignacion->enterprise_id, $this->idsVisibles($request), true), 404);
    }

    /** 404 y no 403, igual que con los activos y las asignaciones: no se revela que la salida existe. */
    public function autorizarSalida(FixedAssetDisposal $salida, Request $request): void
    {
        abort_unless(in_array($salida->enterprise_id, $this->idsVisibles($request), true), 404);
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
