<?php

namespace App\Services\Compras;

use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\RequisicionCampo;
use App\Models\User;
use App\Services\NotificationService;

/**
 * Notificaciones del flujo de compras (campana del sistema).
 */
class AvisosCompras
{
    public function __construct(
        private PermisosCompras $permisos,
        private AprobadorOrdenCompra $aprobador,
    ) {
    }

    public function requisicionEnviada(RequisicionCampo $req): void
    {
        $req->loadMissing(['empresa', 'almacen', 'solicitante']);
        if (! $req->empresa) {
            return;
        }

        foreach ($this->permisos->usuariosQueCotizan($req->empresa) as $user) {
            $this->enviar($user, 'Nueva requisición por cotizar',
                "{$req->numero_requisicion} de {$req->solicitante?->name} para {$req->almacen?->name}",
                $this->url($req->empresa->slug, 'operacion-agricola/agricola/requisiciones'));
        }
    }

    public function requisicionRechazada(RequisicionCampo $req): void
    {
        $req->loadMissing(['empresa', 'solicitante']);
        if ($req->solicitante) {
            $this->enviar($req->solicitante, 'Compras regresó tu requisición',
                "{$req->numero_requisicion}: {$req->notas_rechazo}",
                $this->url($req->empresa?->slug, 'operacion-agricola/agricola/requisiciones'), 'red');
        }
    }

    public function ordenPorAutorizar(PurchaseOrder $oc): void
    {
        $oc->loadMissing(['supplier', 'empresa']);
        $monto = '$' . number_format((float) $oc->total_amount, 2);
        foreach ($this->aprobador->aprobadoresDe($oc) as $user) {
            $this->enviar($user, 'Orden de compra por autorizar',
                "{$oc->order_number} · {$this->proveedor($oc)} · {$monto}",
                $this->url($oc->empresa?->slug, 'inventario/compras/ordenes-compra'), 'amber');
        }
    }

    public function ordenResuelta(PurchaseOrder $oc, bool $aprobada): void
    {
        $oc->loadMissing(['createdByUser', 'requisicion.solicitante', 'empresa']);
        $destinatarios = collect([$oc->createdByUser, $oc->requisicion?->solicitante])->filter()->unique('id');
        $titulo = $aprobada ? 'Orden de compra aprobada' : 'Orden de compra rechazada';
        $mensaje = $aprobada
            ? "{$oc->order_number} fue aprobada"
            : "{$oc->order_number}: {$oc->rejection_reason}";

        foreach ($destinatarios as $user) {
            $this->enviar($user, $titulo, $mensaje,
                $this->url($oc->empresa?->slug, 'inventario/compras/ordenes-compra'), $aprobada ? 'green' : 'red');
        }
    }

    public function recepcionPorConfirmar(PurchaseReceipt $rec): void
    {
        $rec->loadMissing(['empresa', 'almacen']);
        if (! $rec->empresa) {
            return;
        }

        foreach ($this->permisos->usuariosQueConfirman($rec->empresa) as $user) {
            $this->enviar($user, 'Entrada por confirmar',
                "{$rec->receipt_number} en {$rec->almacen?->name}",
                $this->url($rec->empresa->slug, 'inventario/compras/recepciones'), 'amber');
        }
    }

    public function recepcionRegresada(PurchaseReceipt $rec): void
    {
        $rec->loadMissing(['capturadaPor', 'empresa']);
        if ($rec->capturadaPor) {
            $this->enviar($rec->capturadaPor, 'Compras regresó una recepción',
                "{$rec->receipt_number}: {$rec->motivo_rechazo}",
                $this->url($rec->empresa?->slug, 'inventario/compras/recepciones'), 'red');
        }
    }

    private function enviar(User $user, string $titulo, string $mensaje, string $url, string $color = 'blue'): void
    {
        NotificationService::toUser($user)
            ->icon('ShoppingCart', $color)
            ->withAction($url, 'Abrir')
            ->info($titulo, $mensaje);
    }

    private function url(?string $empresaSlug, string $ruta): string
    {
        return '/' . ($empresaSlug ?: 'splendidfarms') . '/' . $ruta;
    }

    private function proveedor(PurchaseOrder $oc): string
    {
        return $oc->supplier?->trade_name ?: ($oc->supplier?->business_name ?? 'Sin proveedor');
    }
}
