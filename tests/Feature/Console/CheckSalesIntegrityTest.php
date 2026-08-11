<?php

namespace Tests\Feature\Console;

use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * `sales:check-integrity` es de solo lectura: reporta ventas cuyo total no está
 * respaldado por sus líneas, que son las que cambiarían de importe al asignarles
 * un cliente.
 */
class CheckSalesIntegrityTest extends TestCase
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

    public function test_reporta_todo_coherente_cuando_lo_esta(): void
    {
        $this->makeSale(total: 200, lineSubtotal: 200);

        $this->artisan('sales:check-integrity')
            ->expectsOutputToContain('Todo coherente')
            ->assertSuccessful();
    }

    public function test_detecta_venta_con_importe_y_sin_lineas(): void
    {
        $this->makeSale(total: 250, lineSubtotal: null);

        $this->artisan('sales:check-integrity')
            ->expectsOutputToContain('sin lineas')
            ->assertSuccessful();
    }

    public function test_detecta_total_distinto_a_la_suma_de_lineas(): void
    {
        $this->makeSale(total: 250, lineSubtotal: 200);

        $this->artisan('sales:check-integrity')
            ->expectsOutputToContain('distinto a lineas + envio')
            ->assertSuccessful();
    }

    /**
     * El total de una venta a domicilio es líneas + envío. Si el comando no
     * contara el envío, marcaría como desfasada toda venta con domicilio sana
     * — que es justo lo que pasó al correrlo por primera vez en producción.
     */
    public function test_una_venta_con_envio_es_coherente(): void
    {
        $this->makeSale(total: 230, lineSubtotal: 200, deliveryFee: 30);

        $this->artisan('sales:check-integrity')
            ->expectsOutputToContain('Todo coherente')
            ->assertSuccessful();
    }

    public function test_detecta_desfase_real_en_una_venta_con_envio(): void
    {
        $this->makeSale(total: 300, lineSubtotal: 200, deliveryFee: 30);

        $this->artisan('sales:check-integrity')
            ->expectsOutputToContain('distinto a lineas + envio')
            ->assertSuccessful();
    }

    public function test_una_venta_vacia_con_total_cero_es_coherente(): void
    {
        $this->makeSale(total: 0, lineSubtotal: null);

        $this->artisan('sales:check-integrity')
            ->expectsOutputToContain('Todo coherente')
            ->assertSuccessful();
    }

    public function test_no_modifica_nada(): void
    {
        $sale = $this->makeSale(total: 250, lineSubtotal: 200);

        $this->artisan('sales:check-integrity')->assertSuccessful();

        $this->assertSame('250.00', $sale->fresh()->total);
    }

    public function test_puede_acotarse_a_una_sucursal(): void
    {
        $this->makeSale(total: 250, lineSubtotal: null, branchId: $this->secondBranch->id);

        $this->artisan('sales:check-integrity', ['--branch' => $this->branch->id])
            ->expectsOutputToContain('Todo coherente')
            ->assertSuccessful();
    }

    private function makeSale(float $total, ?float $lineSubtotal, ?int $branchId = null, ?float $deliveryFee = null): Sale
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'folio' => 'V-'.uniqid(),
            'total' => $total,
            'amount_paid' => 0,
            'amount_pending' => $total,
            'origin' => 'admin',
            'status' => SaleStatus::Active,
            'delivery_type' => $deliveryFee === null ? null : 'delivery',
            'delivery_fee' => $deliveryFee,
        ]);

        if ($lineSubtotal !== null) {
            $sale->items()->create([
                'product_id' => $this->product->id,
                'product_name' => $this->product->name,
                'quantity' => 1,
                'quantity_unit' => 'kg',
                'unit_type' => 'kg',
                'unit_price' => $lineSubtotal,
                'original_unit_price' => $lineSubtotal,
                'subtotal' => $lineSubtotal,
            ]);
        }

        return $sale->fresh();
    }
}
