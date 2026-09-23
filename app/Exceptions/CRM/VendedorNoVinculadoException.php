<?php

namespace App\Exceptions\CRM;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * El usuario autenticado no tiene un CrmVendedor activo en la empresa del
 * contexto. Laravel llama render() y responde 422 con el formato de error
 * estándar del CRM (mismo shape que CrmBaseController::jsonError()).
 */
class VendedorNoVinculadoException extends RuntimeException
{
    public const MENSAJE = 'Tu usuario no está vinculado a un vendedor. Pide a tu administrador que te dé de alta.';

    public function __construct()
    {
        parent::__construct(self::MENSAJE);
    }

    public function render(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $this->getMessage()], 422);
    }
}
