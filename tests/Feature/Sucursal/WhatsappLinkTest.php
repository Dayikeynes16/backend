<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Feature tests del endpoint de WhatsApp en Mesa de Trabajo (Sucursal).
 *
 * Cubre la nueva lógica:
 *  - Si hay cliente con teléfono → usa el teléfono del cliente.
 *  - Si no, usa `contact_phone` capturado en la venta.
 *  - Si no hay ninguno → reason `needs_phone` para que el frontend abra el modal.
 *  - El POST de teléfono guarda en `contact_phone` (E.164) y devuelve link wa.me.
 */
class WhatsappLinkTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_link_uses_customer_phone_when_sale_has_customer_with_phone(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente Uno',
            'phone' => '5512345678',
            'status' => 'active',
        ]);

        $sale = $this->makeSale(['customer_id' => $customer->id, 'contact_phone' => null]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.whatsapp-link', [$this->tenant->slug, $sale->id]));

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => is_string($url) && str_contains($url, 'wa.me/525512345678'));
    }

    public function test_link_falls_back_to_contact_phone_when_no_customer(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => '+529998887766']);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.whatsapp-link', [$this->tenant->slug, $sale->id]));

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => is_string($url) && str_contains($url, 'wa.me/529998887766'));
    }

    public function test_customer_phone_is_preferred_over_contact_phone(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente',
            'phone' => '5511112222',
            'status' => 'active',
        ]);

        $sale = $this->makeSale([
            'customer_id' => $customer->id,
            'contact_phone' => '+529999999999',
        ]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.whatsapp-link', [$this->tenant->slug, $sale->id]));

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => str_contains($url, 'wa.me/525511112222'));
    }

    public function test_link_returns_needs_phone_when_no_customer_and_no_contact_phone(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => null]);

        $response = $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.whatsapp-link', [$this->tenant->slug, $sale->id]));

        $response->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'needs_phone')
            ->assertJsonPath('url', null);
    }

    public function test_link_forbidden_when_sale_belongs_to_other_branch(): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'folio' => 'O-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);

        $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.whatsapp-link', [$this->tenant->slug, $sale->id]))
            ->assertForbidden();
    }

    public function test_store_phone_saves_normalized_phone_and_returns_link(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => null]);

        $response = $this->actingAs($this->adminSucursal)
            ->postJson(
                route('sucursal.workbench.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                ['phone' => '5512345678'],
            );

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => is_string($url) && str_contains($url, 'wa.me/525512345678'));

        $this->assertSame('+525512345678', $sale->fresh()->contact_phone);
    }

    public function test_store_phone_rejects_invalid_format(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => null]);

        // 9 dígitos
        $this->actingAs($this->adminSucursal)
            ->postJson(
                route('sucursal.workbench.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                ['phone' => '551234567'],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        // No numérico
        $this->actingAs($this->adminSucursal)
            ->postJson(
                route('sucursal.workbench.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                ['phone' => '55-1234-5678'],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertNull($sale->fresh()->contact_phone);
    }

    public function test_store_phone_requires_phone_field(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => null]);

        $this->actingAs($this->adminSucursal)
            ->postJson(
                route('sucursal.workbench.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                [],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_store_phone_forbidden_when_sale_belongs_to_other_branch(): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'folio' => 'O-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);

        $this->actingAs($this->adminSucursal)
            ->postJson(
                route('sucursal.workbench.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                ['phone' => '5512345678'],
            )
            ->assertForbidden();

        $this->assertNull($sale->fresh()->contact_phone);
    }

    public function test_destroy_phone_clears_contact_phone(): void
    {
        $sale = $this->makeSale(['contact_phone' => '+525512345678']);

        $this->actingAs($this->adminSucursal)
            ->deleteJson(route('sucursal.workbench.whatsapp-phone.destroy', [$this->tenant->slug, $sale->id]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($sale->fresh()->contact_phone);
    }

    public function test_destroy_phone_forbidden_when_sale_belongs_to_other_branch(): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'folio' => 'O-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
            'contact_phone' => '+525512345678',
        ]);

        $this->actingAs($this->adminSucursal)
            ->deleteJson(route('sucursal.workbench.whatsapp-phone.destroy', [$this->tenant->slug, $sale->id]))
            ->assertForbidden();

        $this->assertSame('+525512345678', $sale->fresh()->contact_phone);
    }

    public function test_subsequent_link_request_uses_saved_phone_without_modal(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => null]);

        // Primer envío: capturamos el teléfono.
        $this->actingAs($this->adminSucursal)
            ->postJson(
                route('sucursal.workbench.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                ['phone' => '5512345678'],
            )
            ->assertOk();

        // Segundo intento: la venta ya tiene contact_phone, debe devolver URL directo
        // sin pedir nuevamente el teléfono (sin reason needs_phone).
        $this->actingAs($this->adminSucursal)
            ->getJson(route('sucursal.workbench.whatsapp-link', [$this->tenant->slug, $sale->id]))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => str_contains($url, 'wa.me/525512345678'));
    }

    private function makeSale(array $attrs = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 250,
            'amount_paid' => 0,
            'amount_pending' => 250,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ], $attrs));
    }
}
