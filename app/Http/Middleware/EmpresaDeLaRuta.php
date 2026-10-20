<?php

namespace App\Http\Middleware;

use App\Models\Enterprise;
use App\Services\ActivosFijos\AlcanceActivos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grupo genérico /api/{empresa}/administration (Activos Fijos y Organización):
 * resuelve la empresa por el slug de la URL, sin lista de slugs fijos.
 *
 * 1. La empresa debe existir (404 «Empresa no encontrada»).
 * 2. El usuario debe ser admin o tener acceso activo a ella (403, mismo
 *    criterio y mensaje que AlcanceActivos::empresaActual).
 * 3. Deja la empresa como la actual de AlcanceActivos (no se repite la consulta).
 * 4. Fija el header X-Enterprise-Slug al slug de la empresa: los controladores
 *    de Organización (y la bitácora/eventos) toman la empresa de ese header, así
 *    que siempre operan sobre la empresa de la URL y no sobre una cabecera
 *    arbitraria del cliente.
 * 5. Olvida el parámetro {empresa}: si no, Laravel lo pasaría como primer
 *    argumento posicional a las acciones del controlador (show($id),
 *    show(Branch $branch)…) y desplazaría los demás.
 */
class EmpresaDeLaRuta
{
    public const PARAMETRO = 'empresa';

    public function __construct(private readonly AlcanceActivos $alcance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = (string) ($route?->parameter(self::PARAMETRO) ?? $request->segment(2));

        $empresa = Enterprise::where('slug', $slug)->first();
        if (! $empresa) {
            return response()->json(['status' => 'error', 'message' => 'Empresa no encontrada'], 404);
        }

        if (! AlcanceActivos::tieneAcceso($request->user(), $empresa)) {
            return response()->json(['status' => 'error', 'message' => AlcanceActivos::MENSAJE_SIN_ACCESO], 403);
        }

        $this->alcance->recordarEmpresa($request, $empresa);
        $request->headers->set('X-Enterprise-Slug', $empresa->slug);
        $route?->forgetParameter(self::PARAMETRO);

        return $next($request);
    }
}
