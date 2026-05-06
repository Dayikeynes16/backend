<?php

namespace Tests\Feature\Caja;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Feature tests del endpoint de WhatsApp en Mesa de Trabajo (Caja).
 *
 * El cajero tiene la misma capacidad que admin-sucursal para enviar la nota:
 *  - cliente con teléfono → directo
 *  - sin cliente o sin teléfono → modal pide teléfono → se guarda en contact_phone
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

    public function test_link_uses_customer_phone(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente',
            'phone' => '5512345678',
            'status' => 'active',
        ]);

        $sale = $this->makeSale(['customer_id' => $customer->id]);

        $this->actingAs($this->cajero)
            ->getJson(route('caja.whatsapp-link', [$this->tenant->slug, $sale->id]))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => is_string($url) && str_contains($url, 'wa.me/525512345678'));
    }

    public function test_link_uses_contact_phone_when_no_customer(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => '+525511112222']);

        $this->actingAs($this->cajero)
            ->getJson(route('caja.whatsapp-link', [$this->tenant->slug, $sale->id]))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => str_contains($url, 'wa.me/525511112222'));
    }

    public function test_link_requests_phone_when_none_available(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => null]);

        $this->actingAs($this->cajero)
            ->getJson(route('caja.whatsapp-link', [$this->tenant->slug, $sale->id]))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'needs_phone');
    }

    public function test_store_phone_saves_normalized_phone_and_returns_link(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => null]);

        $this->actingAs($this->cajero)
            ->postJson(
                route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                ['phone' => '5512345678'],
            )
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => str_contains($url, 'wa.me/525512345678'));

        $this->assertSame('+525512345678', $sale->fresh()->contact_phone);
    }

    public function test_store_phone_rejects_non_ten_digit_input(): void
    {
        $sale = $this->makeSale(['customer_id' => null, 'contact_phone' => null]);

        $this->actingAs($this->cajero)
            ->postJson(
                route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                ['phone' => '12345'],
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertNull($sale->fresh()->contact_phone);
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

        $this->actingAs($this->cajero)
            ->postJson(
                route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]),
                ['phone' => '5512345678'],
            )
            ->assertForbidden();
    }

    public function test_destroy_phone_clears_contact_phone(): void
    {
        $sale = $this->makeSale(['contact_phone' => '+525512345678']);

        $this->actingAs($this->cajero)
            ->deleteJson(route('caja.whatsapp-phone.destroy', [$this->tenant->slug, $sale->id]))
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

        $this->actingAs($this->cajero)
            ->deleteJson(route('caja.whatsapp-phone.destroy', [$this->tenant->slug, $sale->id]))
            ->assertForbidden();

        $this->assertSame('+525512345678', $sale->fresh()->contact_phone);
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
