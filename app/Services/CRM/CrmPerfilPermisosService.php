<?php

namespace App\Services\CRM;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Perfiles de permisos del CRM Comercial (fase 1 de la reorganización).
 *
 * Un perfil es un conjunto fijo de módulo → submódulo → permisos. Aplicarlo a
 * un usuario REEMPLAZA todos sus accesos y permisos dentro de la aplicación
 * `crm` de una empresa: lo que está en el perfil queda activo y todo lo demás
 * del CRM queda inactivo. Nada fuera del CRM de esa empresa se toca.
 *
 * El menú lateral ya muestra solo lo que el usuario tiene permitido, así que
 * el perfil define qué ve cada persona sin cambiar el sidebar.
 *
 * Nota sobre Agenda: AgendaController trata "crear" o "editar" como
 * gerencia (puede consultar la agenda de cualquier vendedor). El vendedor
 * necesita ambos para registrar y completar sus eventos, así que el perfil
 * vendedor los incluye; el formulario sigue preseleccionando su propia agenda.
 */
class CrmPerfilPermisosService
{
    private const BASE_VENDEDOR = [
        'agenda' => ['agenda' => ['ver', 'crear', 'editar']],
        'oportunidades' => ['oportunidades' => ['ver', 'crear', 'editar']],
        'clientes' => ['clientes' => ['ver', 'crear', 'editar']],
        'prospectos' => ['prospectos' => ['ver', 'crear', 'editar']],
        'cotizaciones' => ['cotizaciones' => ['ver', 'crear', 'editar']],
        'actividades' => ['actividades' => ['ver', 'crear', 'editar']],
        'empresas-externas' => ['empresas-externas' => ['ver', 'crear', 'editar', 'gestionar_contactos']],
        'dashboard' => ['dashboard' => ['ver']],
        'presupuestos' => ['presupuestos' => ['ver']],
        'integraciones' => ['outlook' => ['ver']],
    ];

    private const EXTRA_GERENCIA = [
        'agenda' => ['agenda' => ['eliminar']],
        'oportunidades' => ['oportunidades' => ['eliminar', 'cerrar']],
        'clientes' => ['clientes' => ['eliminar', 'asignar_vendedor']],
        'prospectos' => ['prospectos' => ['eliminar', 'asignar_vendedor']],
        'cotizaciones' => ['cotizaciones' => ['aprobar', 'rechazar']],
        'actividades' => ['actividades' => ['eliminar']],
        'empresas-externas' => ['empresas-externas' => ['eliminar']],
        'dashboard' => ['dashboard' => ['ejecutivo']],
        'presupuestos' => ['presupuestos' => ['crear', 'editar']],
        'integraciones' => ['dialpad' => ['ver', 'editar', 'sync']],
    ];

    private const ADMINISTRACION = [
        'catalogos' => [
            'vendedores' => ['ver', 'crear', 'editar', 'eliminar'],
            'regiones' => ['ver', 'crear', 'editar', 'eliminar'],
            'zonas' => ['ver', 'crear', 'editar', 'eliminar'],
            'bodegas' => ['ver', 'crear', 'editar', 'eliminar'],
            'productos' => ['ver', 'crear', 'editar', 'eliminar'],
            'configuracion-comercial' => ['ver', 'editar'],
        ],
        'integraciones' => [
            'dialpad' => ['ver', 'sync'],
            'outlook' => ['ver'],
        ],
    ];

    public const NOMBRES = [
        'vendedor' => 'Vendedor',
        'gerencia' => 'Gerencia comercial',
        'administracion' => 'Administración',
    ];

    public const DESCRIPCIONES = [
        'vendedor' => 'Agenda, oportunidades, clientes, prospectos, cotizaciones y actividades. Ve sus propias metas y su tablero. Sin catálogos ni aprobaciones.',
        'gerencia' => 'Todo lo del vendedor sobre cualquier vendedor, más aprobar cotizaciones, asignar vendedores, definir metas, tablero ejecutivo y clasificar llamadas.',
        'administracion' => 'Catálogos, configuración comercial e integraciones. Sin acceso a la operación de ventas.',
    ];

    /** @return array<string, array<string, array<string, string[]>>> */
    public static function definiciones(): array
    {
        return [
            'vendedor' => self::BASE_VENDEDOR,
            'gerencia' => array_merge_recursive(self::BASE_VENDEDOR, self::EXTRA_GERENCIA),
            'administracion' => self::ADMINISTRACION,
        ];
    }

    /** @return string[] */
    public static function slugs(): array
    {
        return array_keys(self::NOMBRES);
    }

    /** Lista para la interfaz de administración. */
    public function perfiles(): array
    {
        return array_map(fn (string $slug) => [
            'slug' => $slug,
            'nombre' => self::NOMBRES[$slug],
            'descripcion' => self::DESCRIPCIONES[$slug],
        ], self::slugs());
    }

    /**
     * Reemplaza los accesos del usuario en el CRM de la empresa por el perfil.
     *
     * @return array{perfil: string, modulos_activos: int, permisos_otorgados: int}
     *
     * @throws InvalidArgumentException si el perfil no existe o la empresa no tiene CRM.
     */
    public function aplicar(User $usuario, Enterprise $empresa, string $perfil): array
    {
        $definiciones = self::definiciones();
        if (! array_key_exists($perfil, $definiciones)) {
            throw new InvalidArgumentException("El perfil \"{$perfil}\" no existe.");
        }
        $definicion = $definiciones[$perfil];

        $app = Application::where('enterprise_id', $empresa->id)
            ->where('slug', 'crm')
            ->with('modules.submodules.permissionTypes')
            ->first();

        if (! $app) {
            throw new InvalidArgumentException('La empresa no tiene el CRM Comercial configurado.');
        }

        return DB::transaction(function () use ($usuario, $app, $definicion, $perfil) {
            $ahora = now();
            $modulosActivos = 0;
            $permisosOtorgados = 0;

            UserApplicationAccess::updateOrCreate(
                ['user_id' => $usuario->id, 'application_id' => $app->id],
                ['is_active' => true, 'granted_at' => $ahora],
            );

            foreach ($app->modules as $modulo) {
                $submodulosDelPerfil = $definicion[$modulo->slug] ?? [];
                $moduloActivo = $submodulosDelPerfil !== [];

                UserModuleAccess::updateOrCreate(
                    ['user_id' => $usuario->id, 'module_id' => $modulo->id],
                    ['is_active' => $moduloActivo] + ($moduloActivo ? ['granted_at' => $ahora] : []),
                );
                $modulosActivos += $moduloActivo ? 1 : 0;

                foreach ($modulo->submodules as $submodulo) {
                    $permisosDelPerfil = array_unique($submodulosDelPerfil[$submodulo->slug] ?? []);
                    $submoduloActivo = $permisosDelPerfil !== [];

                    UserSubmoduleAccess::updateOrCreate(
                        ['user_id' => $usuario->id, 'submodule_id' => $submodulo->id],
                        ['is_active' => $submoduloActivo] + ($submoduloActivo ? ['granted_at' => $ahora] : []),
                    );

                    foreach ($submodulo->permissionTypes as $tipo) {
                        $otorgado = in_array($tipo->slug, $permisosDelPerfil, true);

                        UserSubmodulePermission::updateOrCreate(
                            [
                                'user_id' => $usuario->id,
                                'submodule_id' => $submodulo->id,
                                'permission_type_id' => $tipo->id,
                            ],
                            ['is_granted' => $otorgado],
                        );
                        $permisosOtorgados += $otorgado ? 1 : 0;
                    }
                }
            }

            return [
                'perfil' => $perfil,
                'modulos_activos' => $modulosActivos,
                'permisos_otorgados' => $permisosOtorgados,
            ];
        });
    }
}
