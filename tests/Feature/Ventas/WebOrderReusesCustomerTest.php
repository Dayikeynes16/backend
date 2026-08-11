<?php

namespace Tests\Feature\Ventas;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * El checkout del menú QR resolvía el cliente con su propio `firstOrCreate`,
 * buscando en E.164 contra una columna que se guardaba sin normalizar. Eso
 * creaba un cliente duplicado cada vez que alguien dado de alta a mano pedía
 * por la web. Ahora pasa por `ResolveCustomerByPhone`, como el resto.
 */
class WebOrderReusesCustomerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $this->branch->update([
            'online_ordering_enabled' => true,
            'pickup_enabled' => true,
            'hours' => null,              // isOpenNow() → true a cualquier hora
            'min_order_amount' => null,
        ]);

        $this->product = $this->makeProduct(['visible_online' => true, 'price' => 100]);
    }

    public function test_pedido_web_reutiliza_el_cliente_dado_de_alta_a_mano(): void
    {
        $existing = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Perez',
            'phone' => '993 123 4567',   // formato humano, como lo captura el mostrador
            'status' => 'active',
        ]);

        $this->postJson($this->orderUrl(), $this->payload([
            'contact_name' => 'Juan P',
            'contact_phone' => '9931234567',
        ]))->assertCreated();

        // Antes de este cambio aquí se creaba un SEGUNDO cliente.
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
        $this->assertSame($existing->id, $this->lastWebOrder()->customer_id);
    }

    public function test_pedido_web_conserva_el_nombre_capturado_en_el_checkout(): void
    {
        $this->postJson($this->orderUrl(), $this->payload([
            'contact_name' => 'Cliente Nuevo',
            'contact_phone' => '9939999999',
        ]))->assertCreated();

        $customer = Customer::where('phone', '+529939999999')->first();

        $this->assertNotNull($customer);
        $this->assertSame('Cliente Nuevo', $customer->name);
        $this->assertFalse($customer->name_pending);
    }

    public function test_no_pisa_el_nombre_del_cliente_existente(): void
    {
        $existing = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Perez',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $this->postJson($this->orderUrl(), $this->payload([
            'contact_name' => 'Juanito',
            'contact_phone' => '9931234567',
        ]))->assertCreated();

        $this->assertSame('Juan Perez', $existing->fresh()->name);
    }

    public function test_dos_pedidos_del_mismo_numero_no_duplican_cliente(): void
    {
        $this->postJson($this->orderUrl(), $this->payload(['contact_phone' => '9939999999']))->assertCreated();
        $this->postJson($this->orderUrl(), $this->payload(['contact_phone' => '9939999999']))->assertCreated();

        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_el_pedido_conserva_su_contact_phone(): void
    {
        $this->postJson($this->orderUrl(), $this->payload(['contact_phone' => '9939999999']))->assertCreated();

        // En ventas `web` el contacto forma parte del registro del pedido.
        $this->assertSame('+529939999999', $this->lastWebOrder()->contact_phone);
    }

    public function test_rechaza_un_telefono_que_no_lo_es(): void
    {
        // Pasa el regex (11 caracteres) pero solo tiene 6 dígitos.
        $this->postJson($this->orderUrl(), $this->payload([
            'contact_phone' => '(55) 12-34',
        ]))->assertStatus(422);

        $this->assertSame(0, Customer::where('branch_id', $this->branch->id)->count());
        $this->assertSame(0, Sale::withoutGlobalScopes()->where('origin', 'web')->count());
    }

    private function lastWebOrder(): Sale
    {
        return Sale::withoutGlobalScopes()->where('origin', 'web')->latest('id')->firstOrFail();
    }

    private function orderUrl(): string
    {
        return "/api/public/{$this->tenant->slug}/branches/{$this->branch->id}/orders";
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
            'delivery_type' => 'pickup',
            'contact_name' => 'Cliente',
            'contact_phone' => '9939999999',
            'payment_method' => 'cash',
        ], $overrides);
    }
}
