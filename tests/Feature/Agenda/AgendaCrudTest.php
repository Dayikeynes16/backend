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

    public function test_cajero_creates_branch_task_with_own_branch(): void
    {
        $this->actingAs($this->cajero)
            ->post(route('agenda.store', $this->tenant->slug), [
                'type' => 'task', 'title' => 'Informar que no hay monedas', 'scope' => 'branch',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('agenda_items', [
            'title' => 'Informar que no hay monedas',
            'user_id' => $this->cajero->id,
            'scope' => 'branch',
            'branch_id' => $this->cajero->branch_id,
        ]);
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

    /**
     * El endpoint del calendario debe responder 200 y traer la ocurrencia.
     *
     * Los tests de recurrencia ejercitan `AgendaCalendarService` directamente, así
     * que no vieron que el controlador cargaba relaciones con un nombre que no
     * existe (`assignedTo`/`user` en vez de `assignee`/`creator`): la ruta
     * devolvía 500 y el calendario se quedaba vacío en pantalla, sin error
     * visible. Este test cubre la ruta HTTP, que es lo que usa el navegador.
     */
    public function test_calendar_endpoint_returns_the_day_occurrences(): void
    {
        AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'task',
            'title' => 'Recordatorio de prueba',
            'scope' => 'personal',
            'user_id' => $this->cajero->id,
            'starts_at' => Carbon::parse('2026-08-07 08:40:00'),
            'priority' => 'high',
        ]);

        $res = $this->actingAs($this->cajero)
            ->getJson(route('agenda.calendar', $this->tenant->slug).'?from=2026-08-01&to=2026-08-31')
            ->assertOk();

        $occ = $res->json('occurrences');
        $this->assertCount(1, $occ);
        $this->assertSame('Recordatorio de prueba', $occ[0]['title']);
        $this->assertStringStartsWith('2026-08-07', $occ[0]['starts_at']);
    }

    /** El panel del día y el modal de edición necesitan el ítem completo. */
    public function test_calendar_occurrence_carries_what_the_panel_and_modal_need(): void
    {
        AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'task',
            'title' => 'Pedido a proveedor',
            'body' => 'Confirmar precio antes de cerrar.',
            'scope' => 'personal',
            'user_id' => $this->cajero->id,
            'starts_at' => Carbon::parse('2026-08-10 07:30:00'),
            'priority' => 'high',
            'recurrence' => 'weekly',
        ]);

        $occ = $this->actingAs($this->cajero)
            ->getJson(route('agenda.calendar', $this->tenant->slug).'?from=2026-08-10&to=2026-08-10')
            ->assertOk()
            ->json('occurrences.0');

        // Sin estos campos la tarea se abre pero no se puede guardar.
        foreach (['id', 'title', 'body', 'type', 'scope', 'branch_id', 'assigned_to_user_id',
            'priority', 'recurrence', 'recurrence_until', 'starts_at', 'ends_at',
            'remind_at', 'state', 'all_day', 'completed_at', 'owner'] as $campo) {
            $this->assertArrayHasKey($campo, $occ, "falta {$campo} en la ocurrencia");
        }

        $this->assertSame('high', $occ['priority']);
        $this->assertSame('weekly', $occ['recurrence']);
        $this->assertSame($this->cajero->name, $occ['owner']);
    }
}
