<?php

namespace Tests\Feature\Services;

use App\Enums\SaleStatus;
use App\Exceptions\SaleItemEditNoOp;
use App\Exceptions\SaleItemEditNotAllowed;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItemChange;
use App\Services\SaleItemEditor;
use App\Services\SalePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleItemEditorTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private SaleItemEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->editor = app(SaleItemEditor::class);
    }

    private function product(array $attrs = []): Product
    {
        return $this->makeProduct(array_merge(['price' => 100, 'sale_mode' => 'piece', 'unit_type' => 'pieza'], $attrs));
    }

    private function activeSale(array $items = []): Sale
    {
        $sale = $this->makeCompletedSale([
            'status' => SaleStatus::Active->value,
            'completed_at' => null,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'total' => 100,
            'locked_by' => $this->adminSucursal->id,
            'locked_at' => now(),
        ], $items ?: [['product_id' => $this->product()->id, 'unit_price' => 100, 'quantity' => 1]]);
        // Sincroniza total con la suma real de items.
        $sale->update([
            'total' => $sale->items->sum('subtotal'),
            'amount_pending' => $sale->items->sum('subtotal'),
        ]);

        return $sale->refresh();
    }

    public function test_add_creates_item_recalculates_total_and_logs_event(): void
    {
        $sale = $this->activeSale();
        $product = $this->product(['price' => 75]);

        $item = $this->editor->add(
            $sale,
            ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50],
            'Cliente pidió uno más',
            $this->adminSucursal,
        );

        $this->assertSame(100.0, (float) $item->subtotal);
        $this->assertSame($this->adminSucursal->id, $item->created_by);

        $sale->refresh();
        $this->assertSame(200.0, (float) $sale->total);
        // No tenía pagos, debe seguir Active con amount_pending = total.
        $this->assertSame(SaleStatus::Active, $sale->status);
        $this->assertSame(200.0, (float) $sale->amount_pending);

        $change = SaleItemChange::where('sale_id', $sale->id)->latest()->first();
        $this->assertSame(SaleItemChange::EVENT_ADDED, $change->event);
        $this->assertNull($change->before);
        $this->assertEquals(100, $change->after['subtotal']);
        $this->assertSame('Cliente pidió uno más', $change->reason);
        $this->assertSame($this->adminSucursal->id, $change->user_id);
    }

    public function test_update_changes_quantity_and_price_logs_diff(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        $this->editor->update(
            $sale,
            $item,
            ['quantity' => 3, 'unit_price' => 90],
            null,
            $this->adminSucursal,
        );

        $item->refresh();
        $this->assertSame(3.0, (float) $item->quantity);
        $this->assertSame(90.0, (float) $item->unit_price);
        $this->assertSame(270.0, (float) $item->subtotal);
        $this->assertSame($this->adminSucursal->id, $item->updated_by);

        $sale->refresh();
        $this->assertSame(270.0, (float) $sale->total);

        $change = SaleItemChange::where('sale_id', $sale->id)->latest()->first();
        $this->assertSame(SaleItemChange::EVENT_UPDATED, $change->event);
        $this->assertEquals([1, 3], $change->diff['quantity']);
        $this->assertEquals([100, 90], $change->diff['unit_price']);
        $this->assertEquals([100, 270], $change->diff['subtotal']);
    }

    public function test_update_rejects_no_op(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        $this->expectException(SaleItemEditNoOp::class);
        $this->editor->update($sale, $item, ['quantity' => 1, 'unit_price' => 100], null, $this->adminSucursal);
    }

    public function test_update_rejects_zero_quantity_suggesting_remove(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        try {
            $this->editor->update($sale, $item, ['quantity' => 0, 'unit_price' => 100], null, $this->adminSucursal);
            $this->fail('Expected SaleItemEditNotAllowed');
        } catch (SaleItemEditNotAllowed $e) {
            $this->assertStringContainsString('eliminar', $e->getMessage());
        }
    }

    public function test_remove_soft_deletes_item_logs_event_and_recalculates(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        $this->editor->remove($sale, $item, 'Producto agotado', $this->adminSucursal);

        $item->refresh();
        $this->assertNotNull($item->deleted_at);
        $this->assertSame($this->adminSucursal->id, $item->deleted_by);

        $sale->refresh();
        $this->assertSame(0.0, (float) $sale->total);

        $change = SaleItemChange::where('sale_id', $sale->id)->latest()->first();
        $this->assertSame(SaleItemChange::EVENT_REMOVED, $change->event);
        $this->assertEquals(100, $change->before['subtotal']);
        $this->assertNull($change->after);
        $this->assertSame('Producto agotado', $change->reason);
    }

    public function test_remove_rejects_empty_reason(): void
    {
        $sale = $this->activeSale();
        $item = $sale->items->first();

        $this->expectException(SaleItemEditNotAllowed::class);
        $this->editor->remove($sale, $item, '   ', $this->adminSucursal);
    }

    public function test_rejects_when_sale_completed_with_helpful_message(): void
    {
        $sale = $this->activeSale();
        $sale->update(['status' => SaleStatus::Completed, 'completed_at' => now()]);

        try {
            $this->editor->add(
                $sale,
                ['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 50],
                null,
                $this->adminSucursal,
            );
            $this->fail('Expected SaleItemEditNotAllowed');
        } catch (SaleItemEditNotAllowed $e) {
            $this->assertStringContainsString('cobrada', $e->getMessage());
        }
    }

    public function test_rejects_when_sale_cancelled(): void
    {
        $sale = $this->activeSale();
        $sale->update(['status' => SaleStatus::Cancelled, 'cancelled_at' => now()]);

        $this->expectException(SaleItemEditNotAllowed::class);
        $this->editor->add(
            $sale,
            ['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 50],
            null,
            $this->adminSucursal,
        );
    }

    public function test_rejects_when_lock_belongs_to_another_user(): void
    {
        $sale = $this->activeSale();
        $sale->update(['locked_by' => $this->cajero->id, 'locked_at' => now()]);

        try {
            $this->editor->add(
                $sale,
                ['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 50],
                null,
                $this->adminSucursal,
            );
            $this->fail('Expected SaleItemEditNotAllowed');
        } catch (SaleItemEditNotAllowed $e) {
            $this->assertStringContainsString('otro usuario', $e->getMessage());
        }
    }

    public function test_update_with_partial_payment_keeps_completed_status_when_total_equals_paid(): void
    {
        // Caso real: venta de 200 con un pago parcial de 100 (Active, pending=100).
        // Si el admin reduce un item para que el total quede en 100, recalculate la marca Completed.
        $sale = $this->activeSale();
        $item = $sale->items->first();
        $item->update(['quantity' => 2, 'subtotal' => 200]);
        $sale->update(['total' => 200, 'amount_pending' => 200]);

        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 100,
        ]);
        // El controlador de pagos normalmente correría recalculate; lo simulamos.
        app(SalePaymentService::class)->recalculate($sale->refresh(), $this->cajero);

        // Ahora el admin baja la cantidad a 1 → total queda 100 → status Completed.
        $this->editor->update($sale, $item, ['quantity' => 1, 'unit_price' => 100], 'Ajuste', $this->adminSucursal);

        $sale->refresh();
        $this->assertSame(100.0, (float) $sale->total);
        $this->assertSame(0.0, (float) $sale->amount_pending);
        $this->assertSame(SaleStatus::Completed, $sale->status);
    }
}
