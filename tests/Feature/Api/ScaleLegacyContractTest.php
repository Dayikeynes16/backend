<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato congelado de la Scale API con las básculas que hablan **directo con
 * la nube**: las Windows (repo `bascula`) y las Android sin hub.
 *
 * Esas básculas no se actualizan cuando se despliega el backend, y algunas ni
 * siquiera pueden emparejarse con un hub. Si esta superficie cambia de forma,
 * dejan de vender y nadie se entera hasta que una sucursal llama por teléfono.
 *
 * Este test existe para que ese cambio **falle aquí** en vez de en el mostrador.
 * Consume exactamente lo que consume `bascula/src/services/api.js`:
 *
 *   GET  /api/v1/branches/me   → `validateConnection` y `fetchBranch`
 *   GET  /api/v1/products      → catálogo (`DashboardView`, store de venta)
 *   POST /api/v1/sales         → cobro (`stores/sale.js`)
 *
 * Si un cambio legítimo tiene que tocar esta forma, primero hay que decidir qué
 * pasa con las básculas que ya están instaladas. Actualizar el test sin esa
 * decisión es exactamente lo que este archivo intenta impedir.
 */
class ScaleLegacyContractTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Product $product;

    private string $rawKey;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-tenant', 'status' => 'active']);

        $this->branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal 1',
            'address' => 'Calle 1',
            'status' => 'active',
        ]);

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Res',
            'status' => 'active',
        ]);

        $this->product = Product::create([
            'tenant_id' => $tenant->id,
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
            'tenant_id' => $tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'bascula legacy',
            'key_hash' => hash('sha256', $this->rawKey),
            'last_used_at' => null,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function postSale(array $payload = []): array
    {
        return $this->postJson('/api/v1/sales', array_merge([
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ], $payload), ['X-Api-Key' => $this->rawKey])
            ->assertCreated()
            // La creación de venta NO envuelve en `data` (a diferencia de los
            // GET): por eso la báscula hace `res.data.data || res.data`.
            ->json();
    }

    public function test_branches_me_conserva_su_forma_exacta(): void
    {
        $data = $this->getJson('/api/v1/branches/me', ['X-Api-Key' => $this->rawKey])
            ->assertOk()
            ->json('data');

        $this->assertSame(
            ['id', 'name', 'address', 'phone', 'schedule', 'status'],
            array_keys($data),
            'Cambió la forma de branches/me. Es lo primero que pide una báscula al arrancar: '
            .'si un campo desaparece, deja de conectar. Decide qué pasa con las básculas ya instaladas antes de tocar esto.'
        );
    }

    public function test_products_conserva_los_campos_que_la_bascula_lee(): void
    {
        $data = $this->getJson('/api/v1/products', ['X-Api-Key' => $this->rawKey])
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data, 'El catálogo llegó vacío: la báscula no podría vender nada.');

        // La báscula lee estos campos por nombre; los demás puede ignorarlos.
        foreach (['id', 'name', 'price', 'unit_type', 'sale_mode', 'image_url', 'presentations'] as $campo) {
            $this->assertArrayHasKey($campo, $data[0], "Falta `{$campo}` en el catálogo que consume la báscula.");
        }

        // El precio se usa en aritmética directa en el renderer: si viaja como
        // texto, el total sale mal sin que nada falle de forma visible. (JSON no
        // distingue 170.0 de 170, así que lo que se afirma es "número, no texto".)
        $this->assertIsNotString($data[0]['price'], '`price` tiene que viajar como número JSON, no como texto.');
        $this->assertIsNumeric($data[0]['price']);
        $this->assertEqualsWithDelta(170.0, $data[0]['price'], 0.001);
        $this->assertIsArray($data[0]['presentations']);
    }

    public function test_una_venta_devuelve_folio_y_un_total_numerico(): void
    {
        $sale = $this->postSale();

        // `DashboardView.vue` hace `result.total.toFixed(2)` con lo que devuelve
        // este endpoint: si `total` llega como texto, la báscula revienta al
        // cobrar — con la venta ya registrada.
        $this->assertIsNotString($sale['total'], '`total` tiene que viajar como número: la báscula le aplica toFixed(2).');
        $this->assertIsNumeric($sale['total']);
        $this->assertEqualsWithDelta(340.0, $sale['total'], 0.001);

        $this->assertIsString($sale['folio']);
        $this->assertNotSame('', $sale['folio'], 'Sin folio, la báscula muestra "Venta undefined registrada".');
    }

    /**
     * La garantía que más importa mientras se trabaja en cobrar sin internet:
     * el hub va a exigir turno abierto para registrar un pago, y sería fácil
     * extender esa regla a las ventas. Estas básculas **no tienen turno** —
     * no pueden abrirlo, ni saben que existe.
     */
    public function test_una_bascula_vende_sin_turno_abierto(): void
    {
        $this->assertDatabaseCount('cash_register_shifts', 0);

        $sale = $this->postSale();

        $this->assertSame('active', $sale['status']);
    }

    /**
     * Las básculas viejas no mandan `client_reference`. La idempotencia se
     * añadió para el hub y no puede volverse obligatoria aquí.
     */
    public function test_una_venta_sin_client_reference_se_crea_igual(): void
    {
        $sale = $this->postSale();

        $this->assertIsInt($sale['id']);
    }
}
