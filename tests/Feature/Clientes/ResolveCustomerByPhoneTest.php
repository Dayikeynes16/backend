<?php

namespace Tests\Feature\Clientes;

use App\Models\Customer;
use App\Services\Customers\ResolveCustomerByPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Resolver un teléfono a un cliente de la sucursal: reutilizar el existente o
 * crearlo sin nombre. Es el único punto del sistema que toma esa decisión.
 */
class ResolveCustomerByPhoneTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private ResolveCustomerByPhone $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        $this->service = app(ResolveCustomerByPhone::class);
    }

    public function test_devuelve_el_cliente_existente_sin_crear_otro(): void
    {
        $existing = $this->makeCustomer('9931234567', 'Juan Perez');

        $result = $this->service->execute('9931234567', $this->branch->id, $this->tenant->id);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($existing->id, $result->customer->id);
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    public function test_encuentra_al_cliente_aunque_el_formato_sea_distinto(): void
    {
        $existing = $this->makeCustomer('993 123 4567', 'Juan Perez');

        $result = $this->service->execute('+52 993 123 4567', $this->branch->id, $this->tenant->id);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($existing->id, $result->customer->id);
    }

    public function test_encuentra_al_cliente_con_el_formato_movil_legacy(): void
    {
        $existing = $this->makeCustomer('9931234567', 'Juan Perez');

        $result = $this->service->execute('+52 1 993 123 4567', $this->branch->id, $this->tenant->id);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($existing->id, $result->customer->id);
    }

    public function test_crea_cliente_sin_nombre_cuando_el_telefono_es_nuevo(): void
    {
        $result = $this->service->execute('9939999999', $this->branch->id, $this->tenant->id);

        $this->assertTrue($result->wasCreated);
        $this->assertTrue($result->customer->name_pending);
        $this->assertSame('Cliente 993 999 9999', $result->customer->name);
        $this->assertSame('+529939999999', $result->customer->phone);
        $this->assertSame('active', $result->customer->status);
        $this->assertSame($this->branch->id, $result->customer->branch_id);
        $this->assertSame($this->tenant->id, $result->customer->tenant_id);
    }

    public function test_no_cruza_sucursales(): void
    {
        Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'name' => 'Juan en otra sucursal',
            'phone' => '9931234567',
            'status' => 'active',
        ]);

        $result = $this->service->execute('9931234567', $this->branch->id, $this->tenant->id);

        // Mismo número, otra sucursal: se crea uno nuevo. La cartera es por sucursal.
        $this->assertTrue($result->wasCreated);
        $this->assertSame($this->branch->id, $result->customer->branch_id);
        $this->assertSame(2, Customer::withoutGlobalScopes()->where('phone', '+529931234567')->count());
    }

    public function test_reutiliza_cliente_inactivo_en_vez_de_duplicarlo(): void
    {
        $inactive = Customer::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Juan Inactivo',
            'phone' => '9931234567',
            'status' => 'inactive',
        ]);

        $result = $this->service->execute('9931234567', $this->branch->id, $this->tenant->id);

        $this->assertFalse($result->wasCreated);
        $this->assertSame($inactive->id, $result->customer->id);
        // No se reactiva solo: eso es decisión del usuario.
        $this->assertSame('inactive', $result->customer->status);
    }

    public function test_no_pisa_el_nombre_del_cliente_existente(): void
    {
        $existing = $this->makeCustomer('9931234567', 'Juan Perez');

        $this->service->execute('9931234567', $this->branch->id, $this->tenant->id);

        $this->assertSame('Juan Perez', $existing->fresh()->name);
        $this->assertFalse($existing->fresh()->name_pending);
    }

    public function test_llamarlo_dos_veces_no_duplica(): void
    {
        $first = $this->service->execute('9939999999', $this->branch->id, $this->tenant->id);
        $second = $this->service->execute('993 999 9999', $this->branch->id, $this->tenant->id);

        $this->assertTrue($first->wasCreated);
        $this->assertFalse($second->wasCreated);
        $this->assertSame($first->customer->id, $second->customer->id);
        $this->assertSame(1, Customer::where('branch_id', $this->branch->id)->count());
    }

    /**
     * El comando `sales:link-orphan-phones` corre en CLI, sin tenant resuelto
     * en el contenedor. El servicio debe funcionar igual con el tenant_id
     * explícito que recibe.
     */
    public function test_funciona_sin_tenant_resuelto_en_el_contenedor(): void
    {
        $existing = $this->makeCustomer('9931234567', 'Juan Perez');

        app()->forgetInstance('tenant');

        $found = $this->service->execute('9931234567', $this->branch->id, $this->tenant->id);
        $created = $this->service->execute('9939999999', $this->branch->id, $this->tenant->id);

        $this->assertSame($existing->id, $found->customer->id);
        $this->assertTrue($created->wasCreated);
        $this->assertSame($this->tenant->id, $created->customer->tenant_id);
    }

    public function test_rechaza_telefono_ilegible(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->execute('---', $this->branch->id, $this->tenant->id);
    }

    public function test_no_crea_nada_si_el_telefono_es_ilegible(): void
    {
        try {
            $this->service->execute('---', $this->branch->id, $this->tenant->id);
        } catch (\InvalidArgumentException $e) {
            // esperado
        }

        $this->assertSame(0, Customer::where('branch_id', $this->branch->id)->count());
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
}
