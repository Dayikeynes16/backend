<?php

namespace Tests\Feature\Historial;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\Purchase;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseHistoryTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function provider(): Provider
    {
        return Provider::firstOrCreate(
            ['name' => 'Don Pedro'],
            ['type' => 'mayorista_carne'],
        );
    }

    private function purchasePayload(array $override = []): array
    {
        return array_merge([
            'provider_id' => $this->provider()->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [[
                'concept' => 'Chuleta',
                'quantity' => 1,
                'unit' => 'kg',
                'unit_price' => 100,
            ]],
        ], $override);
    }

    public function test_creating_a_purchase_writes_created_history(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.compras.store', $this->tenant->slug), $this->purchasePayload())
            ->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $purchase->getMorphClass(),
            'auditable_id' => $purchase->id,
            'event' => AuditEvent::Created->value,
            'user_id' => $this->adminSucursal->id,
        ]);
    }

    public function test_editing_a_purchase_writes_updated_history_with_diff(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.compras.store', $this->tenant->slug), $this->purchasePayload())->assertRedirect();
        $purchase = Purchase::firstOrFail();

        $this->put(route('sucursal.compras.update', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            $this->purchasePayload(['items' => [[
                'concept' => 'Chuleta', 'quantity' => 3, 'unit' => 'kg', 'unit_price' => 100,
            ]]]))->assertRedirect();

        $log = AuditLog::where('event', AuditEvent::Updated->value)->firstOrFail();
        $this->assertArrayHasKey('changed', $log->changes['items']);
        // El total cambió de 100 a 300. Los valores se serializan a JSON al
        // persistir el log, y PHP decodifica los floats enteros (100.0) como
        // int (100); por eso comparamos por valor, no por tipo estricto.
        $this->assertEquals([100.0, 300.0], $log->changes['fields']['total']);
    }

    public function test_cancelling_a_purchase_writes_cancelled_history(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.compras.store', $this->tenant->slug), $this->purchasePayload())->assertRedirect();
        $purchase = Purchase::firstOrFail();

        $this->patch(route('sucursal.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]),
            ['reason' => 'duplicada'])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $purchase->id,
            'event' => AuditEvent::Cancelled->value,
        ]);
    }

    public function test_index_exposes_history_in_purchase_payload(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.compras.store', $this->tenant->slug), $this->purchasePayload())->assertRedirect();

        $this->get(route('sucursal.compras.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->has('purchases.0.history', 1)
                ->where('purchases.0.history.0.event', 'created'));
    }

    public function test_diff_detects_field_and_item_changes(): void
    {
        $logger = new AuditLogger;

        $before = [
            'fields' => ['provider' => 'Don Pedro', 'total' => 100.0, 'invoice_number' => null],
            'items' => [
                ['concept' => 'Chuleta', 'quantity' => 1.0, 'unit' => 'kg', 'unit_price' => 100.0],
            ],
        ];
        $after = [
            'fields' => ['provider' => 'Don Pedro', 'total' => 280.0, 'invoice_number' => 'F-1'],
            'items' => [
                ['concept' => 'Costilla', 'quantity' => 2.0, 'unit' => 'kg', 'unit_price' => 90.0],
            ],
        ];

        $changes = $logger->diff($before, $after);

        $this->assertSame([100.0, 280.0], $changes['fields']['total']);
        $this->assertSame([null, 'F-1'], $changes['fields']['invoice_number']);
        $this->assertArrayNotHasKey('provider', $changes['fields']); // no cambió
        $this->assertCount(1, $changes['items']['added']);
        $this->assertSame('Costilla', $changes['items']['added'][0]['concept']);
        $this->assertCount(1, $changes['items']['removed']);
        $this->assertSame('Chuleta', $changes['items']['removed'][0]['concept']);
    }

    public function test_diff_returns_empty_when_nothing_changed(): void
    {
        $logger = new AuditLogger;
        $snap = [
            'fields' => ['total' => 100.0],
            'items' => [['concept' => 'X', 'quantity' => 1.0, 'unit' => 'kg', 'unit_price' => 100.0]],
        ];

        $this->assertSame([], $logger->diff($snap, $snap));
    }
}
