<?php

namespace Tests\Feature\Sucursal;

use App\Enums\SaleStatus;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleHistorySummaryTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeSale(array $attrs = []): Sale
    {
        // created_at no está en $fillable de Sale, así que lo extraemos para
        // forzarlo via forceFill (Eloquent lo sobrescribiría con now() si no).
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $sale = Sale::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->cajero->id,
            'folio' => 'F'.uniqid(),
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 100,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed,
            'completed_at' => now(),
        ], $attrs));

        if ($createdAt) {
            $sale->forceFill(['created_at' => $createdAt])->save();
        }

        return $sale;
    }

    public function test_day_summary_only_counts_completed_excluding_pending_and_cancelled(): void
    {
        $today = now()->toDateString();

        // Cobrada (cuenta)
        $this->makeSale(['total' => 200, 'status' => SaleStatus::Completed, 'created_at' => now()]);
        // Pendiente (NO cuenta)
        $this->makeSale(['total' => 500, 'status' => SaleStatus::Pending, 'amount_paid' => 0, 'amount_pending' => 500, 'created_at' => now()]);
        // Activa (NO cuenta)
        $this->makeSale(['total' => 300, 'status' => SaleStatus::Active, 'amount_paid' => 0, 'amount_pending' => 300, 'created_at' => now()]);
        // Cancelada (NO cuenta)
        $this->makeSale(['total' => 999, 'status' => SaleStatus::Cancelled, 'created_at' => now()]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.$today);
        $summary = $response->viewData('page')['props']['daySummary'];

        $this->assertSame(200.0, $summary['total_sold']);
        $this->assertSame(1, $summary['sale_count']);
        // Conteo por estado se reporta tal cual (transparencia)
        $this->assertSame(1, $summary['by_status']['completed']['count']);
        $this->assertSame(1, $summary['by_status']['pending']['count']);
        $this->assertSame(1, $summary['by_status']['active']['count']);
        $this->assertSame(1, $summary['by_status']['cancelled']['count']);
    }

    public function test_day_summary_does_not_count_payments_made_today_for_old_sales(): void
    {
        // Venta CREADA ayer, pagada hoy. Como Historial filtra por
        // created_at, pertenece a ayer y no debe sumar al total de hoy
        // ni a sus métodos de pago.
        $oldSale = $this->makeSale([
            'total' => 1000,
            'status' => SaleStatus::Completed,
            'created_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
        Payment::create([
            'sale_id' => $oldSale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 1000,
            'created_at' => now(),  // pago HOY
        ]);

        // Venta de hoy completada
        $todaySale = $this->makeSale([
            'total' => 300,
            'status' => SaleStatus::Completed,
            'created_at' => now(),
        ]);
        Payment::create([
            'sale_id' => $todaySale->id,
            'user_id' => $this->cajero->id,
            'method' => 'card',
            'amount' => 300,
            'created_at' => now(),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.now()->toDateString());
        $summary = $response->viewData('page')['props']['daySummary'];

        // Total vendido del día solo cuenta la venta de hoy (300), no la de
        // ayer aunque haya sido cobrada hoy.
        $this->assertSame(300.0, $summary['total_sold']);
        $this->assertSame(1, $summary['sale_count']);

        $byMethod = collect($summary['by_method'])->keyBy('method');
        $this->assertEquals(0.0, $byMethod['cash']['amount']);
        $this->assertEquals(300.0, $byMethod['card']['amount']);
    }

    public function test_index_returns_only_completed_sales(): void
    {
        // Asegura que la lista del Historial filtra a status=Completed
        // independientemente de lo que llegue en query string.
        $today = now()->toDateString();
        $this->makeSale(['total' => 100, 'status' => SaleStatus::Completed, 'created_at' => now()]);
        $this->makeSale(['total' => 200, 'status' => SaleStatus::Pending, 'amount_paid' => 0, 'amount_pending' => 200, 'created_at' => now()]);
        $this->makeSale(['total' => 300, 'status' => SaleStatus::Cancelled, 'created_at' => now()]);
        $this->makeSale(['total' => 400, 'status' => SaleStatus::Active, 'amount_paid' => 0, 'amount_pending' => 400, 'created_at' => now()]);

        $this->actingAs($this->adminSucursal);

        // Sin status param → solo completed.
        $response = $this->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.$today);
        $sales = $response->viewData('page')['props']['sales']['data'];
        $this->assertCount(1, $sales);
        $this->assertSame('completed', $sales[0]['status']);

        // Con status=cancelled en URL → IGNORADO, sigue mostrando solo completed.
        $response = $this->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.$today.'&status=cancelled');
        $sales = $response->viewData('page')['props']['sales']['data'];
        $this->assertCount(1, $sales);
        $this->assertSame('completed', $sales[0]['status']);
    }

    public function test_day_summary_handles_mixed_payments_correctly(): void
    {
        $sale = $this->makeSale([
            'total' => 500,
            'status' => SaleStatus::Completed,
            'amount_paid' => 500,
            'created_at' => now(),
        ]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 200, 'created_at' => now()]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'card', 'amount' => 300, 'created_at' => now()]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.now()->toDateString());
        $summary = $response->viewData('page')['props']['daySummary'];

        $byMethod = collect($summary['by_method'])->keyBy('method');
        $this->assertEquals(200.0, $byMethod['cash']['amount']);
        $this->assertEquals(300.0, $byMethod['card']['amount']);
        $this->assertSame(500.0, $summary['total_sold']);
    }

    public function test_day_summary_includes_zero_for_active_methods_with_no_payments(): void
    {
        $this->makeSale(['total' => 100, 'created_at' => now()]);
        Payment::create([
            'sale_id' => Sale::first()->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => 100,
            'created_at' => now(),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.historial.index', $this->tenant->slug).'?date='.now()->toDateString());
        $byMethod = collect($response->viewData('page')['props']['daySummary']['by_method'])->keyBy('method');

        // Los 3 métodos activos por default (cash, card, transfer) deben aparecer
        // con $0 para los que no tuvieron pagos.
        $this->assertEquals(100.0, $byMethod['cash']['amount']);
        $this->assertEquals(0.0, $byMethod['card']['amount']);
        $this->assertEquals(0.0, $byMethod['transfer']['amount']);
    }
}
