<?php

namespace Tests\Feature\Migrations;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class LinkedOrderSchemaTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_sales_table_has_linked_order_id_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('sales', 'linked_order_id'),
            'Expected sales table to have linked_order_id column'
        );
    }

    public function test_linked_order_id_index_exists(): void
    {
        $row = DB::selectOne(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'sales' AND indexname = 'sales_linked_order_id_idx'"
        );

        $this->assertNotNull($row, 'Expected sales_linked_order_id_idx to exist');
    }

    public function test_linked_order_id_is_self_foreign_key_with_set_null_on_delete(): void
    {
        $fk = DB::selectOne("
            SELECT confrelid::regclass::text AS referenced_table, confdeltype
            FROM pg_constraint
            WHERE conrelid = 'sales'::regclass
              AND contype = 'f'
              AND conkey @> ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = 'sales'::regclass AND attname = 'linked_order_id')]
        ");

        $this->assertNotNull($fk, 'Expected foreign key on linked_order_id');
        $this->assertSame('sales', $fk->referenced_table);
        $this->assertSame('n', $fk->confdeltype, 'Expected ON DELETE SET NULL (confdeltype=n)');
    }

    public function test_sale_can_persist_linked_order_id(): void
    {
        $webOrder = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'WEB-1',
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ]);

        $scaleSale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'API-1',
            'payment_method' => 'cash',
            'total' => 110,
            'amount_paid' => 0,
            'amount_pending' => 110,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
            'linked_order_id' => $webOrder->id,
        ]);

        $this->assertSame($webOrder->id, $scaleSale->fresh()->linked_order_id);
        $this->assertSame($webOrder->id, $scaleSale->linkedOrder->id);
        $this->assertSame($scaleSale->id, $webOrder->fulfilledBy->id);
    }

    public function test_deleting_linked_web_order_nulls_the_link_instead_of_cascading(): void
    {
        $webOrder = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'WEB-2',
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'web',
            'status' => SaleStatus::Pending->value,
        ]);

        $scaleSale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'folio' => 'API-2',
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 0,
            'amount_pending' => 100,
            'origin' => 'api',
            'status' => SaleStatus::Active->value,
            'linked_order_id' => $webOrder->id,
        ]);

        // Force-delete to bypass SoftDeletes and trigger the FK constraint
        $webOrder->forceDelete();

        $this->assertNull($scaleSale->fresh()->linked_order_id);
        $this->assertNotNull(Sale::find($scaleSale->id), 'Scale sale should survive when web order is deleted');
    }

    public function test_accountable_scope_excludes_web_pending_and_fulfilled(): void
    {
        $base = [
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'payment_method' => 'cash',
            'total' => 100,
            'amount_paid' => 100,
            'amount_pending' => 0,
        ];

        $apiActive = Sale::create($base + ['folio' => 'A1', 'origin' => 'api', 'status' => SaleStatus::Active->value]);
        $apiCompleted = Sale::create($base + ['folio' => 'A2', 'origin' => 'api', 'status' => SaleStatus::Completed->value]);
        $webPending = Sale::create($base + ['folio' => 'W1', 'origin' => 'web', 'status' => SaleStatus::Pending->value]);
        $webFulfilled = Sale::create($base + ['folio' => 'W2', 'origin' => 'web', 'status' => SaleStatus::Fulfilled->value]);
        $webCancelled = Sale::create($base + ['folio' => 'W3', 'origin' => 'web', 'status' => SaleStatus::Cancelled->value]);

        $ids = Sale::accountable()->pluck('id')->all();

        $this->assertContains($apiActive->id, $ids);
        $this->assertContains($apiCompleted->id, $ids);
        $this->assertContains($webCancelled->id, $ids);
        $this->assertNotContains($webPending->id, $ids);
        $this->assertNotContains($webFulfilled->id, $ids);
    }
}
