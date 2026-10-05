<?php

namespace App\Http\Controllers\Api\ActivosFijos;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextoActivosController extends Controller
{
    public function permisos(Request $request): JsonResponse
    {
        abort(501);
    }

    public function catalogos(Request $request): JsonResponse
    {
        abort(501);
    }
}
