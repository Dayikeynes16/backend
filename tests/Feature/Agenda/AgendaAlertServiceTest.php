<?php

namespace Tests\Feature\Agenda;

use App\Models\Provider;
use App\Models\Purchase;
use App\Services\Agenda\AgendaAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaAlertServiceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_lists_accounts_payable_for_visible_branch(): void
    {
        Purchase::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'provider_id' => Provider::create(['name' => 'Don Pedro', 'type' => 'mayorista_carne'])->id,
            'folio' => 'C-1', 'purchased_at' => now()->subDays(5),
            'status' => 'received', 'subtotal' => 1000, 'total' => 1000,
            'amount_paid' => 0, 'amount_pending' => 1000,
            'created_by' => $this->adminSucursal->id,
        ]);

        $alerts = app(AgendaAlertService::class)->for($this->adminSucursal);

        $payable = array_values(array_filter($alerts, fn ($a) => $a['source'] === 'accounts_payable'));
        $this->assertNotEmpty($payable);
        $this->assertEquals(1000.0, $payable[0]['amount']);
    }

    public function test_does_not_write_to_database(): void
    {
        app(AgendaAlertService::class)->for($this->adminEmpresa);
        $this->assertDatabaseCount('agenda_items', 0);
    }
}
