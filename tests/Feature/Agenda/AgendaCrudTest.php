<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaCrudTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_creates_personal_task(): void
    {
        $this->actingAs($this->cajero)
            ->post(route('agenda.store', $this->tenant->slug), [
                'type' => 'task', 'title' => 'Contar inventario', 'scope' => 'personal',
            ])->assertRedirect();

        $this->assertDatabaseHas('agenda_items', [
            'title' => 'Contar inventario', 'user_id' => $this->cajero->id, 'scope' => 'personal',
        ]);
    }

    public function test_cajero_cannot_create_company_scope(): void
    {
        $this->actingAs($this->cajero)
            ->post(route('agenda.store', $this->tenant->slug), [
                'type' => 'note', 'title' => 'Aviso', 'scope' => 'company',
            ])->assertForbidden();
    }

    public function test_completing_recurring_task_generates_next(): void
    {
        $task = AgendaItem::create([
            'tenant_id' => $this->tenant->id, 'type' => 'task', 'title' => 'Pagar renta',
            'scope' => 'branch', 'branch_id' => $this->branch->id, 'user_id' => $this->adminSucursal->id,
            'starts_at' => Carbon::parse('2026-06-01 09:00:00'), 'recurrence' => 'monthly',
        ]);

        $this->actingAs($this->adminSucursal)
            ->patch(route('agenda.complete', [$this->tenant->slug, $task->id]))
            ->assertRedirect();

        $this->assertNotNull($task->fresh()->completed_at);
        // Se generó la siguiente (julio)
        $this->assertDatabaseHas('agenda_items', [
            'title' => 'Pagar renta', 'completed_at' => null,
        ]);
        $this->assertEquals(2, AgendaItem::where('title', 'Pagar renta')->count());
    }

    public function test_ics_download(): void
    {
        $item = AgendaItem::create([
            'tenant_id' => $this->tenant->id, 'type' => 'event', 'title' => 'Entrega',
            'scope' => 'company', 'user_id' => $this->adminEmpresa->id,
            'starts_at' => Carbon::parse('2026-06-10 14:00:00'),
        ]);

        $res = $this->actingAs($this->adminEmpresa)
            ->get(route('agenda.ics', [$this->tenant->slug, $item->id]));

        $res->assertOk();
        $res->assertHeader('content-type', 'text/calendar; charset=UTF-8');
        $this->assertStringContainsString('BEGIN:VEVENT', $res->getContent());
    }
}
