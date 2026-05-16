<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Exceptions\SaleItemEditNoOp;
use App\Exceptions\SaleItemEditNotAllowed;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemChange;
use App\Models\User;
use App\Support\SaleItemSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Mutaciones de items de una venta desde Mesa de Trabajo. Transaccional:
 * cada operación captura snapshot before/after, recalcula sale.total, delega
 * a SalePaymentService::recalculate() para que ajuste amount_paid/pending/
 * status con la misma lógica que el flujo de pagos y deja un registro
 * append-only en sale_item_changes.
 *
 * No dispara broadcasts; el caller (controller) decide cuándo emitir
 * SaleUpdated tras la transacción.
 */
class SaleItemEditor
{
    public function __construct(protected SalePaymentService $payments) {}

    /**
     * @param  array{product_id: int, presentation_id?: ?int, quantity: float|int, unit_price: float|int, notes?: ?string}  $data
     */
    public function add(Sale $sale, array $data, ?string $reason, User $user): SaleItem
    {
        return DB::transaction(function () use ($sale, $data, $reason, $user) {
            $this->guardEditable($sale, $user);

            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $sale->tenant_id)
                ->where('id', $data['product_id'])
                ->with('presentations')
                ->firstOrFail();

            $presentation = null;
            if (! empty($data['presentation_id'])) {
                $presentation = $product->presentations->firstWhere('id', $data['presentation_id']);
                if (! $presentation) {
                    throw new SaleItemEditNotAllowed('La presentación no pertenece al producto.');
                }
            }

            $quantity = (float) $data['quantity'];
            $unitPrice = (float) $data['unit_price'];
            $subtotal = round($quantity * $unitPrice, 2);

            if ($presentation) {
                $productName = $product->name.' - '.$presentation->name;
                $unitType = 'unit';
                $quantityUnit = 'unit';
                $saleMode = 'presentation';
                $snapshot = SaleItemSnapshot::presentation($presentation);
            } else {
                $productName = $product->name;
                $unitType = in_array($product->sale_mode, ['weight', 'both'], true)
                    ? 'kg'
                    : $product->unit_type;
                $quantityUnit = $unitType;
                $saleMode = ($product->sale_mode === 'weight' || $product->sale_mode === 'both')
                    ? 'weight'
                    : 'piece';
                $snapshot = null;
            }

            $item = SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'presentation_id' => $presentation?->id,
                'product_name' => $productName,
                'unit_type' => $unitType,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'original_unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'notes' => $data['notes'] ?? null,
                'presentation_snapshot' => $snapshot,
                'sale_mode_at_sale' => $saleMode,
                'quantity_unit' => $quantityUnit,
                'created_by' => $user->id,
            ]);

            $this->recalculateSale($sale, $user);

            SaleItemChange::create([
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'event' => SaleItemChange::EVENT_ADDED,
                'before' => null,
                'after' => SaleItemSnapshot::item($item),
                'diff' => null,
                'reason' => $reason,
                'user_id' => $user->id,
            ]);

            return $item;
        });
    }

    /**
     * @param  array{quantity: float|int, unit_price: float|int}  $data
     */
    public function update(Sale $sale, SaleItem $item, array $data, ?string $reason, User $user): SaleItem
    {
        return DB::transaction(function () use ($sale, $item, $data, $reason, $user) {
            $this->guardEditable($sale, $user);

            if ($item->sale_id !== $sale->id) {
                throw new SaleItemEditNotAllowed('El item no pertenece a esta venta.');
            }
            if ($item->trashed()) {
                throw new SaleItemEditNotAllowed('No se puede editar un item eliminado.');
            }

            $newQuantity = round((float) $data['quantity'], 3);
            $newUnitPrice = round((float) $data['unit_price'], 2);
            $currentQuantity = round((float) $item->quantity, 3);
            $currentUnitPrice = round((float) $item->unit_price, 2);

            if ($newQuantity === $currentQuantity && $newUnitPrice === $currentUnitPrice) {
                throw new SaleItemEditNoOp('No detectamos cambios en cantidad o precio.');
            }

            if ($newQuantity <= 0) {
                throw new SaleItemEditNotAllowed('Para retirar el producto, usa la opción de eliminar.');
            }

            $before = SaleItemSnapshot::item($item);

            $item->update([
                'quantity' => $newQuantity,
                'unit_price' => $newUnitPrice,
                'subtotal' => round($newQuantity * $newUnitPrice, 2),
                'updated_by' => $user->id,
            ]);

            $this->recalculateSale($sale, $user);

            $after = SaleItemSnapshot::item($item->refresh());
            $diff = $this->buildDiff($before, $after);

            SaleItemChange::create([
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'event' => SaleItemChange::EVENT_UPDATED,
                'before' => $before,
                'after' => $after,
                'diff' => $diff,
                'reason' => $reason,
                'user_id' => $user->id,
            ]);

            return $item;
        });
    }

    public function remove(Sale $sale, SaleItem $item, string $reason, User $user): void
    {
        DB::transaction(function () use ($sale, $item, $reason, $user) {
            $this->guardEditable($sale, $user);

            if ($item->sale_id !== $sale->id) {
                throw new SaleItemEditNotAllowed('El item no pertenece a esta venta.');
            }
            if ($item->trashed()) {
                throw new SaleItemEditNotAllowed('Este item ya fue eliminado.');
            }
            if (trim($reason) === '') {
                throw new SaleItemEditNotAllowed('Debes indicar un motivo para eliminar el item.');
            }

            $before = SaleItemSnapshot::item($item);

            $item->update(['deleted_by' => $user->id]);
            $item->delete();

            $this->recalculateSale($sale, $user);

            SaleItemChange::create([
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'event' => SaleItemChange::EVENT_REMOVED,
                'before' => $before,
                'after' => null,
                'diff' => null,
                'reason' => $reason,
                'user_id' => $user->id,
            ]);
        });
    }

    private function guardEditable(Sale $sale, User $user): void
    {
        $fresh = Sale::lockForUpdate()->findOrFail($sale->id);

        if ($fresh->branch_id !== $user->branch_id) {
            throw new SaleItemEditNotAllowed('Esta venta pertenece a otra sucursal.');
        }

        if (! in_array($fresh->status, [SaleStatus::Active, SaleStatus::Pending], true)) {
            if ($fresh->status === SaleStatus::Completed) {
                throw new SaleItemEditNotAllowed(
                    'Esta venta ya está cobrada. Elimina el pago antes de modificar los items.'
                );
            }
            throw new SaleItemEditNotAllowed('Esta venta está cancelada y no se puede editar.');
        }

        if ($fresh->isLockedBy(null) && $fresh->locked_by !== $user->id) {
            throw new SaleItemEditNotAllowed('Esta venta está siendo operada por otro usuario.');
        }

        // Refrescamos la instancia que el caller pasó para que reflejé el lock
        // actual; los métodos públicos vuelven a usar $sale para recalcular.
        $sale->setRawAttributes($fresh->getAttributes());
        $sale->exists = true;
    }

    private function recalculateSale(Sale $sale, User $user): void
    {
        $total = SaleItem::where('sale_id', $sale->id)
            ->whereNull('deleted_at')
            ->sum('subtotal');

        $sale->update(['total' => round((float) $total, 2)]);
        $this->payments->recalculate($sale->refresh(), $user);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function buildDiff(array $before, array $after): array
    {
        $diff = [];
        foreach (['quantity', 'unit_price', 'subtotal'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $diff[$field] = [$before[$field], $after[$field]];
            }
        }

        return $diff;
    }
}
