<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Controller de Proveedores
 * Ubicación: administration/organizacion/proveedores
 */
class SupplierController extends Controller
{
    /**
     * Listar proveedores
     */
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::with(['contacts', 'primaryContact']);

        // Filtros
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                  ->orWhere('trade_name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('tax_id', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('supplier_type')) {
            $query->where('supplier_type', $request->supplier_type);
        }

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Ordenamiento
        $sortBy = $request->get('sort_by', 'business_name');
        $sortDir = $request->get('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        // Paginación o todos
        if ($request->has('per_page')) {
            $suppliers = $query->paginate($request->per_page);
        } else {
            $suppliers = $query->get();
        }

        return response()->json([
            'success' => true,
            'data' => $suppliers
        ]);
    }

    /**
     * Crear proveedor
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:suppliers,code',
            'business_name' => 'required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:50|unique:suppliers,tax_id',
            'supplier_type' => 'required|in:national,international',
            'category' => 'nullable|string|max:100',
            'has_credit' => 'boolean',
            'payment_terms' => 'nullable|integer|min:0',
            'credit_limit' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            'bank_clabe' => 'nullable|string|max:50',
            'bank_swift' => 'nullable|string|max:20',
            'legal_representative' => 'nullable|string|max:255',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date|after_or_equal:contract_start_date',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            // Contactos iniciales
            'contacts' => 'nullable|array',
            'contacts.*.name' => 'required_with:contacts|string|max:255',
            'contacts.*.position' => 'nullable|string|max:100',
            'contacts.*.department' => 'nullable|string|max:100',
            'contacts.*.phone' => 'nullable|string|max:50',
            'contacts.*.mobile' => 'nullable|string|max:50',
            'contacts.*.email' => 'nullable|email|max:255',
            'contacts.*.is_primary' => 'boolean',
        ]);

        // Generar código si no se proporcionó
        if (empty($validated['code'])) {
            $validated['code'] = Supplier::generateCode();
        }

        // Asignar valores por defecto para campos numéricos
        $validated['discount_percent'] = $validated['discount_percent'] ?? 0;
        $validated['credit_limit'] = $validated['credit_limit'] ?? 0;
        $validated['payment_terms'] = $validated['payment_terms'] ?? 30;

        // Crear proveedor
        $supplier = Supplier::create($validated);

        // Crear contactos si se proporcionaron
        if (!empty($validated['contacts'])) {
            foreach ($validated['contacts'] as $contactData) {
                $supplier->contacts()->create($contactData);
            }
            $supplier->load('contacts');
        }

        return response()->json([
            'success' => true,
            'message' => 'Proveedor creado exitosamente',
            'data' => $supplier
        ], 201);
    }

    /**
     * Mostrar proveedor
     */
    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->load(['contacts', 'primaryContact']);
        
        // Agregar métricas
        $supplier->current_balance = $supplier->getCurrentBalance();
        $supplier->available_credit = $supplier->getAvailableCredit();
        $supplier->purchase_orders_count = $supplier->purchaseOrders()->count();
        $supplier->pending_orders_count = $supplier->purchaseOrders()->active()->count();

        return response()->json([
            'success' => true,
            'data' => $supplier
        ]);
    }

    /**
     * Actualizar proveedor
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:50', Rule::unique('suppliers', 'code')->ignore($supplier->id)],
            'business_name' => 'sometimes|required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'tax_id' => ['nullable', 'string', 'max:50', Rule::unique('suppliers', 'tax_id')->ignore($supplier->id)],
            'supplier_type' => 'sometimes|required|in:national,international',
            'category' => 'nullable|string|max:100',
            'has_credit' => 'boolean',
            'payment_terms' => 'nullable|integer|min:0',
            'credit_limit' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:50',
            'bank_clabe' => 'nullable|string|max:50',
            'bank_swift' => 'nullable|string|max:20',
            'legal_representative' => 'nullable|string|max:255',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date|after_or_equal:contract_start_date',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Filtrar valores null para campos numéricos que no deben ser null
        $numericFields = ['discount_percent', 'credit_limit', 'payment_terms'];
        foreach ($numericFields as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] === null) {
                unset($validated[$field]);
            }
        }

        $supplier->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Proveedor actualizado exitosamente',
            'data' => $supplier->fresh(['contacts', 'primaryContact'])
        ]);
    }

    /**
     * Eliminar proveedor
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        // Verificar que no tenga órdenes de compra activas
        if ($supplier->purchaseOrders()->active()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el proveedor porque tiene órdenes de compra activas'
            ], 422);
        }

        // Verificar que no tenga cuentas por pagar pendientes
        if ($supplier->accountsPayable()->pending()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el proveedor porque tiene cuentas por pagar pendientes'
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Proveedor eliminado exitosamente'
        ]);
    }

    // ==================== MÉTODOS DE CONTACTOS ====================

    /**
     * Listar contactos de un proveedor
     */
    public function contacts(Supplier $supplier): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $supplier->contacts
        ]);
    }

    /**
     * Agregar contacto a un proveedor
     */
    public function addContact(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $contact = $supplier->contacts()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Contacto agregado exitosamente',
            'data' => $contact
        ], 201);
    }

    /**
     * Actualizar contacto
     */
    public function updateContact(Request $request, Supplier $supplier, SupplierContact $contact): JsonResponse
    {
        if ($contact->supplier_id !== $supplier->id) {
            return response()->json([
                'success' => false,
                'message' => 'El contacto no pertenece a este proveedor'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $contact->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Contacto actualizado exitosamente',
            'data' => $contact
        ]);
    }

    /**
     * Eliminar contacto
     */
    public function deleteContact(Supplier $supplier, SupplierContact $contact): JsonResponse
    {
        if ($contact->supplier_id !== $supplier->id) {
            return response()->json([
                'success' => false,
                'message' => 'El contacto no pertenece a este proveedor'
            ], 404);
        }

        $contact->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contacto eliminado exitosamente'
        ]);
    }

    // ==================== MÉTODOS ADICIONALES ====================

    /**
     * Obtener lista simplificada para selects
     */
    public function list(Request $request): JsonResponse
    {
        $suppliers = Supplier::active()
            ->select('id', 'code', 'business_name', 'trade_name', 'has_credit', 'payment_terms')
            ->orderBy('business_name')
            ->get()
            ->map(function ($supplier) {
                return [
                    'id' => $supplier->id,
                    'code' => $supplier->code,
                    'business_name' => $supplier->business_name,
                    'trade_name' => $supplier->trade_name,
                    'name' => $supplier->full_name,
                    'has_credit' => $supplier->has_credit,
                    'payment_terms' => $supplier->payment_terms,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $suppliers
        ]);
    }

    /**
     * Obtener balance de un proveedor
     */
    public function balance(Supplier $supplier): JsonResponse
    {
        $pending = $supplier->accountsPayable()
            ->pending()
            ->selectRaw('SUM(balance) as total, COUNT(*) as count')
            ->first();

        $overdue = $supplier->accountsPayable()
            ->overdue()
            ->selectRaw('SUM(balance) as total, COUNT(*) as count')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'current_balance' => $supplier->getCurrentBalance(),
                'credit_limit' => $supplier->credit_limit,
                'available_credit' => $supplier->getAvailableCredit(),
                'pending_documents' => $pending->count ?? 0,
                'pending_amount' => $pending->total ?? 0,
                'overdue_documents' => $overdue->count ?? 0,
                'overdue_amount' => $overdue->total ?? 0,
            ]
        ]);
    }
}
