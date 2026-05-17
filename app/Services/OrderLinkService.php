<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Exceptions\OrderLink\CrossBranchLinkException;
use App\Exceptions\OrderLink\IneligibleScaleSaleException;
use App\Exceptions\OrderLink\IneligibleWebOrderException;
use App\Exceptions\OrderLink\LockedScaleSaleException;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Empareja una venta real de báscula con un pedido web pendiente.
 *
 * La venta de báscula es la que se cobra; el pedido web queda como referencia
 * histórica con status Fulfilled. El emparejamiento copia el customer/contact
 * cuando faltan en la venta real y sobrescribe los campos de delivery (el
 * pedido web es la fuente de verdad para domicilio). El delivery_fee se suma
 * al total de la venta de báscula.
 *
 * Diseño: docs/superpowers/specs/2026-05-16-emparejar-pedido-venta-design.md
 */
class OrderLinkService
{
    public function link(Sale $scaleSale, Sale $webOrder): void
    {
        $this->assertSameTenantAndBranch($scaleSale, $webOrder);
        $this->assertScaleSaleEligible($scaleSale);
        $this->assertWebOrderEligible($webOrder);

        DB::transaction(function () use ($scaleSale, $webOrder): void {
            $scaleSale->linked_order_id = $webOrder->id;

            $scaleSale->customer_id ??= $webOrder->customer_id;
            $scaleSale->contact_name ??= $webOrder->contact_name;
            $scaleSale->contact_phone ??= $webOrder->contact_phone;

            $scaleSale->delivery_type = $webOrder->delivery_type;
            $scaleSale->delivery_address = $webOrder->delivery_address;
            $scaleSale->delivery_lat = $webOrder->delivery_lat;
            $scaleSale->delivery_lng = $webOrder->delivery_lng;
            $scaleSale->delivery_distance_km = $webOrder->delivery_distance_km;
            $scaleSale->delivery_fee = $webOrder->delivery_fee;

            $itemsSubtotal = (float) $scaleSale->items()->sum('subtotal');
            $deliveryFee = (float) ($webOrder->delivery_fee ?? 0);
            $newTotal = round($itemsSubtotal + $deliveryFee, 2);

            $scaleSale->total = $newTotal;
            $scaleSale->amount_pending = round($newTotal - (float) $scaleSale->amount_paid, 2);
            $scaleSale->save();

            $webOrder->status = SaleStatus::Fulfilled;
            $webOrder->save();

            SaleUpdated::dispatch($scaleSale->fresh());
            SaleUpdated::dispatch($webOrder->fresh());
        });
    }

    public function unlink(Sale $scaleSale): void
    {
        if ($scaleSale->linked_order_id === null) {
            throw new IneligibleScaleSaleException('La venta no está vinculada a ningún pedido.');
        }

        $this->assertScaleSaleStillEditable($scaleSale);

        DB::transaction(function () use ($scaleSale): void {
            $webOrder = $scaleSale->linkedOrder;

            $scaleSale->linked_order_id = null;
            $scaleSale->delivery_type = null;
            $scaleSale->delivery_address = null;
            $scaleSale->delivery_lat = null;
            $scaleSale->delivery_lng = null;
            $scaleSale->delivery_distance_km = null;
            $scaleSale->delivery_fee = null;

            $itemsSubtotal = (float) $scaleSale->items()->sum('subtotal');
            $scaleSale->total = round($itemsSubtotal, 2);
            $scaleSale->amount_pending = round($itemsSubtotal - (float) $scaleSale->amount_paid, 2);
            $scaleSale->save();

            if ($webOrder) {
                $webOrder->status = SaleStatus::Pending;
                $webOrder->save();

                SaleUpdated::dispatch($webOrder->fresh());
            }

            SaleUpdated::dispatch($scaleSale->fresh());
        });
    }

    private function assertSameTenantAndBranch(Sale $a, Sale $b): void
    {
        if ($a->tenant_id !== $b->tenant_id || $a->branch_id !== $b->branch_id) {
            throw new CrossBranchLinkException('La venta y el pedido deben pertenecer a la misma sucursal.');
        }
    }

    private function assertScaleSaleEligible(Sale $sale): void
    {
        if ($sale->origin === 'web') {
            throw new IneligibleScaleSaleException('No se puede vincular un pedido web como venta destino.');
        }

        if ($sale->status !== SaleStatus::Active) {
            throw new IneligibleScaleSaleException('Solo se pueden vincular ventas activas.');
        }

        if ($sale->linked_order_id !== null) {
            throw new IneligibleScaleSaleException('La venta ya está vinculada a otro pedido.');
        }
    }

    private function assertWebOrderEligible(Sale $order): void
    {
        if ($order->origin !== 'web') {
            throw new IneligibleWebOrderException('El registro a vincular no es un pedido web.');
        }

        if ($order->status !== SaleStatus::Pending) {
            throw new IneligibleWebOrderException('Solo se pueden vincular pedidos web pendientes.');
        }
    }

    private function assertScaleSaleStillEditable(Sale $sale): void
    {
        if ($sale->status !== SaleStatus::Active) {
            throw new LockedScaleSaleException('Solo se puede desvincular una venta activa.');
        }

        if ($sale->payments()->exists()) {
            throw new LockedScaleSaleException('No se puede desvincular: la venta ya tiene pagos registrados.');
        }
    }
}
