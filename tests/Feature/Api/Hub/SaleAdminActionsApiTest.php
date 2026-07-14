<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Potestades de admin-sucursal sobre ventas desde el hub (paridad P1):
 * editar/eliminar pagos, cancelación directa, reabrir y aprobar/rechazar
 * solicitudes de cancelación.
 */
class SaleAdminActionsApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('hub')->plainTextToken;
    }

    private function sale(float $total = 100, string $status = 'active', float $paid = 0): Sale
    {
        return Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'S-'.fake()->unique()->numerify('#####'),
            'payment_method' => 'cash',
            'total' => $total,
            'amount_paid' => $paid,
            'amount_pending' => $total - $paid,
            'origin' => 'api',
            'status' => SaleStatus::from($status),
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
    }

    private function payment(Sale $sale, float $amount, ?int $customerPaymentId = null): Payment
    {
        return Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => $amount,
            'customer_payment_id' => $customerPaymentId,
        ]);
    }

    // ── Editar / eliminar pagos ─────────────────────────────────────────

    public function test_admin_updates_payment_and_sale_recalculates(): void
    {
        $sale = $this->sale(100, 'completed', 100);
        $p = $this->payment($sale, 100);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->putJson("/api/v1/hub/sales/{$sale->id}/payments/{$p->id}", ['method' => 'card', 'amount' => 60])
            ->assertOk()
            ->assertJsonPath('data.amount_paid', 60)
            ->assertJsonPath('data.amount_pending', 40)
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('card', $p->refresh()->method);
        $this->assertSame($this->adminSucursal->id, $p->updated_by);
    }

    public function test_update_rejects_amount_above_remaining_total(): void
    {
        $sale = $this->sale(100, 'active');
        $this->payment($sale, 70);
        $p = $this->payment($sale, 20);

        // Tope = 100 − 70 (los otros pagos) = 30.
        $this->withToken($this->tokenFor($this->adminSucursal))
            ->putJson("/api/v1/hub/sales/{$sale->id}/payments/{$p->id}", ['method' => 'cash', 'amount' => 31])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_cajero_cannot_update_or_delete_payments(): void
    {
        $sale = $this->sale(100, 'active');
        $p = $this->payment($sale, 50);

        $this->withToken($this->tokenFor($this->cajero))
            ->putJson("/api/v1/hub/sales/{$sale->id}/payments/{$p->id}", ['method' => 'cash', 'amount' => 40])
            ->assertForbidden();

        $this->withToken($this->tokenFor($this->cajero))
            ->deleteJson("/api/v1/hub/sales/{$sale->id}/payments/{$p->id}")
            ->assertForbidden();
    }

    public function test_global_payment_children_are_protected(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Fiado',
            'status' => 'active',
        ]);
        $cp = CustomerPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'user_id' => $this->cajero->id,
            'folio' => 'CG-00001',
            'method' => 'cash',
            'amount_received' => 50,
            'amount_applied' => 50,
            'change_given' => 0,
            'sales_affected_count' => 1,
        ]);
        $sale = $this->sale(100, 'active');
        $p = $this->payment($sale, 50, $cp->id);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->putJson("/api/v1/hub/sales/{$sale->id}/payments/{$p->id}", ['method' => 'cash', 'amount' => 40])
            ->assertUnprocessable();

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/sales/{$sale->id}/payments/{$p->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('payments', ['id' => $p->id, 'deleted_at' => null]);
    }

    public function test_admin_deletes_payment_and_sale_recalculates(): void
    {
        $sale = $this->sale(100, 'completed', 100);
        $p = $this->payment($sale, 100);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->deleteJson("/api/v1/hub/sales/{$sale->id}/payments/{$p->id}")
            ->assertOk()
            ->assertJsonPath('data.amount_paid', 0)
            ->assertJsonPath('data.amount_pending', 100)
            ->assertJsonPath('data.status', 'active');

        $this->assertSoftDeleted('payments', ['id' => $p->id]);
    }

    // ── Cancelación directa ─────────────────────────────────────────────

    public function test_admin_cancels_sale_directly_and_payments_are_removed(): void
    {
        $sale = $this->sale(100, 'completed', 100);
        $p = $this->payment($sale, 100);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/sales/{$sale->id}/cancel", ['cancel_reason' => 'Producto en mal estado'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $sale->refresh();
        $this->assertEquals(0, (float) $sale->amount_paid);
        $this->assertEquals(0, (float) $sale->amount_pending);
        $this->assertSame($this->adminSucursal->id, $sale->cancelled_by);
        $this->assertSame('Producto en mal estado', $sale->cancel_reason);
        $this->assertSoftDeleted('payments', ['id' => $p->id]);
    }

    public function test_cancel_requires_reason(): void
    {
        $sale = $this->sale();

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/sales/{$sale->id}/cancel", [])
            ->assertUnprocessable();
    }

    /** Separado del anterior: el guard de Sanctum cachea al usuario por test. */
    public function test_cajero_cannot_cancel_directly(): void
    {
        $sale = $this->sale();

        $this->withToken($this->tokenFor($this->cajero))
            ->postJson("/api/v1/hub/sales/{$sale->id}/cancel", ['cancel_reason' => 'x'])
            ->assertForbidden();
    }

    public function test_cancel_rejects_already_cancelled_sale(): void
    {
        $sale = $this->sale(100, 'cancelled');

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/sales/{$sale->id}/cancel", ['cancel_reason' => 'x'])
            ->assertUnprocessable();
    }

    // ── Reabrir ─────────────────────────────────────────────────────────

    public function test_admin_reopens_completed_sale(): void
    {
        $sale = $this->sale(100, 'completed', 100);
        $this->payment($sale, 60);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/sales/{$sale->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.amount_pending', 40);

        $this->assertNull($sale->refresh()->completed_at);
    }

    public function test_reopen_rejects_non_completed(): void
    {
        $active = $this->sale(100, 'active');

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/sales/{$active->id}/reopen")
            ->assertUnprocessable();
    }

    public function test_cajero_cannot_reopen(): void
    {
        $completed = $this->sale(100, 'completed', 100);

        $this->withToken($this->tokenFor($this->cajero))
            ->postJson("/api/v1/hub/sales/{$completed->id}/reopen")
            ->assertForbidden();
    }

    // ── Solicitudes de cancelación ──────────────────────────────────────

    private function saleWithCancelRequest(): Sale
    {
        $sale = $this->sale(100, 'completed', 100);
        $this->payment($sale, 100);
        $sale->update([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $this->cajero->id,
            'cancel_request_reason' => 'Cliente se arrepintió',
        ]);

        return $sale;
    }

    public function test_index_lists_pending_requests(): void
    {
        $sale = $this->saleWithCancelRequest();

        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson('/api/v1/hub/cancel-requests')
            ->assertOk();

        $this->assertCount(1, $res->json('requests'));
        $this->assertSame($sale->folio, $res->json('requests.0.folio'));
        $this->assertSame('Cliente se arrepintió', $res->json('requests.0.reason'));
        $this->assertSame($this->cajero->name, $res->json('requests.0.requested_by'));
    }

    public function test_cajero_cannot_list_cancel_requests(): void
    {
        $this->withToken($this->tokenFor($this->cajero))
            ->getJson('/api/v1/hub/cancel-requests')
            ->assertForbidden();
    }

    public function test_approve_cancels_sale_with_request_reason_fallback(): void
    {
        $sale = $this->saleWithCancelRequest();

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/cancel-requests/{$sale->id}/approve", [])
            ->assertOk()
            ->assertJsonPath('recalculated_shifts', true);

        $sale->refresh();
        $this->assertSame(SaleStatus::Cancelled, $sale->status);
        $this->assertSame('Cliente se arrepintió', $sale->cancel_reason);
        $this->assertEquals(0, (float) $sale->amount_paid);
    }

    public function test_reject_clears_the_request(): void
    {
        $sale = $this->saleWithCancelRequest();

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/cancel-requests/{$sale->id}/reject")
            ->assertOk();

        $sale->refresh();
        $this->assertNull($sale->cancel_requested_at);
        $this->assertNull($sale->cancel_request_reason);
        $this->assertSame(SaleStatus::Completed, $sale->status);
    }
}
