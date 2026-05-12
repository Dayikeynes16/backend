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
        // created_at no está en $fillable de Sale, así que lo forzamos via
        // forceFill (Eloquent lo sobrescribiría con now() si no).
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

    private function daySummary(?string $date = null): array
    {
        $this->actingAs($this->adminSucursal);
        $url = route('sucursal.historial.index', $this->tenant->slug).'?date='.($date ?? now()->toDateString());

        return $this->get($url)->viewData('page')['props']['daySummary'];
    }

    private function listedSales(string $query = ''): array
    {
        $this->actingAs($this->adminSucursal);
        $url = route('sucursal.historial.index', $this->tenant->slug).'?date='.now()->toDateString().$query;

        return $this->get($url)->viewData('page')['props']['sales']['data'];
    }

    public function test_day_summary_counts_only_completed_and_reports_cancellations_separately(): void
    {
        // Cobrada al contado (cuenta)
        $this->makeSale(['total' => 200, 'status' => SaleStatus::Completed, 'created_at' => now()]);
        // "Cobrada" a crédito (status completed con saldo pendiente) — también cuenta
        $this->makeSale(['total' => 500, 'status' => SaleStatus::Completed, 'amount_paid' => 0, 'amount_pending' => 500, 'created_at' => now()]);
        // Pendiente (NO cuenta)
        $this->makeSale(['total' => 999, 'status' => SaleStatus::Pending, 'amount_paid' => 0, 'amount_pending' => 999, 'created_at' => now()]);
        // Activa (NO cuenta — todavía en mesa de trabajo)
        $this->makeSale(['total' => 300, 'status' => SaleStatus::Active, 'amount_paid' => 0, 'amount_pending' => 300, 'created_at' => now()]);
        // Cancelada (NO cuenta en ventas; se reporta aparte, NO se resta)
        $this->makeSale(['total' => 50, 'status' => SaleStatus::Cancelled, 'created_at' => now(), 'cancelled_at' => now()]);

        $summary = $this->daySummary();

        $this->assertSame(700.0, $summary['total_sold']);   // 200 + 500 (solo completadas)
        $this->assertSame(2, $summary['sale_count']);
        $this->assertSame(350.0, $summary['avg_ticket']);   // 700 / 2
        $this->assertSame(50.0, $summary['cancelled_amount']);  // reportada aparte, no restada
        $this->assertSame(1, $summary['cancelled_count']);
    }

    public function test_collections_block_includes_payments_made_today_even_for_old_sales(): void
    {
        // Venta de AYER (creada y completada ayer), abonada HOY → su importe NO
        // entra a "ventas netas" de hoy, pero el pago SÍ entra a la cobranza
        // de hoy, marcado como "de cuentas anteriores".
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
            'created_at' => now(),
        ]);

        // Venta de HOY cobrada hoy.
        $todaySale = $this->makeSale(['total' => 300, 'status' => SaleStatus::Completed, 'created_at' => now()]);
        Payment::create([
            'sale_id' => $todaySale->id,
            'user_id' => $this->cajero->id,
            'method' => 'card',
            'amount' => 300,
            'created_at' => now(),
        ]);

        $summary = $this->daySummary();

        // Ventas netas: solo la venta de hoy.
        $this->assertSame(300.0, $summary['total_sold']);
        $this->assertSame(1, $summary['sale_count']);

        // Cobranza: ambos pagos de hoy, con split por antigüedad.
        $this->assertSame(1300.0, $summary['total_collected']);
        $this->assertSame(300.0, $summary['collected_from_today']);
        $this->assertSame(1000.0, $summary['collected_from_previous']);

        $byMethod = collect($summary['by_method'])->keyBy('method');
        $this->assertSame(1000.0, $byMethod['cash']['total']);
        $this->assertSame(300.0, $byMethod['card']['total']);
        $this->assertSame(0.0, $byMethod['transfer']['total']);
    }

    public function test_listing_shows_completed_and_pending_and_search_lifts_the_status_filter(): void
    {
        $this->makeSale(['total' => 100, 'status' => SaleStatus::Completed, 'created_at' => now()]);
        $this->makeSale(['total' => 200, 'status' => SaleStatus::Pending, 'amount_paid' => 0, 'amount_pending' => 200, 'created_at' => now()]);
        $this->makeSale(['total' => 400, 'status' => SaleStatus::Active, 'amount_paid' => 0, 'amount_pending' => 400, 'created_at' => now()]);
        $cancelled = $this->makeSale(['total' => 300, 'status' => SaleStatus::Cancelled, 'created_at' => now(), 'cancelled_at' => now()]);

        // Sin búsqueda → solo completed + pending del día.
        $sales = $this->listedSales();
        $this->assertCount(2, $sales);
        $this->assertEqualsCanonicalizing(['completed', 'pending'], array_column($sales, 'status'));

        // Búsqueda por folio → muestra cualquier estado (incluida la cancelada).
        $sales = $this->listedSales('&search='.$cancelled->folio);
        $this->assertCount(1, $sales);
        $this->assertSame('cancelled', $sales[0]['status']);
    }

    public function test_search_by_folio_finds_sales_from_other_days(): void
    {
        // Venta de hace 5 días — no debe aparecer en el listado del día de hoy...
        $oldSale = $this->makeSale([
            'total' => 750,
            'status' => SaleStatus::Completed,
            'created_at' => now()->subDays(5),
            'completed_at' => now()->subDays(5),
        ]);
        $this->makeSale(['total' => 100, 'status' => SaleStatus::Completed, 'created_at' => now()]);

        // ...sin búsqueda, mirando "hoy", no está.
        $today = $this->listedSales();
        $this->assertNotContains($oldSale->id, array_column($today, 'id'));

        // ...pero al buscar por su folio aparece, aunque la fecha siga puesta en hoy.
        $found = $this->listedSales('&search='.$oldSale->folio);
        $this->assertSame([$oldSale->id], array_column($found, 'id'));
    }

    public function test_search_by_folio_finds_a_pending_sale_from_another_day(): void
    {
        // Venta PENDIENTE de hace 3 días — el caso reportado: no aparece en
        // "hoy" ni saltaría el filtro de estado si no se buscara.
        $pending = $this->makeSale([
            'total' => 400,
            'status' => SaleStatus::Pending,
            'amount_paid' => 0,
            'amount_pending' => 400,
            'created_at' => now()->subDays(3),
            'completed_at' => null,
        ]);

        $today = $this->listedSales();
        $this->assertNotContains($pending->id, array_column($today, 'id'));

        $found = $this->listedSales('&search='.$pending->folio);
        $this->assertSame([$pending->id], array_column($found, 'id'));
        $this->assertSame('pending', $found[0]['status']);
    }

    public function test_day_summary_collections_split_mixed_payments_by_method(): void
    {
        $sale = $this->makeSale(['total' => 500, 'status' => SaleStatus::Completed, 'amount_paid' => 500, 'created_at' => now()]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 200, 'created_at' => now()]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'card', 'amount' => 300, 'created_at' => now()]);

        $summary = $this->daySummary();

        $byMethod = collect($summary['by_method'])->keyBy('method');
        $this->assertSame(200.0, $byMethod['cash']['total']);
        $this->assertSame(300.0, $byMethod['card']['total']);
        $this->assertSame(0.0, $byMethod['transfer']['total']);
        $this->assertSame(500.0, $summary['total_sold']);
        $this->assertSame(500.0, $summary['total_collected']);
    }

    public function test_day_summary_lists_active_methods_with_zero_when_no_payments(): void
    {
        $sale = $this->makeSale(['total' => 100, 'status' => SaleStatus::Completed, 'created_at' => now()]);
        Payment::create(['sale_id' => $sale->id, 'user_id' => $this->cajero->id, 'method' => 'cash', 'amount' => 100, 'created_at' => now()]);

        $byMethod = collect($this->daySummary()['by_method'])->keyBy('method');

        $this->assertSame(100.0, $byMethod['cash']['total']);
        $this->assertSame(0.0, $byMethod['card']['total']);
        $this->assertSame(0.0, $byMethod['transfer']['total']);
    }
}
