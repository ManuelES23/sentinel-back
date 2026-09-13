<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ContactoUpdated;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmProspecto;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ContactoController
 * Contactos polimórficos que pueden apuntar a: prospecto, cliente o empresa_externa.
 *
 * Payload standard:
 *  - entidad_tipo: 'prospecto' | 'cliente' | 'empresa_externa' (alias corto)
 *  - entidad_id:   ID del recurso relacionado
 * Internamente se traduce a entidad_type (nombre completo del modelo).
 */
class ContactoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    /**
     * Alias corto → clase del modelo padre.
     */
    protected const TIPOS = [
        'prospecto'       => CrmProspecto::class,
        'cliente'         => CrmCliente::class,
        'empresa_externa' => CrmEmpresaExterna::class,
    ];

    /**
     * Permiso de submódulo que exige escribir contactos según la entidad
     * padre: gestionar los contactos de un prospecto/cliente es editarlo;
     * los de una empresa externa tienen su permiso propio.
     */
    protected const PERMISO_POR_TIPO = [
        CrmProspecto::class      => ['prospectos', 'prospectos', 'editar'],
        CrmCliente::class        => ['clientes', 'clientes', 'editar'],
        CrmEmpresaExterna::class => ['empresas-externas', 'empresas-externas', 'gestionar_contactos'],
    ];

    /**
     * GET /crm/contactos?entidad_tipo=&entidad_id=
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $request->validate([
            'entidad_tipo' => ['nullable', Rule::in(array_keys(self::TIPOS))],
            'entidad_id'   => 'nullable|integer',
        ]);

        $query = CrmContacto::query()
            ->where('empresa_id', $empresaId)
            ->when($request->entidad_tipo, function ($q, $tipo) {
                $q->where('entidad_type', self::TIPOS[$tipo]);
            })
            ->when($request->entidad_id, fn ($q, $id) => $q->where('entidad_id', $id))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nombre', 'like', "%{$search}%")
                        ->orWhere('cargo', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('telefono', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('es_principal')
            ->orderBy('nombre');

        $perPage = (int) $request->query('per_page', 50);
        $paginated = $query->paginate($perPage);

        // Añadir alias corto en cada registro para consumo del frontend.
        $items = collect($paginated->items())->map(function ($c) {
            $c->entidad_tipo = array_search($c->entidad_type, self::TIPOS, true) ?: null;
            return $c;
        });

        return response()->json([
            'success' => true,
            'message' => 'Operación exitosa',
            'data'    => $items,
            'meta'    => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * GET /crm/contactos/{contacto}
     */
    public function show(Request $request, CrmContacto $contacto): JsonResponse
    {
        $this->verificarEmpresa($request, $contacto);
        $contacto->entidad_tipo = array_search($contacto->entidad_type, self::TIPOS, true) ?: null;

        return $this->jsonSuccess($contacto);
    }

    /**
     * POST /crm/contactos
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $validated = $request->validate([
            'entidad_tipo' => ['required', Rule::in(array_keys(self::TIPOS))],
            'entidad_id'   => 'required|integer',
            'nombre'       => 'required|string|max:255',
            'cargo'        => 'nullable|string|max:255',
            'email'        => 'nullable|email|max:255',
            'telefono'     => 'nullable|string|max:50',
            'es_principal' => 'nullable|boolean',
        ]);

        $modelClass = self::TIPOS[$validated['entidad_tipo']];
        $this->exigirPermisoContactos($modelClass);

        $entidad = $modelClass::where('empresa_id', $empresaId)->find($validated['entidad_id']);
        if (!$entidad) {
            return $this->jsonError('La entidad relacionada no existe o no pertenece a la empresa.', 404);
        }

        $contacto = DB::transaction(function () use ($validated, $modelClass, $empresaId) {
            // Si se marca como principal, desmarcar los demás de la misma entidad.
            if (!empty($validated['es_principal'])) {
                CrmContacto::where('empresa_id', $empresaId)
                    ->where('entidad_type', $modelClass)
                    ->where('entidad_id', $validated['entidad_id'])
                    ->update(['es_principal' => false]);
            }

            return CrmContacto::create([
                'empresa_id'   => $empresaId,
                'entidad_type' => $modelClass,
                'entidad_id'   => $validated['entidad_id'],
                'nombre'       => $validated['nombre'],
                'cargo'        => $validated['cargo'] ?? null,
                'email'        => $validated['email'] ?? null,
                'telefono'     => $validated['telefono'] ?? null,
                'es_principal' => $validated['es_principal'] ?? false,
            ]);
        });

        $contacto->entidad_tipo = $validated['entidad_tipo'];

        broadcast(new ContactoUpdated('created', $contacto->toArray()));

        return $this->jsonSuccess($contacto, 'Contacto creado correctamente', 201);
    }

    /**
     * PATCH /crm/contactos/{contacto}
     */
    public function update(Request $request, CrmContacto $contacto): JsonResponse
    {
        $this->verificarEmpresa($request, $contacto);
        $this->exigirPermisoContactos($contacto->entidad_type);

        $validated = $request->validate([
            'nombre'       => 'sometimes|required|string|max:255',
            'cargo'        => 'sometimes|nullable|string|max:255',
            'email'        => 'sometimes|nullable|email|max:255',
            'telefono'     => 'sometimes|nullable|string|max:50',
            'es_principal' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($validated, $contacto) {
            if (array_key_exists('es_principal', $validated) && $validated['es_principal']) {
                CrmContacto::where('empresa_id', $contacto->empresa_id)
                    ->where('entidad_type', $contacto->entidad_type)
                    ->where('entidad_id', $contacto->entidad_id)
                    ->where('id', '!=', $contacto->id)
                    ->update(['es_principal' => false]);
            }

            $contacto->update($validated);
        });

        $contacto->refresh();
        $contacto->entidad_tipo = array_search($contacto->entidad_type, self::TIPOS, true) ?: null;

        broadcast(new ContactoUpdated('updated', $contacto->toArray()));

        return $this->jsonSuccess($contacto, 'Contacto actualizado correctamente');
    }

    /**
     * DELETE /crm/contactos/{contacto}
     */
    public function destroy(Request $request, CrmContacto $contacto): JsonResponse
    {
        $this->verificarEmpresa($request, $contacto);
        $this->exigirPermisoContactos($contacto->entidad_type);

        $data = $contacto->toArray();
        $contacto->delete();

        broadcast(new ContactoUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Contacto eliminado correctamente');
    }

    private function exigirPermisoContactos(string $entidadType): void
    {
        abort_unless(isset(self::PERMISO_POR_TIPO[$entidadType]), 403, 'No tienes permiso para gestionar estos contactos.');
        [$modulo, $submodulo, $permiso] = self::PERMISO_POR_TIPO[$entidadType];

        $this->exigirPermisoSubmodulo($this->getEmpresaId(), $modulo, $submodulo, $permiso, 'No tienes permiso para gestionar estos contactos.');
    }

    /**
     * Aborta con 404 si el contacto no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmContacto $contacto): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $contacto->empresa_id !== (int) $empresaId) {
            abort(404, 'Contacto no encontrado');
        }
    }
}
