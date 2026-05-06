<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Support\SaleItemMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asigna o desasigna un cliente a una venta y aplica los efectos asociados:
 * recálculo de precios preferenciales, recálculo de totals/pendiente, limpieza
 * del `contact_phone` manual cuando aplica, auto-completado si la venta queda
 * cubierta por pagos previos, y broadcast del evento SaleUpdated.
 *
 * Las precondiciones (status !== Cancelled, autorización del usuario, etc.) se
 * validan en el controlador antes de invocar este servicio.
 */
class AssignCustomerToSale
{
    /**
     * @return array{skipped_piece_presentations: array<string>, had_payments: bool}
     */
    public function execute(Sale $sale, ?int $customerId, int $branchId): array
    {
        $hadPayments = $sale->payments()->exists();
        $skippedPiecePresentations = [];

        DB::transaction(function () use ($sale, $customerId, $branchId, &$skippedPiecePresentations) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$sale->branch_id]);

            $sale->load('items');

            if ($customerId) {
                // El precio preferencial guardado se interpreta como $/kg (o $/l)
                // del producto base. Para presentaciones con peso/volumen se
                // convierte a unit_price = $/base × content. Las piezas sin
                // equivalencia en peso/volumen se saltan con aviso al usuario.
                $customer = Customer::where('branch_id', $branchId)->findOrFail($customerId);
                $preferentialPrices = $customer->prices->keyBy('product_id');

                foreach ($sale->items as $item) {
                    $prefPrice = $preferentialPrices->get($item->product_id);
                    if (! $prefPrice) {
                        continue;
                    }

                    $pricePerBaseUnit = (float) $prefPrice->price;

                    if (! SaleItemMath::isWeightOrVolume($item)) {
                        $skippedPiecePresentations[] = $item->product_name;

                        continue;
                    }

                    $newUnitPrice = SaleItemMath::unitPriceForBasePrice($item, $pricePerBaseUnit);
                    $item->update([
                        'unit_price' => $newUnitPrice,
                        'subtotal' => round($newUnitPrice * (float) $item->quantity, 2),
                    ]);
                }
            } else {
                // Desasignar: presentaciones se restauran al precio congelado en su
                // snapshot (no al precio actual del producto base). Líneas sin
                // presentación caen al precio del catálogo.
                $productIds = $sale->items->pluck('product_id')->unique();
                $products = Product::withoutGlobalScopes()
                    ->whereIn('id', $productIds)
                    ->get(['id', 'price'])
                    ->keyBy('id');

                foreach ($sale->items as $item) {
                    $product = $products->get($item->product_id);
                    $catalogPrice = $product ? (float) $product->price : 0.0;
                    $restored = SaleItemMath::restoredUnitPrice($item, $catalogPrice);
                    $item->update([
                        'unit_price' => $restored,
                        'subtotal' => round($restored * (float) $item->quantity, 2),
                    ]);
                }
            }

            $newTotal = round((float) $sale->items()->sum('subtotal'), 2);
            $amountPaid = (float) $sale->amount_paid;
            $newPending = round(max($newTotal - $amountPaid, 0), 2);

            $updateData = [
                'customer_id' => $customerId,
                'total' => $newTotal,
                'amount_pending' => $newPending,
            ];

            // Cuando se asigna un cliente a una venta POS, el teléfono manual
            // (capturado desde el flujo "Enviar por WhatsApp") deja de ser
            // relevante: el cliente trae su propio teléfono. Las ventas origen
            // `web` conservan `contact_phone` porque ahí ese dato vino del
            // checkout y forma parte del registro del pedido.
            if ($customerId && $sale->origin !== 'web') {
                $updateData['contact_phone'] = null;
            }

            if ($newPending <= 0 && $amountPaid > 0 && $sale->status !== SaleStatus::Completed) {
                $updateData['status'] = SaleStatus::Completed;
                $updateData['completed_at'] = now();
            }

            $sale->update($updateData);
        });

        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        return [
            'skipped_piece_presentations' => $skippedPiecePresentations,
            'had_payments' => $hadPayments,
        ];
    }
}
