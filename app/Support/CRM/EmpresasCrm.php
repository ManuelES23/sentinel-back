<?php

namespace App\Support\CRM;

use App\Models\Enterprise;

/**
 * Empresas del CRM con reglas propias. Se localizan siempre por slug,
 * nunca por id (los ids cambian entre local y producción).
 */
class EmpresasCrm
{
    public const SLUG_SBP = 'splendidbyporvenir';

    public static function idPorSlug(string $slug): ?int
    {
        $id = Enterprise::where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }
}
