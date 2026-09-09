<?php

namespace App\Services;

use App\Models\ApprovalProcess;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RecipeApprovalService
{
    public function submit(Recipe $recipe): Recipe
    {
        if ($recipe->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Solo una receta en borrador puede enviarse a revisión.']]);
        }

        $recipe->update(['status' => 'pending_approval']);

        return $recipe->fresh();
    }

    public function approve(Recipe $recipe, User $approver): Recipe
    {
        $this->assertCanDecide($recipe, $approver);

        $recipe->update(['status' => 'active']);

        return $recipe->fresh();
    }

    public function reject(Recipe $recipe, User $approver, string $reason): Recipe
    {
        $this->assertCanDecide($recipe, $approver);

        $recipe->update(['status' => 'draft', 'notes' => trim(($recipe->notes ?? '')."\n[Rechazada] {$reason}")]);

        return $recipe->fresh();
    }

    private function assertCanDecide(Recipe $recipe, User $approver): void
    {
        if ($recipe->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => ['La receta no está pendiente de aprobación.']]);
        }

        $process = ApprovalProcess::findByCode('recipe_approval');
        $employee = $approver->employee;

        if (! $process || ! $employee || ! $process->canBeApprovedBy($employee, $employee->enterprise_id)) {
            throw ValidationException::withMessages(['approval' => ['No tienes permiso para aprobar esta receta.']]);
        }
    }
}
