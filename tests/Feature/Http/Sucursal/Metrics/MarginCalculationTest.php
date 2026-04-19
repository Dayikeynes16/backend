<?php

namespace Tests\Feature\Http\Sucursal\Metrics;

use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Validación end-to-end del cálculo de margen: controller + service + props.
 * Cubre la semántica post-refactor: `revenue` se restringe a items con costo
 * registrado; `items_without_cost` reporta la brecha sin entrar al cálculo.
 *
 * Nota: Inertia serializa floats enteros como JSON int ("200.0" → 200), por lo
 * que las aserciones comparan con int cuando el valor esperado es un entero.
 */
class MarginCalculationTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        Carbon::setTestNow('2026-04-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function marginUrl(array $query = []): string
    {
        $query = array_merge(['preset' => 'this_month', 'refresh' => 1], $query);

        return route('sucursal.metricas.margen', $this->tenant->slug).'?'.http_build_query($query);
    }

    /**
     * Borra cost_price_at_sale de un item existente, saltando el observer de
     * SaleItem (que sólo actúa en `creating`).
     */
    private function clearCostAtSale(SaleItem $item): void
    {
        DB::table('sale_items')->where('id', $item->id)->update(['cost_price_at_sale' => null]);
    }

    public function test_ganancia_bruta_suma_solo_items_con_costo_registrado(): void
    {
        $pA = $this->makeProduct(['name' => 'A', 'cost_price' => 60]);
        $pB = $this->makeProduct(['name' => 'B', 'cost_price' => 40]);
        $pNoCost = $this->makeProduct(['name' => 'Sin costo', 'cost_price' => null]);

        $this->makeCompletedSale([], [
            ['product_id' => $pA->id, 'product_name' => 'A', 'quantity' => 1, 'unit_price' => 100],
        ]);
        $this->makeCompletedSale([], [
            ['product_id' => $pB->id, 'product_name' => 'B', 'quantity' => 2, 'unit_price' => 80],
        ]);
        $this->makeCompletedSale([], [
            ['product_id' => $pNoCost->id, 'product_name' => 'Sin costo', 'quantity' => 1, 'unit_price' => 50],
        ]);

        $response = $this->actingAs($this->adminSucursal)->get($this->marginUrl());

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('data.summary.current.revenue', 260)
            ->where('data.summary.current.cost', 140)
            ->where('data.summary.current.gross_profit', 120)
            ->where('data.summary.current.items_with_cost', 2)
            ->where('data.summary.current.items_without_cost', 1)
        );
    }

    public function test_margen_porcentaje_es_cero_cuando_no_hay_items_con_costo(): void
    {
        $pNoCost = $this->makeProduct(['name' => 'Sin costo', 'cost_price' => null]);

        $this->makeCompletedSale([], [
            ['product_id' => $pNoCost->id, 'product_name' => 'Sin costo', 'quantity' => 1, 'unit_price' => 100],
        ]);

        $response = $this->actingAs($this->adminSucursal)->get($this->marginUrl());

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('data.summary.current.revenue', 0)
            ->where('data.summary.current.gross_profit', 0)
            ->where('data.summary.current.margin_pct', 0)
            ->where('data.summary.current.items_without_cost', 1)
            ->where('data.summary.current.items_with_cost', 0)
        );
    }

    public function test_by_product_marca_has_missing_cost_cuando_producto_tiene_ventas_sin_costo(): void
    {
        $pMixed = $this->makeProduct(['name' => 'Mixto', 'cost_price' => 30]);

        // 3 ventas con costo registrado
        for ($i = 0; $i < 3; $i++) {
            $this->makeCompletedSale([], [
                ['product_id' => $pMixed->id, 'product_name' => 'Mixto', 'quantity' => 1, 'unit_price' => 100],
            ]);
        }

        // 2 ventas sin costo: creamos y luego null-eamos cost_price_at_sale
        // (el observer auto-llena en creating a partir del producto; update no lo toca)
        for ($i = 0; $i < 2; $i++) {
            $sale = $this->makeCompletedSale();
            $item = SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $pMixed->id,
                'product_name' => 'Mixto',
                'unit_type' => 'pieza',
                'quantity' => 1,
                'unit_price' => 100,
                'subtotal' => 100,
            ]);
            $this->clearCostAtSale($item);
        }

        $response = $this->actingAs($this->adminSucursal)->get($this->marginUrl());

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('data.by_product', 1)
            ->where('data.by_product.0.product_name', 'Mixto')
            ->where('data.by_product.0.revenue', 300)       // 3 ventas × 100, sólo costeadas
            ->where('data.by_product.0.gross_profit', 210)  // (100 − 30) × 3
            ->where('data.by_product.0.has_missing_cost', true)
        );
    }

    public function test_by_category_excluye_items_sin_costo(): void
    {
        $category = \App\Models\Category::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Res',
        ]);
        $pOk = $this->makeProduct(['name' => 'Costilla', 'cost_price' => 50, 'category_id' => $category->id]);
        $pNoCost = $this->makeProduct(['name' => 'Lomo', 'cost_price' => null, 'category_id' => $category->id]);

        $this->makeCompletedSale([], [
            ['product_id' => $pOk->id, 'product_name' => 'Costilla', 'quantity' => 2, 'unit_price' => 100],
            ['product_id' => $pNoCost->id, 'product_name' => 'Lomo', 'quantity' => 1, 'unit_price' => 200],
        ]);

        $response = $this->actingAs($this->adminSucursal)->get($this->marginUrl());

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('data.by_category', 1)
            ->where('data.by_category.0.category', 'Res')
            ->where('data.by_category.0.revenue', 200)      // 100×2 (sin el Lomo)
            ->where('data.by_category.0.cost', 100)         // 50×2
            ->where('data.by_category.0.gross_profit', 100) // 200 − 100
        );
    }

    public function test_filtro_branch_se_respeta_y_no_mezcla_margen_de_otra_sucursal(): void
    {
        $pA = $this->makeProduct(['name' => 'A', 'cost_price' => 40]);

        $this->makeCompletedSale(['branch_id' => $this->branch->id], [
            ['product_id' => $pA->id, 'product_name' => 'A', 'quantity' => 1, 'unit_price' => 100],
        ]);
        // Venta en la otra sucursal — NO debe aparecer en el payload de adminSucursal
        $this->makeCompletedSale(['branch_id' => $this->secondBranch->id], [
            ['product_id' => $pA->id, 'product_name' => 'A', 'quantity' => 5, 'unit_price' => 100],
        ]);

        $response = $this->actingAs($this->adminSucursal)->get($this->marginUrl());

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('data.summary.current.revenue', 100)
            ->where('data.summary.current.gross_profit', 60)
            ->where('selected_branch_id', $this->branch->id)
        );
    }

    public function test_rango_de_fechas_excluye_ventas_fuera_del_periodo(): void
    {
        $pA = $this->makeProduct(['name' => 'A', 'cost_price' => 40]);

        // Dentro del rango: 15 abril 14:00
        $this->makeCompletedSale([
            'completed_at' => Carbon::parse('2026-04-15 14:00:00'),
        ], [
            ['product_id' => $pA->id, 'product_name' => 'A', 'quantity' => 1, 'unit_price' => 100],
        ]);

        // Fuera del rango: 16 abril 14:00
        $this->makeCompletedSale([
            'completed_at' => Carbon::parse('2026-04-16 14:00:00'),
        ], [
            ['product_id' => $pA->id, 'product_name' => 'A', 'quantity' => 5, 'unit_price' => 100],
        ]);

        // Custom range: sólo 15 abril
        $url = route('sucursal.metricas.margen', $this->tenant->slug).'?'.http_build_query([
            'from' => '2026-04-15',
            'to' => '2026-04-15',
            'refresh' => 1,
        ]);

        $response = $this->actingAs($this->adminSucursal)->get($url);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('data.summary.current.revenue', 100)
            ->where('data.summary.current.gross_profit', 60)
        );
    }
}
