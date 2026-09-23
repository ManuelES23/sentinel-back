<?php

namespace App\Services\Compras;

/**
 * Dónde vive el módulo de Compras en cada empresa. Splendid Farms lo tiene en
 * Administración; el resto sigue en Inventario. Es la única fuente de verdad:
 * la consultan tanto los permisos como los enlaces de las notificaciones.
 */
class RutasCompras
{
    /** empresa => [aplicación, módulo] */
    private const MAPA = [
        'splendidfarms' => ['administration', 'compras'],
        '*' => ['inventario', 'compras'],
    ];

    /** @return array{0:string,1:string,2:string} [aplicación, módulo, submódulo] */
    public function ruta(?string $empresaSlug, string $submodulo): array
    {
        [$app, $modulo] = self::MAPA[$empresaSlug] ?? self::MAPA['*'];

        return [$app, $modulo, $submodulo];
    }

    public function url(?string $empresaSlug, string $submodulo): string
    {
        $empresa = $empresaSlug ?: 'splendidfarms';
        [$app, $modulo, $sub] = $this->ruta($empresa, $submodulo);

        return "/{$empresa}/{$app}/{$modulo}/{$sub}";
    }
}
