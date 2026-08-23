<?php

namespace Tests\Feature\Caja;

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class HistorialFiltersTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    /**
     * Venta cobrada por el cajero (con su pago registrado, requisito del historial).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function paidSale(array $items, array $attrs = []): Sale
    {
        // created_at no es fillable en Sale: para fechas pasadas hay que forzarlo.
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $sale = $this->makeCompletedSale($attrs, $items);

        if ($createdAt) {
            $sale->forceFill(['created_at' => $createdAt])->save();
        }

        Payment::create([
            'sale_id' => $sale->id,
            'user_id' => $this->cajero->id,
            'method' => 'cash',
            'amount' => $sale->total,
            'created_at' => now(),
        ]);

        return $sale;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listed(string $query = ''): array
    {
        $this->actingAs($this->cajero);
        $url = route('caja.historial', $this->tenant->slug).($query ? '?'.$query : '');

        return $this->get($url)->viewData('page')['props']['sales']['data'];
    }

    public function test_filters_by_product_name_within_the_sale(): void
    {
        $withPork = $this->paidSale([
            ['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 120],
            ['product_name' => 'Chorizo', 'quantity' => 1, 'unit_price' => 40],
        ]);
        $this->paidSale([
            ['product_name' => 'Bistec de res', 'quantity' => 1, 'unit_price' => 90],
        ]);

        $this->assertCount(2, $this->listed());

        $found = $this->listed('product=pulpa');
        $this->assertSame([$withPork->id], array_column($found, 'id'));
    }

    public function test_product_search_is_case_insensitive_and_matches_substrings(): void
    {
        $sale = $this->paidSale([
            ['product_name' => 'Pulpa de Cerdo Especial', 'quantity' => 1, 'unit_price' => 150],
        ]);

        $found = $this->listed('product='.urlencode('PULPA DE cerdo'));
        $this->assertSame([$sale->id], array_column($found, 'id'));
    }

    public function test_filters_by_price_range_on_sale_total(): void
    {
        $cheap = $this->paidSale([['product_name' => 'A', 'quantity' => 1, 'unit_price' => 50]], ['total' => 50]);
        $mid = $this->paidSale([['product_name' => 'B', 'quantity' => 1, 'unit_price' => 150]], ['total' => 150]);
        $pricey = $this->paidSale([['product_name' => 'C', 'quantity' => 1, 'unit_price' => 500]], ['total' => 500]);

        $found = $this->listed('min_total=100&max_total=200');
        $this->assertSame([$mid->id], array_column($found, 'id'));

        $found = $this->listed('min_total=100');
        $this->assertEqualsCanonicalizing([$mid->id, $pricey->id], array_column($found, 'id'));

        $found = $this->listed('max_total=100');
        $this->assertSame([$cheap->id], array_column($found, 'id'));
    }

    public function test_product_filter_respects_the_selected_day(): void
    {
        // Venta con pulpa pero de AYER: no debe aparecer al mirar el día de hoy.
        $this->paidSale(
            [['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 120]],
            ['created_at' => now()->subDay()],
        );
        $today = $this->paidSale(
            [['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 120]],
            ['created_at' => now()],
        );

        $found = $this->listed('product=pulpa');
        $this->assertSame([$today->id], array_column($found, 'id'));
    }

    // ── Búsqueda por folio ──────────────────────────────────────────────
    //
    // Es lo que hace utilizable el salto «Ver venta ↗» desde Pagos.

    public function test_folio_search_ignores_the_selected_day(): void
    {
        // El caso que justifica la regla: fiado de anteayer cobrado hoy. Si el día
        // siguiera filtrando, el salto desde Pagos aterrizaría en un historial vacío.
        $old = $this->paidSale(
            [['product_name' => 'Pulpa', 'quantity' => 1, 'unit_price' => 120]],
            ['folio' => 'V-00184', 'created_at' => now()->subDays(2)],
        );
        $this->paidSale(
            [['product_name' => 'Bistec', 'quantity' => 1, 'unit_price' => 90]],
            ['folio' => 'V-00190', 'created_at' => now()],
        );

        $found = $this->listed('search=V-00184');
        $this->assertSame([$old->id], array_column($found, 'id'));
    }

    public function test_folio_search_is_case_insensitive_and_matches_substrings(): void
    {
        $sale = $this->paidSale(
            [['product_name' => 'Pulpa', 'quantity' => 1, 'unit_price' => 120]],
            ['folio' => 'V-00184'],
        );

        $this->assertSame([$sale->id], array_column($this->listed('search=v-00184'), 'id'));
        $this->assertSame([$sale->id], array_column($this->listed('search=00184'), 'id'));
    }

    public function test_folio_search_does_not_reach_sales_the_cashier_never_charged(): void
    {
        // Buscar no ensancha el alcance: el historial de caja sigue siendo el de
        // las ventas donde ESTE cajero registró un pago.
        $otherCashier = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);

        $foreign = $this->makeCompletedSale(['folio' => 'V-00999'], [
            ['product_name' => 'Pulpa', 'quantity' => 1, 'unit_price' => 120],
        ]);
        Payment::create([
            'sale_id' => $foreign->id,
            'user_id' => $otherCashier->id,
            'method' => 'cash',
            'amount' => $foreign->total,
            'created_at' => now(),
        ]);

        $this->assertCount(0, $this->listed('search=V-00999'));
    }

    public function test_without_a_folio_search_the_day_still_filters(): void
    {
        // Guarda de regresión: el filtro nuevo no debe aflojar el comportamiento
        // por defecto del historial, que es el día en curso.
        $this->paidSale(
            [['product_name' => 'Pulpa', 'quantity' => 1, 'unit_price' => 120]],
            ['folio' => 'V-00184', 'created_at' => now()->subDays(2)],
        );

        $this->assertCount(0, $this->listed());
        $this->assertCount(1, $this->listed('search=V-00184'));
    }

    public function test_folio_search_combines_with_the_price_range(): void
    {
        $this->paidSale(
            [['product_name' => 'Pulpa', 'quantity' => 1, 'unit_price' => 500]],
            ['folio' => 'V-00184', 'total' => 500, 'created_at' => now()->subDays(2)],
        );

        $this->assertCount(1, $this->listed('search=V-00184'));
        $this->assertCount(0, $this->listed('search=V-00184&max_total=100'));
    }

    public function test_product_and_price_filters_combine(): void
    {
        $match = $this->paidSale([['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 180]], ['total' => 180]);
        // Mismo producto, fuera del rango de precio.
        $this->paidSale([['product_name' => 'Pulpa de cerdo', 'quantity' => 1, 'unit_price' => 30]], ['total' => 30]);
        // Dentro del rango, otro producto.
        $this->paidSale([['product_name' => 'Bistec', 'quantity' => 1, 'unit_price' => 180]], ['total' => 180]);

        $found = $this->listed('product=pulpa&min_total=100&max_total=200');
        $this->assertSame([$match->id], array_column($found, 'id'));
    }
}
