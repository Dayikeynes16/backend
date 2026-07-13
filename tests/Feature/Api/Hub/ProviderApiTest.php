<?php

namespace Tests\Feature\Api\Hub;

use App\Enums\PurchaseStatus;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ProviderApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function adminToken(): string
    {
        return $this->adminSucursal->createToken('hub')->plainTextToken;
    }

    private function enableToggle(): void
    {
        $this->branch->forceFill(['branch_admin_providers_enabled' => true])->save();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Proveedor Test',
            'type' => 'insumos',
            'phone' => '555',
        ], $overrides);
    }

    private function makeProvider(array $attrs = []): Provider
    {
        return Provider::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Carnes ABC',
            'type' => 'mayorista_carne',
            'status' => 'active',
        ], $attrs));
    }

    public function test_index_lists_providers_for_admin_sucursal(): void
    {
        $this->makeProvider();

        $res = $this->withToken($this->adminToken())
            ->getJson('/api/v1/hub/providers')
            ->assertOk();

        $this->assertSame('Carnes ABC', $res->json('data.0.name'));
        $this->assertSame('Mayorista de carne', $res->json('data.0.type_label'));
        $this->assertFalse($res->json('can_manage')); // toggle apagado por default
    }

    public function test_index_reports_can_manage_when_toggle_on(): void
    {
        $this->enableToggle();

        $res = $this->withToken($this->adminToken())
            ->getJson('/api/v1/hub/providers')
            ->assertOk();

        $this->assertTrue($res->json('can_manage'));
    }

    public function test_cajero_forbidden(): void
    {
        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/providers')
            ->assertStatus(403);
    }

    public function test_admin_empresa_forbidden_by_hub_role(): void
    {
        $this->withToken($this->adminEmpresa->createToken('hub')->plainTextToken)
            ->getJson('/api/v1/hub/providers')
            ->assertStatus(403);
    }

    public function test_store_forbidden_when_toggle_off(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/v1/hub/providers', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, Provider::withoutGlobalScopes()->count());
    }

    public function test_store_creates_provider_when_toggle_on(): void
    {
        $this->enableToggle();

        $this->withToken($this->adminToken())
            ->postJson('/api/v1/hub/providers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Proveedor Test')
            ->assertJsonPath('data.type_label', 'Insumos')
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(1, Provider::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $this->enableToggle();
        $this->makeProvider(['name' => 'Repetido']);

        $this->withToken($this->adminToken())
            ->postJson('/api/v1/hub/providers', $this->payload(['name' => 'Repetido']))
            ->assertStatus(422);
    }

    public function test_update_edits_provider_when_toggle_on(): void
    {
        $this->enableToggle();
        $provider = $this->makeProvider(['name' => 'Viejo']);

        $this->withToken($this->adminToken())
            ->putJson("/api/v1/hub/providers/{$provider->id}", $this->payload([
                'name' => 'Nuevo', 'status' => 'inactive',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Nuevo')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_update_forbidden_when_toggle_off(): void
    {
        $provider = $this->makeProvider();

        $this->withToken($this->adminToken())
            ->putJson("/api/v1/hub/providers/{$provider->id}", $this->payload(['status' => 'active']))
            ->assertStatus(403);
    }

    public function test_no_destroy_route(): void
    {
        $this->enableToggle();
        $provider = $this->makeProvider();

        // No existe ruta DELETE para proveedores en el hub (el borrado queda en empresa).
        $this->withToken($this->adminToken())
            ->deleteJson("/api/v1/hub/providers/{$provider->id}")
            ->assertStatus(405);
    }

    public function test_cross_tenant_update_returns_404(): void
    {
        $this->enableToggle();

        $otherTenant = Tenant::create(['name' => 'Otro', 'slug' => 'otro-tenant', 'status' => 'active']);
        app()->instance('tenant', $otherTenant);
        $foreign = Provider::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Ajeno', 'type' => 'otro', 'status' => 'active',
        ]);

        $this->withToken($this->adminToken())
            ->putJson("/api/v1/hub/providers/{$foreign->id}", $this->payload(['status' => 'active']))
            ->assertStatus(404);
    }

    private function makePurchase(Provider $p, float $total, float $paid = 0): Purchase
    {
        return Purchase::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $p->id,
            'folio' => 'CMP-'.fake()->unique()->numerify('#####'),
            'purchased_at' => now(),
            'status' => PurchaseStatus::Received,
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => $paid,
            'amount_pending' => $total - $paid,
        ]);
    }

    public function test_show_returns_resumen_and_debt(): void
    {
        $provider = $this->makeProvider();
        $this->makePurchase($provider, 100, 30);

        $res = $this->withToken($this->adminToken())
            ->getJson("/api/v1/hub/providers/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('resumen.compras_count', 1);

        $this->assertEquals(100, $res->json('resumen.total_comprado'));
        $this->assertEquals(70, $res->json('resumen.deuda_actual'));
        $this->assertNotNull($res->json('resumen.ultima_compra.folio'));
    }

    public function test_show_kpis_respect_date_range_but_debt_is_lifetime(): void
    {
        $provider = $this->makeProvider();
        // Compra vieja con deuda + compra de hoy pagada.
        $old = $this->makePurchase($provider, 500, 100);
        $old->forceFill(['purchased_at' => now()->subDays(30)])->save();
        $this->makePurchase($provider, 200, 200);

        $res = $this->withToken($this->adminToken())
            ->getJson("/api/v1/hub/providers/{$provider->id}?from=".now()->subDay()->toDateString())
            ->assertOk();

        // KPIs del periodo: solo la compra de hoy.
        $this->assertSame(1, $res->json('resumen.compras_count'));
        $this->assertEquals(200, $res->json('resumen.total_comprado'));
        // La deuda es de por vida (la compra vieja sigue debiendo 400).
        $this->assertEquals(400, $res->json('resumen.deuda_actual'));
    }

    public function test_detail_forbidden_for_cajero(): void
    {
        $provider = $this->makeProvider();

        $this->withToken($this->cajero->createToken('hub')->plainTextToken)
            ->getJson("/api/v1/hub/providers/{$provider->id}")
            ->assertStatus(403);
    }

    public function test_account_payment_reduces_debt_fifo(): void
    {
        $provider = $this->makeProvider();
        $this->makePurchase($provider, 100); // más antigua
        $this->makePurchase($provider, 50);

        $this->withToken($this->adminToken())
            ->postJson("/api/v1/hub/providers/{$provider->id}/pagos", ['amount' => 120, 'payment_method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('applied_count', 2);

        $res = $this->withToken($this->adminToken())
            ->getJson("/api/v1/hub/providers/{$provider->id}")
            ->assertOk();
        $this->assertEquals(30, $res->json('resumen.deuda_actual'));
    }

    public function test_compras_and_productos_listing(): void
    {
        $provider = $this->makeProvider();
        $purchase = $this->makePurchase($provider, 100);
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'concept' => 'Costilla',
            'quantity' => 5,
            'unit' => 'kg',
            'unit_price' => 20,
            'subtotal' => 100,
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/v1/hub/providers/{$provider->id}/compras")
            ->assertOk()
            ->assertJsonPath('data.0.folio', $purchase->folio);

        $this->withToken($this->adminToken())
            ->getJson("/api/v1/hub/providers/{$provider->id}/productos")
            ->assertOk()
            ->assertJsonPath('items.0.concept', 'Costilla');
    }
}
