<?php
// sentinel-back/app/Services/ActivosFijos/ResponsablesActivo.php

namespace App\Services\ActivosFijos;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\SfEmployee;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Personas a las que se puede asignar un activo de una empresa: empleados activos
 * (employees y sf_employees) y usuarios con acceso vigente a la empresa. La búsqueda
 * alimenta el selector del front; resolver() es la única fuente de los datos que se
 * copian a la asignación (nunca se confía en lo que mande el cliente).
 */
class ResponsablesActivo
{
    public const LIMITE = 20;

    /** @return Collection<int, array{tipo: string, id: int, nombre: string, puesto: ?string, departamento: ?string}> */
    public function buscar(Enterprise $empresa, string $texto): Collection
    {
        $texto = trim($texto);

        $empleados = $this->filtrar($this->empleadosActivos($empresa), $texto, [
            'employees.first_name', 'employees.last_name', 'employees.second_last_name', 'employees.employee_number',
        ])->orderBy('employees.first_name')->orderBy('employees.last_name')->limit(self::LIMITE)->get()
            ->map(fn (Employee $e) => $this->deEmpleado($e));

        $empleadosSf = $this->filtrar($this->empleadosSfActivos($empresa), $texto, [
            'sf_employees.first_name', 'sf_employees.last_name', 'sf_employees.second_last_name', 'sf_employees.code',
        ])->orderBy('sf_employees.first_name')->orderBy('sf_employees.last_name')->limit(self::LIMITE)->get()
            ->map(fn (SfEmployee $e) => $this->deEmpleadoSf($e));

        // Un usuario ligado a un empleado de la misma empresa se ofrece como empleado, no como usuario.
        $usuarios = $this->filtrar($this->usuariosConAcceso($empresa), $texto, ['users.name', 'users.email'])
            ->whereNotIn('users.id', Employee::query()
                ->where('enterprise_id', $empresa->id)
                ->whereNotNull('user_id')
                ->select('user_id'))
            ->orderBy('users.name')->limit(self::LIMITE)->get()
            ->map(fn (User $u) => $this->deUsuario($u));

        return $empleados->concat($empleadosSf)->concat($usuarios)
            ->sortBy(fn (array $p) => mb_strtolower($p['nombre']))
            ->take(self::LIMITE)
            ->values();
    }

    /**
     * @return array{tipo: string, id: int, nombre: string, puesto: ?string, departamento: ?string}
     *
     * @throws ValidationException
     */
    public function resolver(string $tipo, int $id, Enterprise $empresa): array
    {
        $persona = match ($tipo) {
            'employee' => ($e = $this->empleadosActivos($empresa)->whereKey($id)->first()) ? $this->deEmpleado($e) : null,
            'sf_employee' => ($e = $this->empleadosSfActivos($empresa)->whereKey($id)->first()) ? $this->deEmpleadoSf($e) : null,
            'user' => ($u = $this->usuariosConAcceso($empresa)->whereKey($id)->first()) ? $this->deUsuario($u) : null,
            default => null,
        };

        if (! $persona) {
            throw ValidationException::withMessages([
                'assignee_id' => 'La persona seleccionada no es válida: debe estar activa y pertenecer a la empresa del activo.',
            ]);
        }

        return $persona;
    }

    private function empleadosActivos(Enterprise $empresa): Builder
    {
        return Employee::query()
            ->with(['position:id,name', 'department:id,name'])
            ->where('employees.enterprise_id', $empresa->id)
            ->where('employees.status', 'active');
    }

    private function empleadosSfActivos(Enterprise $empresa): Builder
    {
        return SfEmployee::query()
            ->where('sf_employees.enterprise_id', $empresa->id)
            ->where('sf_employees.status', 'active');
    }

    /** Acceso a la empresa vigente: activo y sin vencer. */
    private function usuariosConAcceso(Enterprise $empresa): Builder
    {
        return User::query()->whereIn('users.id', UserEnterpriseAccess::query()
            ->where('enterprise_id', $empresa->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->select('user_id'));
    }

    /**
     * Cada palabra del texto debe aparecer en alguna de las columnas. '!' como carácter
     * de escape: funciona igual en MySQL y SQLite.
     */
    private function filtrar(Builder $query, string $texto, array $columnas): Builder
    {
        foreach (preg_split('/\s+/', $texto, -1, PREG_SPLIT_NO_EMPTY) as $palabra) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $palabra).'%';
            $query->where(function ($grupo) use ($columnas, $like) {
                foreach ($columnas as $columna) {
                    $grupo->orWhereRaw("{$columna} LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        return $query;
    }

    private function deEmpleado(Employee $e): array
    {
        return [
            'tipo' => 'employee',
            'id' => $e->id,
            'nombre' => $e->full_name,
            'puesto' => $e->position?->name,
            'departamento' => $e->department?->name,
        ];
    }

    private function deEmpleadoSf(SfEmployee $e): array
    {
        return [
            'tipo' => 'sf_employee',
            'id' => $e->id,
            'nombre' => $e->full_name,
            'puesto' => $e->position,
            'departamento' => $e->department,
        ];
    }

    private function deUsuario(User $u): array
    {
        return ['tipo' => 'user', 'id' => $u->id, 'nombre' => $u->name, 'puesto' => null, 'departamento' => null];
    }
}
