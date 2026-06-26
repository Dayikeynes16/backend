<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeSale(int $branchId, SaleStatus $status, float $total = 100): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'api',
            'status' => $status,
        ]);
    }

    private function makeSaleWithItem(SaleStatus $status = SaleStatus::Active, float $total = 100): Sale
    {
        $sale = $this->makeSale($this->branch->id, $status, $total);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Producto',
            'unit_type' => 'piece',
            'quantity' => 1,
            'unit_price' => $total,
            'subtotal' => $total,
        ]);

        return $sale;
    }

    public function test_index_lists_only_active_and_pending_of_own_branch(): void
    {
        $this->makeSale($this->branch->id, SaleStatus::Active);
        $this->makeSale($this->branch->id, SaleStatus::Pending);
        $this->makeSale($this->branch->id, SaleStatus::Completed);
        $this->makeSale($this->secondBranch->id, SaleStatus::Active);

        $res = $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/sales')
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
    }

    public function test_show_returns_sale_with_items_and_payments(): void
    {
        $sale = $this->makeSale($this->branch->id, SaleStatus::Active);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $sale->id)
            ->assertJsonPath('data.folio', $sale->folio);
    }

    public function test_show_forbids_other_branch_sale(): void
    {
        $sale = $this->makeSale($this->secondBranch->id, SaleStatus::Active);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertStatus(404);
    }

    public function test_show_exposes_enabled_payment_methods_and_status_label(): void
    {
        // Sucursal con solo efectivo y tarjeta habilitados.
        $this->branch->forceFill(['payment_methods_enabled' => ['cash', 'card']])->save();
        $sale = $this->makeSale($this->branch->id, SaleStatus::Pending);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('payment_methods', ['cash', 'card'])
            ->assertJsonPath('data.status_label', 'Pendiente');
    }

    public function test_show_includes_item_unit_fields(): void
    {
        $sale = $this->makeSale($this->branch->id, SaleStatus::Active);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_name' => 'Pierna de cerdo',
            'unit_type' => 'kg',
            'quantity_unit' => 'kg',
            'quantity' => 1.25,
            'unit_price' => 80,
            'subtotal' => 100,
        ]);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_type', 'kg')
            ->assertJsonPath('data.items.0.quantity_unit', 'kg')
            ->assertJsonPath('data.items.0.quantity', 1.25);
    }

    public function test_show_includes_assigned_customer(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Fiado',
            'phone' => '5551234',
            'status' => 'active',
        ]);
        $sale = $this->makeSale($this->branch->id, SaleStatus::Active);
        $sale->forceFill(['customer_id' => $customer->id])->save();

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Cliente Fiado')
            ->assertJsonPath('data.customer.phone', '5551234');
    }

    public function test_index_filters_by_status_and_returns_counts(): void
    {
        $this->makeSale($this->branch->id, SaleStatus::Active);
        $this->makeSale($this->branch->id, SaleStatus::Active);
        $this->makeSale($this->branch->id, SaleStatus::Pending);

        $token = $this->cajero->createToken('hub')->plainTextToken;

        $all = $this->withToken($token)->getJson('/api/v1/hub/sales')->assertOk();
        $this->assertCount(3, $all->json('data'));
        $this->assertSame(2, $all->json('counts.active'));
        $this->assertSame(1, $all->json('counts.pending'));
        $this->assertSame(3, $all->json('counts.all'));

        $pending = $this->withToken($token)->getJson('/api/v1/hub/sales?status=pending')->assertOk();
        $this->assertCount(1, $pending->json('data'));
    }

    public function test_update_status_pauses_and_reactivates(): void
    {
        $sale = $this->makeSaleWithItem(SaleStatus::Active);
        $token = $this->cajero->createToken('hub')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/v1/hub/sales/{$sale->id}/status", ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->withToken($token)
            ->patchJson("/api/v1/hub/sales/{$sale->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_update_status_forbids_completing_from_cashier(): void
    {
        $sale = $this->makeSaleWithItem(SaleStatus::Active);

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->patchJson("/api/v1/hub/sales/{$sale->id}/status", ['status' => 'completed'])
            ->assertStatus(403);
    }

    public function test_update_status_rejects_invalid_transition(): void
    {
        $sale = $this->makeSaleWithItem(SaleStatus::Active);

        // Active -> Active no es una transición válida.
        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->patchJson("/api/v1/hub/sales/{$sale->id}/status", ['status' => 'active'])
            ->assertStatus(422);
    }

    public function test_request_cancel_marks_request_once(): void
    {
        $sale = $this->makeSaleWithItem(SaleStatus::Active);
        $token = $this->cajero->createToken('hub')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$sale->id}/request-cancel", ['cancel_request_reason' => 'Cliente se arrepintió'])
            ->assertOk()
            ->assertJsonPath('data.cancel_request_reason', 'Cliente se arrepintió');

        $this->assertNotNull($sale->refresh()->cancel_requested_at);

        // Segunda solicitud → 422.
        $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$sale->id}/request-cancel", ['cancel_request_reason' => 'Otra'])
            ->assertStatus(422);
    }

    public function test_assign_and_unassign_customer(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Doña Marta',
            'phone' => '5559999',
            'status' => 'active',
        ]);
        $sale = $this->makeSaleWithItem(SaleStatus::Active);
        $token = $this->cajero->createToken('hub')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/v1/hub/sales/{$sale->id}/customer", ['customer_id' => $customer->id])
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Doña Marta');

        $this->assertSame($customer->id, $sale->refresh()->customer_id);

        $this->withToken($token)
            ->patchJson("/api/v1/hub/sales/{$sale->id}/customer", ['customer_id' => null])
            ->assertOk()
            ->assertJsonPath('data.customer', null);

        $this->assertNull($sale->refresh()->customer_id);
    }

    public function test_whatsapp_link_reports_needs_phone_then_works_after_capture(): void
    {
        $sale = $this->makeSaleWithItem(SaleStatus::Active);
        $token = $this->cajero->createToken('hub')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/hub/sales/{$sale->id}/whatsapp")
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'needs_phone');

        $res = $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$sale->id}/whatsapp-phone", ['phone' => '5512345678'])
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->assertStringContainsString('wa.me', $res->json('url'));
    }

    public function test_lock_acquire_and_unlock(): void
    {
        $sale = $this->makeSaleWithItem(SaleStatus::Active);
        $token = $this->cajero->createToken('hub')->plainTextToken;

        $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/lock")->assertOk();
        $this->assertSame($this->cajero->id, $sale->refresh()->locked_by);

        $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/unlock")->assertOk();
        $this->assertNull($sale->refresh()->locked_by);
    }

    public function test_lock_conflict_returns_409(): void
    {
        $sale = $this->makeSaleWithItem(SaleStatus::Active);
        // La tiene otro usuario, lock vigente.
        $sale->forceFill(['locked_by' => $this->adminSucursal->id, 'locked_at' => now()])->save();

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->postJson("/api/v1/hub/sales/{$sale->id}/lock")
            ->assertStatus(409)
            ->assertJsonPath('locked', true);
    }

    public function test_resource_reports_locked_by_other(): void
    {
        $sale = $this->makeSaleWithItem(SaleStatus::Active);
        $sale->forceFill(['locked_by' => $this->adminSucursal->id, 'locked_at' => now()])->save();

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.locked_by_other', true);
    }
}
