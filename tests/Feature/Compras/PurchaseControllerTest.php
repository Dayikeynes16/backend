<?php

namespace Tests\Feature\Compras;

use App\Models\Branch;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->provider = Provider::create(['name' => 'Carnes Test', 'type' => 'mayorista_carne']);
    }

    // ─── Empresa ─────────────────────────────────────────────────────────

    public function test_admin_empresa_lists_purchases(): void
    {
        $this->makePurchase();

        $this->actingAs($this->adminEmpresa);
        $this->get(route('empresa.compras.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Empresa/Compras/Index')
                ->has('purchases', 1)
                ->has('providers')
                ->has('branches')
                ->has('kpis')
            );
    }

    public function test_admin_empresa_creates_purchase_with_items(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->post(route('empresa.compras.store', $this->tenant->slug), [
            'provider_id' => $this->provider->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'invoice_number' => 'F-001',
            'items' => [
                ['concept' => 'Pulpa de res', 'quantity' => 10, 'unit' => 'kg', 'unit_price' => 180],
                ['concept' => 'Bistec', 'quantity' => 5, 'unit' => 'kg', 'unit_price' => 200],
            ],
        ])->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->assertSame(2, $purchase->items()->count());
        $this->assertEquals(2800.00, (float) $purchase->total);
        $this->assertEquals(2800.00, (float) $purchase->amount_pending);
        $this->assertStringStartsWith('CMP-'.now()->format('Y').'-', $purchase->folio);
        $this->assertSame($this->adminEmpresa->id, $purchase->created_by);
    }

    public function test_folio_increments_per_tenant(): void
    {
        $this->actingAs($this->adminEmpresa);
        $payload = [
            'provider_id' => $this->provider->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100]],
        ];
        $this->post(route('empresa.compras.store', $this->tenant->slug), $payload);
        $this->post(route('empresa.compras.store', $this->tenant->slug), $payload);

        $folios = Purchase::orderBy('id')->pluck('folio')->all();
        $year = now()->format('Y');
        $this->assertSame(['CMP-'.$year.'-00001', 'CMP-'.$year.'-00002'], $folios);
    }

    public function test_admin_empresa_updates_purchase_replacing_items(): void
    {
        $purchase = $this->makePurchase();
        $purchase->items()->createMany([
            ['concept' => 'Original', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100, 'subtotal' => 100],
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->put(route('empresa.compras.update', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'provider_id' => $this->provider->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [
                ['concept' => 'Nuevo A', 'quantity' => 2, 'unit' => 'kg', 'unit_price' => 50],
                ['concept' => 'Nuevo B', 'quantity' => 3, 'unit' => 'pieza', 'unit_price' => 10],
            ],
        ])->assertRedirect();

        $fresh = $purchase->fresh(['items']);
        $this->assertSame(2, $fresh->items->count());
        $this->assertSame(['Nuevo A', 'Nuevo B'], $fresh->items->pluck('concept')->all());
        $this->assertEquals(130.00, (float) $fresh->total);
    }

    public function test_admin_empresa_cancels_purchase(): void
    {
        $purchase = $this->makePurchase();

        $this->actingAs($this->adminEmpresa);
        $this->patch(route('empresa.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'reason' => 'Pedido equivocado',
        ])->assertRedirect();

        $fresh = $purchase->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertSame('Pedido equivocado', $fresh->cancel_reason);
        $this->assertSame($this->adminEmpresa->id, $fresh->cancelled_by);
    }

    public function test_cannot_update_cancelled_purchase(): void
    {
        $purchase = $this->makePurchase();
        $purchase->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->actingAs($this->adminEmpresa);
        $this->put(route('empresa.compras.update', ['tenant' => $this->tenant->slug, 'compra' => $purchase->id]), [
            'provider_id' => $this->provider->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100]],
        ])->assertStatus(422);
    }

    public function test_store_validates_provider_belongs_to_tenant(): void
    {
        $other = Tenant::create(['name' => 'X', 'slug' => 'x', 'status' => 'active']);
        app()->instance('tenant', $other);
        $foreignProvider = Provider::create(['name' => 'Ajeno', 'type' => 'otro']);
        app()->instance('tenant', $this->tenant);

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.compras.index', $this->tenant->slug))
            ->post(route('empresa.compras.store', $this->tenant->slug), [
                'provider_id' => $foreignProvider->id,
                'branch_id' => $this->branch->id,
                'purchased_at' => now()->toDateString(),
                'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100]],
            ])->assertSessionHasErrors('provider_id');
    }

    public function test_attachments_are_stored_and_listed(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminEmpresa);

        $this->post(route('empresa.compras.store', $this->tenant->slug), [
            'provider_id' => $this->provider->id,
            'branch_id' => $this->branch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100]],
            'attachments' => [UploadedFile::fake()->image('factura.jpg')],
        ])->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->assertSame(1, $purchase->attachments()->count());
        Storage::disk('local')->assertExists($purchase->attachments()->first()->path);
    }

    public function test_attachment_destroy_removes_file(): void
    {
        Storage::fake('local');
        $purchase = $this->makePurchase();
        $att = $purchase->attachments()->create([
            'tenant_id' => $purchase->tenant_id,
            'original_name' => 'f.pdf',
            'path' => "tenants/{$purchase->tenant_id}/purchases/{$purchase->id}/x.pdf",
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($att->path, 'fake');

        $this->actingAs($this->adminEmpresa);
        $this->delete(route('empresa.compras.adjuntos.destroy', [
            'tenant' => $this->tenant->slug, 'compra' => $purchase->id, 'attachment' => $att->id,
        ]))->assertRedirect();

        Storage::disk('local')->assertMissing($att->path);
        $this->assertSame(0, $purchase->attachments()->count());
    }

    public function test_admin_sucursal_cannot_access_empresa_routes(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->get(route('empresa.compras.index', $this->tenant->slug))->assertForbidden();
        $this->post(route('empresa.compras.store', $this->tenant->slug), [])->assertForbidden();
    }

    public function test_cajero_cannot_access(): void
    {
        $this->actingAs($this->cajero);
        $this->get(route('empresa.compras.index', $this->tenant->slug))->assertForbidden();
        $this->get(route('sucursal.compras.index', $this->tenant->slug))->assertForbidden();
    }

    // ─── Sucursal ────────────────────────────────────────────────────────

    public function test_admin_sucursal_creates_purchase_forced_to_own_branch(): void
    {
        $this->actingAs($this->adminSucursal);
        // Aunque mande otra sucursal en el payload, debe forzarse a la suya.
        $this->post(route('sucursal.compras.store', $this->tenant->slug), [
            'provider_id' => $this->provider->id,
            'branch_id' => $this->secondBranch->id,
            'purchased_at' => now()->toDateString(),
            'items' => [['concept' => 'X', 'quantity' => 1, 'unit' => 'kg', 'unit_price' => 100]],
        ])->assertRedirect();

        $purchase = Purchase::firstOrFail();
        $this->assertSame($this->branch->id, $purchase->branch_id);
    }

    public function test_admin_sucursal_index_only_shows_own_branch(): void
    {
        $this->makePurchase(['branch_id' => $this->branch->id]);
        $this->makePurchase(['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->adminSucursal);
        $this->get(route('sucursal.compras.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('purchases', 1));
    }

    public function test_admin_sucursal_cannot_modify_purchase_from_other_branch(): void
    {
        $other = $this->makePurchase(['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->adminSucursal);
        $this->patch(route('sucursal.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $other->id]), [
            'reason' => 'test',
        ])->assertStatus(403);
    }

    public function test_cross_tenant_blocked(): void
    {
        $other = Tenant::create(['name' => 'Y', 'slug' => 'y', 'status' => 'active']);
        app()->instance('tenant', $other);
        $b = Branch::create(['tenant_id' => $other->id, 'name' => 'B', 'address' => 'X', 'status' => 'active']);
        $p = Provider::create(['name' => 'P', 'type' => 'otro']);
        $foreignPurchase = Purchase::create([
            'tenant_id' => $other->id, 'branch_id' => $b->id, 'provider_id' => $p->id,
            'folio' => 'OTHER-1', 'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
        ]);
        app()->instance('tenant', $this->tenant);

        $this->actingAs($this->adminEmpresa);
        $this->patch(route('empresa.compras.cancel', ['tenant' => $this->tenant->slug, 'compra' => $foreignPurchase->id]), [
            'reason' => 'hack',
        ])->assertStatus(404);
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    private function makePurchase(array $overrides = []): Purchase
    {
        return Purchase::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => $this->provider->id,
            'folio' => 'CMP-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
            'created_by' => $this->adminEmpresa->id,
        ], $overrides));
    }
}
