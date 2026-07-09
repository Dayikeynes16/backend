<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    private function openShift(string $token): void
    {
        $this->withToken($token)->postJson('/api/v1/hub/shift/open', ['opening_amount' => 0])->assertCreated();
    }

    private function activeSale(int $branchId, float $total = 100): Sale
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
            'status' => SaleStatus::Active,
        ]);
    }

    public function test_payments_index_cajero_sees_only_own(): void
    {
        $sale = $this->activeSale($this->branch->id, 500);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->adminSucursal->id, 'method' => 'card', 'amount' => 50]);

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertEquals(100, $res->json('data.0.amount'));
        $this->assertEquals(100, $res->json('summary.total'));
        $this->assertFalse($res->json('is_admin'));
        $this->assertCount(0, $res->json('users'));
    }

    public function test_payments_index_admin_sees_all_and_filters_by_user(): void
    {
        $sale = $this->activeSale($this->branch->id, 500);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->adminSucursal->id, 'method' => 'card', 'amount' => 50]);

        $adminToken = $this->adminSucursal->createToken('hub')->plainTextToken;

        $all = $this->withToken($adminToken)->getJson('/api/v1/hub/payments')->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertEquals(150, $all->json('summary.total'));
        $this->assertTrue($all->json('is_admin'));
        $this->assertNotEmpty($all->json('users'));

        $filtered = $this->withToken($adminToken)
            ->getJson('/api/v1/hub/payments?user_id='.$this->cajero->id)
            ->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertEquals(100, $filtered->json('summary.total'));
    }

    public function test_payments_index_collapses_global_fifo_collection(): void
    {
        $sale1 = $this->activeSale($this->branch->id, 2000);
        $sale2 = $this->activeSale($this->branch->id, 200);

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Mike',
            'status' => 'active',
        ]);

        $cp = CustomerPayment::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'user_id' => $this->cajero->id,
            'folio' => 'CG-00001',
            'method' => 'transfer',
            'amount_received' => 2100,
            'amount_applied' => 2100,
            'change_given' => 0,
            'sales_affected_count' => 2,
        ]);

        // Dos pagos hijos del mismo cobro global + un pago directo (standalone).
        Payment::create(['sale_id' => $sale1->id, 'customer_payment_id' => $cp->id, 'user_id' => $this->cajero->id, 'method' => 'transfer', 'amount' => 1977.89]);
        Payment::create(['sale_id' => $sale2->id, 'customer_payment_id' => $cp->id, 'user_id' => $this->cajero->id, 'method' => 'transfer', 'amount' => 122.11]);
        Payment::create(['sale_id' => $sale1->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 50]);

        $res = $this->withToken($this->token())->getJson('/api/v1/hub/payments')->assertOk();

        // La lista colapsa el cobro global en 1 renglón → 2 entradas (global + directo).
        $this->assertCount(2, $res->json('data'));
        // El KPI total SÍ suma todo: 1977.89 + 122.11 + 50 = 2150.
        $this->assertEquals(2150, $res->json('summary.total'));

        $global = collect($res->json('data'))->firstWhere('type', 'global');
        $this->assertNotNull($global);
        $this->assertEquals('CG-00001', $global['folio']);
        $this->assertEquals(2100, $global['amount']); // amount_applied, no el hijo
        $this->assertEquals('Mike', $global['customer']['name']);
    }

    public function test_payment_requires_open_shift(): void
    {
        $sale = $this->activeSale($this->branch->id);
        $this->withToken($this->token())
            ->postJson("/api/v1/hub/sales/{$sale->id}/payments", ['method' => 'cash', 'amount' => 100])
            ->assertStatus(409);
    }

    public function test_full_payment_completes_sale_and_returns_change(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $sale = $this->activeSale($this->branch->id, 100);

        $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$sale->id}/payments", ['method' => 'cash', 'amount' => 120])
            ->assertCreated()
            // JSON serializa floats enteros sin decimales (20.0 -> 20); assertJsonPath compara estricto.
            ->assertJsonPath('change', 20)
            ->assertJsonPath('sale.status', SaleStatus::Completed->value)
            ->assertJsonPath('sale.amount_pending', 0);
    }

    public function test_payment_is_idempotent_by_client_reference(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $sale = $this->activeSale($this->branch->id, 100);
        $ref = 'pay-ref-1';

        $first = $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/payments", [
            'method' => 'cash', 'amount' => 50, 'client_reference' => $ref,
        ])->assertCreated();

        $second = $this->withToken($token)->postJson("/api/v1/hub/sales/{$sale->id}/payments", [
            'method' => 'cash', 'amount' => 50, 'client_reference' => $ref,
        ])->assertSuccessful();

        $this->assertSame(1, Payment::where('sale_id', $sale->id)->count());
        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
    }

    public function test_cannot_pay_other_branch_sale(): void
    {
        $token = $this->token();
        $this->openShift($token);
        $other = $this->activeSale($this->secondBranch->id, 100);

        $this->withToken($token)
            ->postJson("/api/v1/hub/sales/{$other->id}/payments", ['method' => 'cash', 'amount' => 100])
            ->assertStatus(404);
    }
}
