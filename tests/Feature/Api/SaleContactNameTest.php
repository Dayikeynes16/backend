<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `contact_name` es el nombre que la báscula pone a la venta. Es opcional a
 * propósito: las básculas viejas no lo mandan y deben seguir funcionando
 * exactamente igual.
 */
class SaleContactNameTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Product $product;

    private string $rawKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-tenant', 'status' => 'active']);
        $this->branch = Branch::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sucursal 1',
            'address' => 'A',
            'status' => 'active',
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Res',
            'status' => 'active',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'Costilla',
            'price' => 170,
            'unit_type' => 'kg',
            'sale_mode' => 'weight',
            'status' => 'active',
        ]);

        $this->rawKey = 'csa_test_'.str_repeat('x', 20);
        ApiKey::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'bascula de prueba',
            'key_hash' => hash('sha256', $this->rawKey),
            'last_used_at' => null,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function postSale(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/sales', array_merge([
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ], $extra), ['X-Api-Key' => $this->rawKey]);
    }

    public function test_guarda_el_nombre_cuando_viene(): void
    {
        $this->postSale(['contact_name' => 'Doña Mary'])->assertCreated();

        $this->assertSame('Doña Mary', Sale::withoutGlobalScopes()->latest('id')->first()->contact_name);
    }

    /**
     * La garantía para las básculas viejas: no mandan el campo y todo sigue igual.
     * Este test no puede eliminarse.
     */
    public function test_una_venta_sin_el_campo_se_crea_igual_que_antes(): void
    {
        $this->postSale()->assertCreated();

        $sale = Sale::withoutGlobalScopes()->latest('id')->first();

        $this->assertNull($sale->contact_name);
        $this->assertSame('340.00', $sale->total);
    }

    public function test_ignora_un_nombre_vacio(): void
    {
        $this->postSale(['contact_name' => '   '])->assertCreated();

        $this->assertNull(Sale::withoutGlobalScopes()->latest('id')->first()->contact_name);
    }

    public function test_rechaza_un_nombre_demasiado_largo(): void
    {
        $this->postSale(['contact_name' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_name');
    }

    public function test_no_crea_ni_toca_clientes(): void
    {
        $this->postSale(['contact_name' => 'Doña Mary'])->assertCreated();

        $this->assertSame(0, Customer::withoutGlobalScopes()->count());
        $this->assertNull(Sale::withoutGlobalScopes()->latest('id')->first()->customer_id);
    }
}
