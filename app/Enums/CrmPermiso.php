<?php

namespace App\Enums;

/**
 * Slugs de submodulos del CRM que se usan como constantes en controladores,
 * policies y seeders. El valor del enum es el slug que se persiste en la
 * tabla submodule_permission_types.
 */
enum CrmPermiso: string
{
    // --- Acceso general ---
    case ACCEDER = 'crm.acceder';

    // --- Prospectos ---
    case PROSPECTOS_VER     = 'crm.prospectos.ver';
    case PROSPECTOS_CREAR   = 'crm.prospectos.crear';
    case PROSPECTOS_EDITAR  = 'crm.prospectos.editar';
    case PROSPECTOS_ELIMINAR = 'crm.prospectos.eliminar';
    case PROSPECTOS_ASIGNAR_VENDEDOR = 'crm.prospectos.asignar_vendedor';

    // --- Clientes ---
    case CLIENTES_VER     = 'crm.clientes.ver';
    case CLIENTES_CREAR   = 'crm.clientes.crear';
    case CLIENTES_EDITAR  = 'crm.clientes.editar';
    case CLIENTES_ELIMINAR = 'crm.clientes.eliminar';
    case CLIENTES_ASIGNAR_VENDEDOR = 'crm.clientes.asignar_vendedor';

    // --- Oportunidades ---
    case OPORTUNIDADES_VER      = 'crm.oportunidades.ver';
    case OPORTUNIDADES_CREAR    = 'crm.oportunidades.crear';
    case OPORTUNIDADES_EDITAR   = 'crm.oportunidades.editar';
    case OPORTUNIDADES_ELIMINAR = 'crm.oportunidades.eliminar';
    case OPORTUNIDADES_CERRAR   = 'crm.oportunidades.cerrar';

    // --- Cotizaciones ---
    case COTIZACIONES_VER      = 'crm.cotizaciones.ver';
    case COTIZACIONES_CREAR    = 'crm.cotizaciones.crear';
    case COTIZACIONES_EDITAR   = 'crm.cotizaciones.editar';
    case COTIZACIONES_APROBAR  = 'crm.cotizaciones.aprobar';
    case COTIZACIONES_RECHAZAR = 'crm.cotizaciones.rechazar';

    // --- Actividades ---
    case ACTIVIDADES_VER    = 'crm.actividades.ver';
    case ACTIVIDADES_CREAR  = 'crm.actividades.crear';
    case ACTIVIDADES_EDITAR = 'crm.actividades.editar';
    case ACTIVIDADES_ELIMINAR = 'crm.actividades.eliminar';

    // --- Presupuestos ---
    case PRESUPUESTOS_VER    = 'crm.presupuestos.ver';
    case PRESUPUESTOS_CREAR  = 'crm.presupuestos.crear';
    case PRESUPUESTOS_EDITAR = 'crm.presupuestos.editar';

    // --- Agenda ---
    case AGENDA_VER     = 'crm.agenda.ver';
    case AGENDA_CREAR   = 'crm.agenda.crear';
    case AGENDA_EDITAR  = 'crm.agenda.editar';
    case AGENDA_ELIMINAR = 'crm.agenda.eliminar';

    // --- Dashboard ---
    case DASHBOARD_VER            = 'crm.dashboard.ver';
    case DASHBOARD_EJECUTIVO      = 'crm.dashboard.ejecutivo';

    // --- Catálogos ---
    case CATALOGOS_VER    = 'crm.catalogos.ver';
    case CATALOGOS_EDITAR = 'crm.catalogos.editar';
    case CATALOGOS_CONFIGURACION_COMERCIAL_VER    = 'crm.catalogos.configuracion_comercial.ver';
    case CATALOGOS_CONFIGURACION_COMERCIAL_EDITAR = 'crm.catalogos.configuracion_comercial.editar';

    // --- Integraciones ---
    case INTEGRACIONES_DIALPAD = 'crm.integraciones.dialpad';
}
