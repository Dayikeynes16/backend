<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use App\Services\Agenda\IcsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaIcsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_builds_valid_ics_with_alarm(): void
    {
        $item = AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'event', 'title' => 'Entrega de carne', 'scope' => 'branch',
            'branch_id' => $this->branch->id, 'user_id' => $this->adminSucursal->id,
            'starts_at' => Carbon::parse('2026-06-10 14:00:00'),
            'remind_at' => Carbon::parse('2026-06-10 13:00:00'),
        ]);

        $ics = app(IcsBuilder::class)->forItem($item, 'test-tenant');

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Entrega de carne', $ics);
        $this->assertStringContainsString("UID:agenda-{$item->id}@test-tenant", $ics);
        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }
}
