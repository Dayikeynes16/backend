<?php

namespace Tests\Feature\Console;

use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * `customers:normalize-phones` deja la cartera en E.164 y fusiona los clientes
 * que solo diferían en el formato del teléfono. Es prerrequisito del mutator
 * de `Customer::phone`.
 */
class NormalizeCustomerPhonesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_dry_run_no_modifica_nada(): void
    {
        $id = $this->rawCustomer('993 123 4567', 'Juan');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'true'])
            ->assertSuccessful();

        $this->assertSame('993 123 4567', DB::table('customers')->where('id', $id)->value('phone'));
    }

    public function test_normaliza_telefonos_al_aplicar(): void
    {
        $id = $this->rawCustomer('993 123 4567', 'Juan');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertSame('+529931234567', DB::table('customers')->where('id', $id)->value('phone'));
    }

    public function test_fusiona_duplicados_que_solo_diferian_en_formato(): void
    {
        $keep = $this->rawCustomer('993 123 4567', 'Juan Perez');
        $dupe = $this->rawCustomer('+529931234567', 'Juan P.');

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $dupe,
            'folio' => 'V-'.uniqid(),
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
        ]);

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        // Sobrevive el más antiguo y hereda las ventas del fusionado.
        $this->assertNull(Customer::find($dupe));
        $this->assertSame($keep, $sale->fresh()->customer_id);
        $this->assertSame('+529931234567', Customer::find($keep)->phone);
    }

    public function test_el_movil_legacy_521_se_fusiona_con_el_de_diez_digitos(): void
    {
        $keep = $this->rawCustomer('9931234567', 'Juan');
        $dupe = $this->rawCustomer('+5219931234567', 'Juan movil');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertNull(Customer::find($dupe));
        $this->assertSame('+529931234567', Customer::find($keep)->phone);
    }

    public function test_no_fusiona_el_mismo_numero_de_sucursales_distintas(): void
    {
        $first = $this->rawCustomer('993 123 4567', 'Juan sucursal 1', $this->branch->id);
        $second = $this->rawCustomer('9931234567', 'Juan sucursal 2', $this->secondBranch->id);

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        // La cartera es por sucursal: los dos sobreviven, ambos normalizados.
        $this->assertNotNull(Customer::find($first));
        $this->assertNotNull(Customer::find($second));
        $this->assertSame('+529931234567', Customer::find($first)->phone);
        $this->assertSame('+529931234567', Customer::find($second)->phone);
    }

    /**
     * Caso real de producción: dos clientes distintos con basura coincidente
     * ('8556') en el campo teléfono. Fusionarlos mezclaría el historial de
     * ventas de dos personas diferentes, y no se deshace.
     */
    public function test_no_fusiona_clientes_cuya_basura_coincide(): void
    {
        $uno = $this->rawCustomer('8556', 'Cristel marcelo');
        $otro = $this->rawCustomer('85 56', 'Otra persona');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertNotNull(Customer::find($uno));
        $this->assertNotNull(Customer::find($otro));
        $this->assertSame('8556', DB::table('customers')->where('id', $uno)->value('phone'));
        $this->assertSame('85 56', DB::table('customers')->where('id', $otro)->value('phone'));
    }

    public function test_deja_intacto_lo_que_no_parece_telefono(): void
    {
        $basura = $this->rawCustomer('344', 'Tres digitos');
        $cero = $this->rawCustomer('0', 'Un cero');
        $bueno = $this->rawCustomer('9931234567', 'Cliente real');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->expectsOutputToContain('no parece un teléfono')
            ->assertSuccessful();

        $this->assertSame('344', DB::table('customers')->where('id', $basura)->value('phone'));
        $this->assertSame('0', DB::table('customers')->where('id', $cero)->value('phone'));
        $this->assertSame('+529931234567', DB::table('customers')->where('id', $bueno)->value('phone'));
    }

    public function test_respeta_un_numero_internacional_valido(): void
    {
        $id = $this->rawCustomer('+46634532343', 'Cliente extranjero');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertSame('+46634532343', DB::table('customers')->where('id', $id)->value('phone'));
    }

    public function test_no_toca_clientes_sin_telefono(): void
    {
        $id = DB::table('customers')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Sin telefono',
            'phone' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertNull(DB::table('customers')->where('id', $id)->value('phone'));
    }

    public function test_deja_intacto_el_telefono_ilegible(): void
    {
        $id = $this->rawCustomer('---', 'Telefono basura');

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertSame('---', DB::table('customers')->where('id', $id)->value('phone'));
    }

    public function test_reasigna_tambien_los_precios_preferenciales(): void
    {
        $keep = $this->rawCustomer('993 123 4567', 'Juan');
        $dupe = $this->rawCustomer('+529931234567', 'Juan P.');
        $product = $this->makeProduct();

        DB::table('customer_product_prices')->insert([
            'customer_id' => $dupe,
            'product_id' => $product->id,
            'price' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $this->assertSame(
            $keep,
            DB::table('customer_product_prices')->where('product_id', $product->id)->value('customer_id'),
        );
    }

    /**
     * `customer_product_prices` tiene UNIQUE(customer_id, product_id): si ambos
     * clientes tienen precio del mismo producto, reasignar en masa violaría la
     * constraint. Debe ganar el del superviviente y descartarse el del duplicado.
     */
    public function test_no_revienta_si_ambos_tienen_precio_del_mismo_producto(): void
    {
        $keep = $this->rawCustomer('993 123 4567', 'Juan');
        $dupe = $this->rawCustomer('+529931234567', 'Juan P.');
        $product = $this->makeProduct();

        DB::table('customer_product_prices')->insert([
            ['customer_id' => $keep, 'product_id' => $product->id, 'price' => 80, 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $dupe, 'product_id' => $product->id, 'price' => 70, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('customers:normalize-phones', ['--dry-run' => 'false'])
            ->assertSuccessful();

        $prices = DB::table('customer_product_prices')->where('product_id', $product->id)->get();

        $this->assertCount(1, $prices);
        $this->assertSame($keep, $prices->first()->customer_id);
        $this->assertSame('80.00', $prices->first()->price);
    }

    /** Inserta sin pasar por el modelo, para simular datos previos al mutator. */
    private function rawCustomer(string $phone, string $name, ?int $branchId = null): int
    {
        return DB::table('customers')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'name' => $name,
            'phone' => $phone,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
