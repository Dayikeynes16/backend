<?php

namespace Tests\Feature\Compras;

use App\Models\Provider;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class PurchaseHiddenWhenCancelledTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_cancelled_purchase_is_hidden_from_sucursal_index(): void
    {
        Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P', 'type' => 'otro'])->id,
            'folio' => 'CMP-CANCEL', 'purchased_at' => now(), 'status' => 'cancelled',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'created_by' => $this->adminSucursal->id, 'cancelled_at' => now(), 'cancelled_by' => $this->adminSucursal->id,
        ]);
        Purchase::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'P2', 'type' => 'otro'])->id,
            'folio' => 'CMP-VIVA', 'purchased_at' => now(), 'status' => 'received',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0, 'amount_pending' => 100,
            'created_by' => $this->adminSucursal->id,
        ]);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.compras.index', $this->tenant->slug))
            ->assertInertia(fn ($page) => $page
                ->has('purchases', 1)
                ->where('purchases.0.folio', 'CMP-VIVA'));
    }
}
