<?php

namespace Tests\Feature\Console;

use App\Models\SaleItem;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class BackfillCostPricesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_fills_null_cost_price_at_sale_from_current_product_cost(): void
    {
        $p = $this->makeProduct(['cost_price' => 75]);
        $sale = $this->makeCompletedSale();

        // Insert a SaleItem via raw DB bypassing the event (simulates pre-migration items)
        DB::table('sale_items')->insert([
            'sale_id' => $sale->id,
            'product_id' => $p->id,
            'product_name' => $p->name,
            'unit_type' => 'pieza',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'cost_price_at_sale' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('metrics:backfill-cost-prices')->assertSuccessful();

        $item = SaleItem::where('sale_id', $sale->id)->first();
        $this->assertEquals(75, (float) $item->cost_price_at_sale);
    }

    public function test_does_not_overwrite_existing_cost_price_at_sale(): void
    {
        $p = $this->makeProduct(['cost_price' => 75]);
        $sale = $this->makeCompletedSale();

        DB::table('sale_items')->insert([
            'sale_id' => $sale->id,
            'product_id' => $p->id,
            'product_name' => 'X',
            'unit_type' => 'pieza',
            'quantity' => 1,
            'unit_price' => 100,
            'subtotal' => 100,
            'cost_price_at_sale' => 42, // historical
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('metrics:backfill-cost-prices')->assertSuccessful();

        $item = SaleItem::where('sale_id', $sale->id)->first();
        $this->assertEquals(42, (float) $item->cost_price_at_sale);
    }

    public function test_idempotent_does_not_double_fill(): void
    {
        $p = $this->makeProduct(['cost_price' => 75]);
        $sale = $this->makeCompletedSale();

        DB::table('sale_items')->insert([
            'sale_id' => $sale->id, 'product_id' => $p->id, 'product_name' => 'X',
            'unit_type' => 'pieza', 'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100,
            'cost_price_at_sale' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('metrics:backfill-cost-prices');
        $this->artisan('metrics:backfill-cost-prices');

        $item = SaleItem::where('sale_id', $sale->id)->first();
        $this->assertEquals(75, (float) $item->cost_price_at_sale);
    }

    public function test_registers_backfill_date_in_settings(): void
    {
        $this->artisan('metrics:backfill-cost-prices');
        $this->assertNotNull(Setting::get('metrics.backfill_run_at'));
    }

    public function test_settings_date_not_overwritten_on_second_run(): void
    {
        $this->artisan('metrics:backfill-cost-prices');
        $first = Setting::get('metrics.backfill_run_at');

        sleep(1);
        $this->artisan('metrics:backfill-cost-prices');
        $second = Setting::get('metrics.backfill_run_at');

        $this->assertSame($first, $second);
    }
}
