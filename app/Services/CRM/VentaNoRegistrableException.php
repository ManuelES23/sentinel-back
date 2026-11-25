<?php

namespace App\Services\CRM;

use RuntimeException;

/**
 * La venta no se puede registrar por el estado actual de la cotización o de la
 * oportunidad (perdida, ya vendida, con ajustes…). El controlador la responde
 * como 422 con el mensaje; los errores de un campo usan ValidationException.
 */
class VentaNoRegistrableException extends RuntimeException {}
