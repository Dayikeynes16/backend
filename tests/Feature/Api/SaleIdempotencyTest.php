<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Idempotencia de la API de básculas por `client_reference`.
 *
 * El hub reintenta cualquier fallo de red o error 5xx. Sin deduplicar, una venta
 * cuya respuesta se pierde por el camino —timeout, wifi que cae— se crea dos veces
 * en el backend: el mismo dinero contado doble, y nadie se entera hasta cuadrar el
 * turno.
 */
class SaleIdempotencyTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private Product $product;

    private string $rawKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Carnes',
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

    public function test_the_same_client_reference_does_not_create_a_second_sale(): void
    {
        $first = $this->postSale(['client_reference' => 'ref-abc']);
        $first->assertCreated();

        // El reintento del hub: la venta llegó, pero la respuesta se perdió.
        $second = $this->postSale(['client_reference' => 'ref-abc']);

        $this->assertSame(1, Sale::withoutGlobalScopes()->count());
        // Devuelve la venta que ya existía, no un error: para el hub el reintento
        // tiene que ser indistinguible de un envío que salió bien a la primera.
        $this->assertNotNull($first->json('id'));
        $this->assertSame($first->json('id'), $second->json('id'));
    }

    public function test_it_stores_the_client_reference(): void
    {
        $this->postSale(['client_reference' => 'ref-xyz'])->assertCreated();

        $this->assertSame('ref-xyz', Sale::withoutGlobalScopes()->first()->client_reference);
    }

    public function test_a_sale_without_client_reference_still_works(): void
    {
        // Las básculas viejas no lo mandan: tienen que seguir vendiendo igual.
        $this->postSale()->assertCreated();
        $this->postSale()->assertCreated();

        $this->assertSame(2, Sale::withoutGlobalScopes()->count());
        $this->assertNull(Sale::withoutGlobalScopes()->first()->client_reference);
    }

    public function test_the_same_reference_in_another_branch_is_a_different_sale(): void
    {
        // La llave la genera cada equipo; dos sucursales distintas no comparten
        // espacio de nombres y no deben pisarse.
        $this->postSale(['client_reference' => 'ref-abc'])->assertCreated();

        $otherBranch = $this->branch->replicate(['id']);
        $otherBranch->name = 'Sucursal Norte';
        $otherBranch->save();

        $otherKey = 'csa_test_'.str_repeat('y', 20);
        ApiKey::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'name' => 'bascula norte',
            'key_hash' => hash('sha256', $otherKey),
            'last_used_at' => null,
        ]);

        $otherProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $otherBranch->id,
            'category_id' => $this->product->category_id,
            'name' => 'Costilla norte',
            'price' => 170,
            'unit_type' => 'kg',
            'sale_mode' => 'weight',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'client_reference' => 'ref-abc',
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]],
        ], ['X-Api-Key' => $otherKey])->assertCreated();

        $this->assertSame(2, Sale::withoutGlobalScopes()->count());
    }
}
