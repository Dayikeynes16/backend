<?php

namespace Tests\Feature\Sucursal;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SaleHistoryFiltersTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    /**
     * Venta cobrada del día (fecha canónica = completed_at).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function saleWith(array $items, array $attrs = []): Sale
    {
        return $this->makeCompletedSale(array_merge(['completed_at' => now()], $attrs), $items);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listed(string $query = ''): array
    {
        $this->actingAs($this->adminSucursal);
        $url = route('sucursal.historial.index', $this->tenant->slug).'?date='.now()->toDateString().$query;

        return $this->get($url)->viewData('page')['props']['sales']['data'];
    }

    public function test_admin_sucursal_filters_by_product_within_the_sale(): void
    {
        $withPork = $this->saleWith([
            ['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 120],
        ]);
        $this->saleWith([
            ['product_name' => 'Bistec de res', 'quantity' => 1, 'unit_price' => 90],
        ]);

        $this->assertCount(2, $this->listed());

        $found = $this->listed('&product=pulpa');
        $this->assertSame([$withPork->id], array_column($found, 'id'));
    }

    public function test_admin_sucursal_filters_by_price_range(): void
    {
        $cheap = $this->saleWith([['product_name' => 'A', 'quantity' => 1, 'unit_price' => 50]], ['total' => 50]);
        $mid = $this->saleWith([['product_name' => 'B', 'quantity' => 1, 'unit_price' => 150]], ['total' => 150]);
        $pricey = $this->saleWith([['product_name' => 'C', 'quantity' => 1, 'unit_price' => 500]], ['total' => 500]);

        $found = $this->listed('&min_total=100&max_total=200');
        $this->assertSame([$mid->id], array_column($found, 'id'));

        $found = $this->listed('&min_total=100');
        $this->assertEqualsCanonicalizing([$mid->id, $pricey->id], array_column($found, 'id'));
    }

    public function test_product_filter_does_not_lift_the_date_unlike_folio_search(): void
    {
        // Pulpa de AYER: no aparece al filtrar por producto en el día de hoy.
        $this->saleWith(
            [['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 120]],
            ['completed_at' => now()->subDay()],
        );
        $today = $this->saleWith([
            ['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 120],
        ]);

        $found = $this->listed('&product=pulpa');
        $this->assertSame([$today->id], array_column($found, 'id'));
    }

    public function test_product_and_price_filters_combine_with_folio_search(): void
    {
        // El folio search ignora la fecha; producto y precio lo siguen acotando.
        $old = $this->saleWith(
            [['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 180]],
            ['completed_at' => now()->subDays(4), 'total' => 180],
        );
        // Mismo folio-able pero fuera de rango: distinto producto.
        $this->saleWith(
            [['product_name' => 'Costilla', 'quantity' => 1, 'unit_price' => 180]],
            ['completed_at' => now()->subDays(4), 'total' => 180],
        );

        $found = $this->listed('&search='.$old->folio.'&product=pulpa&min_total=100&max_total=200');
        $this->assertSame([$old->id], array_column($found, 'id'));
    }
}
