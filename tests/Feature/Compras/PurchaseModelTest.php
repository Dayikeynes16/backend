<?php

namespace Tests\Feature\Compras;

use App\Enums\AiDraftStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProviderType;
use App\Enums\PurchaseStatus;
use App\Models\AiPurchaseDraft;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use App\Models\PurchaseAttachment;
use App\Models\PurchaseItem;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Tests F0 — verifican la base del módulo: aislamiento por tenant, soft-delete,
 * relaciones, casts. Aún NO hay controllers, rutas ni servicios — esos vienen
 * en F1+.
 */
class PurchaseModelTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_provider_auto_assigns_tenant_id_via_belongs_to_tenant(): void
    {
        $provider = Provider::create([
            'name' => 'Carnes Don Pedro',
            'type' => ProviderType::MayoristaCarne->value,
        ]);

        $this->assertSame($this->tenant->id, $provider->tenant_id);
        $this->assertSame(ProviderType::MayoristaCarne, $provider->type);
    }

    public function test_provider_scope_isolates_by_tenant(): void
    {
        Provider::create(['name' => 'Mío', 'type' => 'ganadero']);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app()->instance('tenant', $other);
        Provider::create(['name' => 'Ajeno', 'type' => 'ganadero']);

        app()->instance('tenant', $this->tenant);
        $names = Provider::pluck('name')->all();
        $this->assertContains('Mío', $names);
        $this->assertNotContains('Ajeno', $names);
    }

    public function test_provider_soft_deletes(): void
    {
        $p = Provider::create(['name' => 'X', 'type' => 'otro']);
        $p->delete();

        $this->assertSoftDeleted('providers', ['id' => $p->id]);
        $this->assertNull(Provider::find($p->id));
        $this->assertNotNull(Provider::withTrashed()->find($p->id));
    }

    public function test_purchase_has_items_attachments_and_payments(): void
    {
        $provider = Provider::create(['name' => 'P1', 'type' => 'mayorista_carne']);
        $purchase = Purchase::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'folio' => 'CMP-2026-00001',
            'purchased_at' => now(),
            'status' => PurchaseStatus::Received->value,
            'subtotal' => 1000,
            'total' => 1000,
            'amount_pending' => 1000,
            'created_by' => $this->adminEmpresa->id,
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'concept' => 'Pulpa de res',
            'quantity' => 5.5,
            'unit' => 'kg',
            'unit_price' => 181.8181,
            'subtotal' => 1000,
        ]);
        PurchaseAttachment::create([
            'purchase_id' => $purchase->id,
            'tenant_id' => $this->tenant->id,
            'original_name' => 'factura.pdf',
            'path' => 'tenants/'.$this->tenant->id.'/purchases/'.$purchase->id.'/uuid.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
        ProviderPayment::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'purchase_id' => $purchase->id,
            'paid_at' => now(),
            'amount' => 500,
            'payment_method' => PaymentMethod::Cash->value,
            'user_id' => $this->adminEmpresa->id,
        ]);

        $fresh = $purchase->fresh(['items', 'attachments', 'payments']);
        $this->assertCount(1, $fresh->items);
        $this->assertCount(1, $fresh->attachments);
        $this->assertCount(1, $fresh->payments);
        $this->assertSame('Pulpa de res', $fresh->items->first()->concept);
    }

    public function test_purchase_casts_status_to_enum_and_amounts_to_decimal_strings(): void
    {
        $provider = Provider::create(['name' => 'P', 'type' => 'otro']);
        $purchase = Purchase::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'folio' => 'CMP-2026-00002',
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 250.5,
            'total' => 250.5,
            'amount_pending' => 250.5,
        ]);

        $fresh = $purchase->fresh();
        $this->assertSame(PurchaseStatus::Received, $fresh->status);
        $this->assertSame('250.50', (string) $fresh->total);
    }

    public function test_purchase_folio_must_be_unique_per_tenant(): void
    {
        $provider = Provider::create(['name' => 'P', 'type' => 'otro']);
        $base = [
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'folio' => 'CMP-2026-00100',
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 100,
            'total' => 100,
            'amount_pending' => 100,
        ];

        Purchase::create($base);

        $this->expectException(QueryException::class);
        Purchase::create($base);
    }

    public function test_provider_payment_belongs_to_purchase_and_provider(): void
    {
        $provider = Provider::create(['name' => 'P', 'type' => 'otro']);
        $purchase = Purchase::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'folio' => 'CMP-2026-00200',
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
        ]);

        $payment = ProviderPayment::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'purchase_id' => $purchase->id,
            'paid_at' => now(),
            'amount' => 50,
            'payment_method' => 'card',
            'user_id' => $this->adminEmpresa->id,
        ]);

        $fresh = $payment->fresh(['purchase', 'provider']);
        $this->assertSame($purchase->id, $fresh->purchase->id);
        $this->assertSame($provider->id, $fresh->provider->id);
        $this->assertSame(PaymentMethod::Card, $fresh->payment_method);
    }

    public function test_provider_payment_can_be_account_payment_with_null_purchase(): void
    {
        $provider = Provider::create(['name' => 'P', 'type' => 'otro']);

        $payment = ProviderPayment::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'purchase_id' => null,
            'paid_at' => now(),
            'amount' => 500,
            'payment_method' => 'transfer',
            'user_id' => $this->adminEmpresa->id,
        ]);

        $this->assertNull($payment->purchase_id);
        $this->assertSame($this->tenant->id, $payment->tenant_id);
    }

    public function test_purchase_item_preserves_concept_when_product_is_deleted(): void
    {
        $provider = Provider::create(['name' => 'P', 'type' => 'otro']);
        $product = $this->makeProduct(['name' => 'Carbón original']);
        $purchase = Purchase::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'folio' => 'CMP-2026-00300',
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_pending' => 100,
        ]);
        $item = PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'concept' => 'Carbón premium 5kg',
            'quantity' => 1,
            'unit' => 'pieza',
            'unit_price' => 100,
            'subtotal' => 100,
        ]);

        $product->forceDelete();

        $fresh = $item->fresh();
        $this->assertNull($fresh->product_id);
        $this->assertSame('Carbón premium 5kg', $fresh->concept);
    }

    public function test_ai_purchase_draft_scoped_by_tenant(): void
    {
        $draft = AiPurchaseDraft::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminEmpresa->id,
            'status' => 'pending',
            'input_text' => 'compré carne ayer',
        ]);

        $this->assertSame($this->tenant->id, $draft->tenant_id);
        $this->assertSame(AiDraftStatus::Pending, $draft->status);
    }

    public function test_attachment_deletion_removes_file(): void
    {
        Storage::fake(config('expenses.disk', 'local'));

        $provider = Provider::create(['name' => 'P', 'type' => 'otro']);
        $purchase = Purchase::create([
            'branch_id' => $this->branch->id,
            'provider_id' => $provider->id,
            'folio' => 'CMP-2026-00400',
            'purchased_at' => now(),
            'status' => 'received',
            'subtotal' => 0, 'total' => 0, 'amount_pending' => 0,
        ]);
        $path = 'tenants/'.$this->tenant->id.'/purchases/'.$purchase->id.'/test.pdf';
        Storage::disk(config('expenses.disk', 'local'))
            ->put($path, 'fake pdf bytes');

        $att = PurchaseAttachment::create([
            'purchase_id' => $purchase->id,
            'tenant_id' => $this->tenant->id,
            'original_name' => 'f.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 14,
        ]);

        Storage::disk(config('expenses.disk', 'local'))
            ->assertExists($path);

        $att->delete();

        Storage::disk(config('expenses.disk', 'local'))
            ->assertMissing($path);
    }
}
