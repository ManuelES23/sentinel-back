<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;

/**
 * Requisitos de contraseña vigentes, para mostrarlos en los formularios.
 */
class PasswordPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => PasswordPolicy::toArray()]);
    }
}
