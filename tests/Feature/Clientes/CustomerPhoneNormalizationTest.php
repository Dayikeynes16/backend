<?php

namespace Tests\Feature\Clientes;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * `customers.phone` guarda siempre E.164. La normalización vive en el mutator
 * del modelo para cubrir a todos los canales que dan de alta clientes (CRUD
 * web, hub, asistente IA, pedido web y captura desde la venta) sin que ninguno
 * tenga que acordarse.
 */
class CustomerPhoneNormalizationTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_el_telefono_se_guarda_normalizado_sin_importar_el_formato(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '993 123 4567',
            'status' => 'active',
        ]);

        $this->assertSame('+529931234567', $customer->fresh()->phone);
    }

    public function test_el_telefono_normalizado_permite_encontrar_al_cliente(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '(993) 123-4567',
            'status' => 'active',
        ]);

        $found = Customer::where('branch_id', $this->branch->id)
            ->where('phone', '+529931234567')
            ->first();

        $this->assertNotNull($found);
    }

    public function test_el_update_tambien_normaliza(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $customer->update(['phone' => '993-999-9999']);

        $this->assertSame('+529939999999', $customer->fresh()->phone);
    }

    public function test_telefono_nulo_sigue_siendo_nulo(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Sin telefono',
            'phone' => null,
            'status' => 'active',
        ]);

        $this->assertNull($customer->fresh()->phone);
    }

    public function test_name_pending_default_false(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $this->assertFalse($customer->fresh()->name_pending);
    }

    public function test_name_pending_es_booleano_y_asignable(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente 993 123 4567',
            'name_pending' => true,
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $this->assertTrue($customer->fresh()->name_pending);
    }

    public function test_no_se_puede_dar_de_alta_el_mismo_numero_con_otro_formato(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.clientes.store', $this->tenant->slug), [
                'name' => 'Juan otra vez',
                'phone' => '993 123 4567',
            ])
            ->assertSessionHasErrors('phone');

        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_editar_a_un_numero_que_ya_es_de_otro_cliente_es_rechazado(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $otro = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Pedro',
            'phone' => '9998887766',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminSucursal)
            ->put(route('sucursal.clientes.update', [$this->tenant->slug, $otro->id]), [
                'name' => 'Pedro',
                'phone' => '(993) 123 4567',
            ])
            ->assertSessionHasErrors('phone');

        $this->assertSame('+529998887766', $otro->fresh()->phone);
    }

    /**
     * Poner nombre a un cliente creado automáticamente desde una venta lo deja
     * de marcar como pendiente — es el flujo del chip "Poner nombre".
     */
    public function test_ponerle_nombre_apaga_name_pending(): void
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Cliente 993 123 4567',
            'name_pending' => true,
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminSucursal)
            ->put(route('sucursal.clientes.update', [$this->tenant->slug, $customer->id]), [
                'name' => 'Juan Perez',
                'phone' => $customer->phone,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Juan Perez', $customer->fresh()->name);
        $this->assertFalse($customer->fresh()->name_pending);
    }

    public function test_un_telefono_ilegible_es_rechazado_al_dar_de_alta(): void
    {
        $this->actingAs($this->adminSucursal)
            ->post(route('sucursal.clientes.store', $this->tenant->slug), [
                'name' => 'Cliente raro',
                'phone' => '---',
            ])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Customer::where('branch_id', $this->branch->id)->count());
    }
}
