<?php

namespace App\Broadcasting;

use App\Models\User;
use App\Models\UserEnterpriseAccess;

/**
 * Autorización del canal privado `crm.{empresaId}` (eventos transversales del
 * CRM, como la reasignación de vendedor).
 *
 * Usa `user_enterprise_access`, que es la tabla que el sistema mantiene de
 * verdad; la relación `activeEnterprises()` apunta a la tabla antigua
 * `user_enterprises`, que quedó prácticamente vacía.
 */
class CrmEmpresaChannel
{
    public function join(User $user, int|string $empresaId): bool
    {
        return UserEnterpriseAccess::where('user_id', $user->id)
            ->where('enterprise_id', (int) $empresaId)
            ->where('is_active', true)
            ->exists();
    }
}
