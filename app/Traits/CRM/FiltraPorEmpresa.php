<?php

namespace App\Traits\CRM;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Exists;

trait FiltraPorEmpresa
{
    /**
     * Devuelve el enterprise_id de contexto: primero intenta leerlo del
     * header X-Enterprise-Id (numérico), luego busca en el user autenticado.
     *
     * Los controladores CRM deben llamar a getEmpresaId() para obtener el
     * scope de empresa y aplicarlo en todos los queries.
     *
     * IMPORTANTE: los headers X-Enterprise-Id / X-Enterprise-Slug son
     * proporcionados por el cliente y NO son de confianza por sí solos —
     * cualquier usuario autenticado podría mandar el ID de otra empresa.
     * Antes de aceptarlos se verifica que el usuario autenticado tenga un
     * UserEnterpriseAccess activo para esa empresa.
     */
    protected function getEmpresaId(): ?int
    {
        $request = request();
        $user = Auth::user();

        // Opción 1: header numérico directo
        $headerEmpresaId = null;
        $fromHeader = $request->header('X-Enterprise-Id');
        if ($fromHeader && ctype_digit((string) $fromHeader)) {
            $headerEmpresaId = (int) $fromHeader;
        } else {
            // Opción 2: resolver empresa por slug desde header
            $slug = $request->header('X-Enterprise-Slug');
            if ($slug) {
                $headerEmpresaId = \App\Models\Enterprise::where('slug', $slug)->value('id');
            }
        }

        if ($headerEmpresaId !== null) {
            // El header es un dato del cliente: solo se acepta si el usuario
            // autenticado tiene acceso activo a esa empresa. Si no lo tiene,
            // se rechaza explícitamente (no se cae a la Opción 3) para no
            // devolver silenciosamente datos de una empresa distinta a la
            // que el cliente pidió.
            if (! $user) {
                return null;
            }

            $tieneAcceso = \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                ->where('enterprise_id', $headerEmpresaId)
                ->where('is_active', true)
                ->exists();

            return $tieneAcceso ? (int) $headerEmpresaId : null;
        }

        // Opción 3: sin header, usar el primer acceso activo del usuario autenticado
        if ($user) {
            $enterpriseId = \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                ->where('is_active', true)
                ->value('enterprise_id');
            return $enterpriseId ? (int) $enterpriseId : null;
        }

        return null;
    }

    /**
     * Regla de validación `exists` acotada a la empresa del contexto.
     *
     * Un `exists:tabla,id` pelado deja que un id de OTRO tenant pase la
     * validación y que su nombre termine reflejado en la respuesta de la API
     * (fuga cross-tenant). Toda FK de una tabla multi-tenant del CRM debe
     * validarse con esta regla, no con la cadena `exists:` simple.
     */
    protected function existeEnEmpresa(string $tabla, ?int $empresaId, string $columna = 'id'): Exists
    {
        return \Illuminate\Validation\Rule::exists($tabla, $columna)
            ->where('empresa_id', $empresaId);
    }

    /**
     * Aplica el scope de empresa_id a un query builder.
     * Aborta con 403 si no se puede determinar la empresa.
     */
    protected function scopeEmpresa(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $empresaId = $this->getEmpresaId();

        if (! $empresaId) {
            abort(403, 'No se pudo determinar el contexto de empresa.');
        }

        return $query->where('empresa_id', $empresaId);
    }
}
