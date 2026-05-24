<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use App\Services\Agenda\AgendaCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaRecurrenceTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_weekly_event_expands_into_range(): void
    {
        AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'event',
            'title' => 'Conteo',
            'scope' => 'branch',
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'starts_at' => Carbon::parse('2026-06-07 10:00:00'), // domingo
            'recurrence' => 'weekly',
        ]);

        $occurrences = app(AgendaCalendarService::class)->expand(
            AgendaItem::query(),
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        // 7, 14, 21, 28 de junio = 4 ocurrencias
        $this->assertCount(4, $occurrences);
        $this->assertEquals('2026-06-07', $occurrences[0]['starts_at']->toDateString());
        $this->assertEquals('2026-06-28', $occurrences[3]['starts_at']->toDateString());
    }

    public function test_non_recurring_item_appears_once_if_in_range(): void
    {
        AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'event', 'title' => 'Único', 'scope' => 'company',
            'user_id' => $this->adminEmpresa->id,
            'starts_at' => Carbon::parse('2026-06-10 09:00:00'),
            'recurrence' => 'none',
        ]);

        $occ = app(AgendaCalendarService::class)->expand(
            AgendaItem::query(), Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

        $this->assertCount(1, $occ);
    }
}
