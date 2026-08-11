<?php

namespace Tests\Feature\Ventas;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\CustomerProductPrice;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Capturar un teléfono en una venta debe resolver siempre a un cliente de la
 * sucursal: reutilizando el existente o creando uno sin nombre.
 *
 * Antes el número se guardaba en `sales.contact_phone` sin cliente y quedaba
 * huérfano; ahora vive en el cliente asignado (`AssignCustomerToSale` limpia
 * `contact_phone` en ventas POS).
 */
class CapturePhoneCreatesCustomerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->product = $this->makeProduct(['unit_type' => 'kg', 'price' => 100]);
    }

    public function test_telefono_de_cliente_existente_lo_asocia_a_la_venta(): void
    {
        $customer = $this->makeCustomer('9931234567', 'Juan Perez');
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9931234567'])
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('customer_created', false);

        $this->assertSame($customer->id, $sale->fresh()->customer_id);
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_telefono_nuevo_crea_cliente_sin_nombre_y_lo_asocia(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertOk()
            ->assertJsonPath('customer_created', true)
            ->assertJsonPath('customer.name_pending', true);

        $customer = $sale->fresh()->customer;
        $this->assertNotNull($customer);
        $this->assertSame('+529939999999', $customer->phone);
        $this->assertSame('Cliente 993 999 9999', $customer->name);
    }

    public function test_el_telefono_deja_de_quedar_huerfano_en_la_venta(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertOk();

        // El número vive en el cliente, no suelto en la venta.
        $this->assertNull($sale->fresh()->contact_phone);
        $this->assertSame('+529939999999', $sale->fresh()->customer->phone);
    }

    public function test_no_duplica_cliente_al_capturar_el_mismo_numero_dos_veces(): void
    {
        $first = $this->makeSale();
        $second = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $first->id]), ['phone' => '9939999999'])
            ->assertOk();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $second->id]), ['phone' => '9939999999'])
            ->assertOk();

        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
        $this->assertSame($first->fresh()->customer_id, $second->fresh()->customer_id);
    }

    public function test_devuelve_el_link_de_whatsapp_al_asociar(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('url', fn (?string $url) => is_string($url) && str_contains($url, 'wa.me/529939999999'));
    }

    public function test_rechaza_telefono_invalido(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_venta_de_otra_sucursal_es_rechazada(): void
    {
        $sale = $this->makeSale(['branch_id' => $this->secondBranch->id]);

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertForbidden();

        $this->assertSame(0, Customer::withoutGlobalScopes()->count());
    }

    public function test_recapturar_el_mismo_numero_en_la_misma_venta_es_idempotente(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertOk();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_pide_confirmacion_cuando_el_cliente_tiene_precio_preferencial(): void
    {
        $customer = $this->makeCustomer('9931234567', 'Juan VIP');
        CustomerProductPrice::create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'price' => 80,
        ]);

        $sale = $this->makeSaleWithItem();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9931234567'])
            ->assertOk()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('preview.changes_total', true)
            ->assertJsonPath('preview.current_total', 200)
            ->assertJsonPath('preview.new_total', 160);

        // Sin confirmar, la venta no se tocó.
        $this->assertNull($sale->fresh()->customer_id);
        $this->assertSame('200.00', $sale->fresh()->total);
    }

    public function test_confirmar_aplica_la_asignacion(): void
    {
        $customer = $this->makeCustomer('9931234567', 'Juan VIP');
        CustomerProductPrice::create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'price' => 80,
        ]);

        $sale = $this->makeSaleWithItem();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), [
                'phone' => '9931234567',
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->assertSame($customer->id, $sale->fresh()->customer_id);
        $this->assertSame('160.00', $sale->fresh()->total);
    }

    public function test_no_pide_confirmacion_si_el_total_no_cambia_aunque_haya_pagos(): void
    {
        $this->makeCustomer('9931234567', 'Juan sin preferenciales');
        $sale = $this->makeSaleWithItem();
        $sale->update(['amount_paid' => 50, 'amount_pending' => 150]);

        // Enviar la nota después de cobrar es el flujo normal: no debe estorbar.
        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9931234567'])
            ->assertOk()
            ->assertJsonMissingPath('requires_confirmation')
            ->assertJsonPath('available', true);

        $this->assertNotNull($sale->fresh()->customer_id);
    }

    public function test_pide_confirmacion_si_la_venta_quedaria_cobrada(): void
    {
        $this->makeCustomer('9931234567', 'Juan');
        $sale = $this->makeSaleWithItem();
        $sale->update(['amount_paid' => 200, 'amount_pending' => 0]);

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9931234567'])
            ->assertOk()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('preview.would_complete', true);

        $this->assertSame(SaleStatus::Active, $sale->fresh()->status);
    }

    /**
     * Si el usuario rechaza la asociación, debe poder mandar la nota igual:
     * se guarda el teléfono en la venta como antes, sin tocar al cliente.
     */
    public function test_permite_enviar_sin_asociar_cuando_se_rechaza_la_confirmacion(): void
    {
        $customer = $this->makeCustomer('9931234567', 'Juan VIP');
        CustomerProductPrice::create([
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'price' => 80,
        ]);

        $sale = $this->makeSaleWithItem();

        $this->actingAs($this->cajero)
            ->postJson(route('caja.whatsapp-phone', [$this->tenant->slug, $sale->id]), [
                'phone' => '9931234567',
                'skip_assign' => true,
            ])
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonMissingPath('requires_confirmation');

        $this->assertNull($sale->fresh()->customer_id);
        $this->assertSame('+529931234567', $sale->fresh()->contact_phone);
        $this->assertSame('200.00', $sale->fresh()->total);
    }

    public function test_el_mismo_flujo_funciona_desde_sucursal(): void
    {
        $sale = $this->makeSale();

        $this->actingAs($this->adminSucursal)
            ->postJson(route('sucursal.workbench.whatsapp-phone', [$this->tenant->slug, $sale->id]), ['phone' => '9939999999'])
            ->assertOk()
            ->assertJsonPath('customer_created', true);

        $this->assertNotNull($sale->fresh()->customer_id);
    }

    private function makeCustomer(string $phone, string $name): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'phone' => $phone,
            'status' => 'active',
        ]);
    }

    /**
     * Venta coherente: el total coincide con la suma de sus líneas.
     *
     * Importa que lo sea. `AssignCustomerToSale` recalcula el total desde las
     * líneas, así que una venta con total pero sin líneas quedaría en 0 al
     * asignar cliente — y el preview, que refleja fielmente esa lógica, pediría
     * confirmación por un cambio de total que en realidad es un dato corrupto.
     */
    private function makeSale(array $attrs = []): Sale
    {
        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => 200,
            'amount_paid' => 0,
            'amount_pending' => 200,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ], $attrs));

        $sale->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 2,
            'quantity_unit' => 'kg',
            'unit_type' => 'kg',
            'unit_price' => 100,
            'original_unit_price' => 100,
            'subtotal' => 200,
        ]);

        return $sale->fresh();
    }

    private function makeSaleWithItem(): Sale
    {
        return $this->makeSale();
    }
}
