<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SharedBranchTicketWidthTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_branch_ticket_width_defaults_to_80mm_when_unconfigured(): void
    {
        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.branch.ticket_width', '80mm'));
    }

    public function test_branch_ticket_width_follows_configured_value(): void
    {
        $this->branch->update(['ticket_config' => ['width' => '58mm']]);

        $this->actingAs($this->cajero)
            ->get(route('caja.pagos', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.branch.ticket_width', '58mm'));
    }
}
