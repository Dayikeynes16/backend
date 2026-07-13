<?php

namespace Tests\Feature\Api\Hub;

use App\Models\CashRegisterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ShiftHistoryApiTest extends TestCase
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

    private function closedShift(User $user, array $attrs = []): CashRegisterShift
    {
        return CashRegisterShift::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'opened_at' => now()->subHours(9),
            'closed_at' => now()->subHour(),
            'opening_amount' => 500,
            'total_cash' => 1000,
            'total_card' => 0,
            'total_transfer' => 0,
            'total_sales' => 1000,
            'sale_count' => 5,
            'declared_amount' => 1500,
            'expected_amount' => 1500,
            'difference' => 0,
            'difference_card' => 0,
            'difference_transfer' => 0,
        ], $attrs));
    }

    // Nota: cada test usa UN solo usuario — el guard de Sanctum cachea al
    // usuario dentro del mismo test (misma razón que en CustomerPaymentApiTest).

    public function test_admin_lists_all_branch_closed_shifts(): void
    {
        $this->closedShift($this->cajero);
        $this->closedShift($this->adminSucursal);
        // Turno abierto: no debe aparecer.
        CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id, 'opened_at' => now(), 'opening_amount' => 0,
        ]);

        $admin = $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson('/api/v1/hub/shifts')->assertOk();
        $this->assertCount(2, $admin->json('data'));
        $this->assertTrue($admin->json('is_admin'));
    }

    public function test_cajero_lists_only_own_shifts(): void
    {
        $this->closedShift($this->cajero);
        $this->closedShift($this->adminSucursal);

        $cajero = $this->withToken($this->tokenFor($this->cajero))
            ->getJson('/api/v1/hub/shifts')->assertOk();
        $this->assertCount(1, $cajero->json('data'));
        $this->assertFalse($cajero->json('is_admin'));
    }

    public function test_index_filters_by_date_range(): void
    {
        $this->closedShift($this->cajero, ['opened_at' => now()->subDays(10), 'closed_at' => now()->subDays(10)->addHours(8)]);
        $this->closedShift($this->cajero, ['opened_at' => now()->subHours(9)]);

        $res = $this->withToken($this->tokenFor($this->cajero))
            ->getJson('/api/v1/hub/shifts?from='.now()->subDay()->toDateString())
            ->assertOk();

        $this->assertCount(1, $res->json('data'));
    }

    public function test_show_returns_summary_verdict_and_whatsapp(): void
    {
        $this->tenant->update(['owner_whatsapp' => '5216621112233']);
        $shift = $this->closedShift($this->cajero);

        $res = $this->withToken($this->tokenFor($this->cajero))
            ->getJson("/api/v1/hub/shifts/{$shift->id}")
            ->assertOk();

        $this->assertSame($shift->id, $res->json('data.id'));
        $this->assertFalse($res->json('summary.is_open'));
        $this->assertSame('balanced', $res->json('verdict.status'));
        $this->assertTrue($res->json('whatsapp.has_owner_whatsapp'));
        $this->assertStringContainsString('wa.me', (string) $res->json('whatsapp.url'));
    }

    public function test_cajero_cannot_view_another_users_corte(): void
    {
        $shift = $this->closedShift($this->adminSucursal);

        $this->withToken($this->tokenFor($this->cajero))
            ->getJson("/api/v1/hub/shifts/{$shift->id}")
            ->assertForbidden();
    }

    public function test_cross_branch_corte_is_not_found(): void
    {
        $otherCajero = $this->makeUser('caja-s2@test.local', 'cajero', $this->secondBranch->id);
        $shift = $this->closedShift($otherCajero);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->getJson("/api/v1/hub/shifts/{$shift->id}")
            ->assertNotFound();
    }

    public function test_close_returns_verdict_whatsapp_and_sales_generated(): void
    {
        CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id, 'opened_at' => now()->subHour(), 'opening_amount' => 200,
        ]);

        $res = $this->withToken($this->tokenFor($this->cajero))
            ->postJson('/api/v1/hub/shift/close', ['declared_amount' => 200])
            ->assertOk();

        $this->assertSame('balanced', $res->json('verdict.status'));
        $this->assertFalse($res->json('whatsapp.has_owner_whatsapp'));
        $this->assertSame(0, $res->json('summary.sales_generated.count'));
        $this->assertEquals(0, $res->json('summary.sales_generated.amount'));
    }

    public function test_cajero_cannot_recalculate(): void
    {
        $shift = $this->closedShift($this->cajero);

        $this->withToken($this->tokenFor($this->cajero))
            ->postJson("/api/v1/hub/shifts/{$shift->id}/recalculate")
            ->assertForbidden();
    }

    public function test_admin_recalculate_fixes_totals(): void
    {
        // Corte con totales "sucios": declarado 500 contra esperado falso 1500.
        $shift = $this->closedShift($this->cajero, [
            'total_cash' => 1000, 'expected_amount' => 1500, 'declared_amount' => 500, 'difference' => -1000,
        ]);

        // Sin pagos reales, el esperado se recalcula a solo el fondo (500).
        $res = $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/shifts/{$shift->id}/recalculate")
            ->assertOk();

        $this->assertEquals(500, $res->json('data.expected_amount'));
        $this->assertEquals(0, $res->json('data.difference'));
        $this->assertNotNull($res->json('verdict'));
        $this->assertEquals(500, $shift->refresh()->expected_amount);
    }

    public function test_cajero_cannot_reopen(): void
    {
        $shift = $this->closedShift($this->cajero);

        $this->withToken($this->tokenFor($this->cajero))
            ->postJson("/api/v1/hub/shifts/{$shift->id}/reopen")
            ->assertForbidden();
    }

    public function test_admin_reopen_requires_no_other_open_shift(): void
    {
        $shift = $this->closedShift($this->cajero);

        // Con otro turno abierto del mismo cajero → 422.
        $open = CashRegisterShift::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id, 'opened_at' => now(), 'opening_amount' => 0,
        ]);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/shifts/{$shift->id}/reopen")
            ->assertStatus(422);

        $open->update(['closed_at' => now()]);

        $this->withToken($this->tokenFor($this->adminSucursal))
            ->postJson("/api/v1/hub/shifts/{$shift->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.closed', false);

        $this->assertNull($shift->refresh()->closed_at);
        $this->assertNull($shift->declared_amount);
    }
}
