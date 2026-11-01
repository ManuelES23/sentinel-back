<?php

namespace App\Exceptions\CRM;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Un cambio de roles del Equipo comercial no se puede aplicar: 403 si quien
 * pide no tiene permiso, 409 si los roles ya cambiaron desde que la pantalla
 * los mostró, 422 si el dato no es válido. El mensaje va en español y nombra
 * a la persona. Laravel llama render() y responde con el formato de error
 * estándar del CRM (mismo shape que CrmBaseController::jsonError()).
 */
class RolesEquipoException extends RuntimeException
{
    public function __construct(string $mensaje, private readonly int $status)
    {
        parent::__construct($mensaje);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $this->getMessage()], $this->status);
    }
}
