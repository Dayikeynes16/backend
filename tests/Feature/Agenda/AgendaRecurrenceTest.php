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

    /**
     * Una ocurrencia ya completada no debe proyectarse al futuro.
     *
     * La recurrencia se materializa al completar (`complete` clona la siguiente
     * ocurrencia como fila viva), así que cada fila es UNA ocurrencia. Expandir
     * también las completadas ponía la misma tarea tachada en los 42 días del
     * calendario: el mes entero se veía idéntico y lo hecho una vez se daba por
     * hecho para siempre.
     */
    public function test_completed_occurrence_does_not_repeat_into_the_future(): void
    {
        AgendaItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'task',
            'title' => 'Revisar temperatura de cámaras',
            'scope' => 'branch',
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'starts_at' => Carbon::parse('2026-06-03 09:00:00'),
            'recurrence' => 'daily',
            'completed_at' => Carbon::parse('2026-06-03 09:20:00'),
        ]);

        $occurrences = app(AgendaCalendarService::class)->expand(
            AgendaItem::query(),
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        // Sólo el día en que se completó, no los 28 restantes del mes.
        $this->assertCount(1, $occurrences);
        $this->assertEquals('2026-06-03', $occurrences[0]['starts_at']->toDateString());
    }

    /** La ocurrencia viva sí sigue proyectándose: lo pendiente se ve en los días que vienen. */
    public function test_live_occurrence_still_expands_after_one_is_completed(): void
    {
        $common = [
            'tenant_id' => $this->tenant->id,
            'type' => 'task',
            'title' => 'Corte de caja',
            'scope' => 'branch',
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminSucursal->id,
            'recurrence' => 'daily',
        ];

        // Lo que deja `complete`: la fila completada + el clon vivo del día siguiente.
        AgendaItem::create($common + [
            'starts_at' => Carbon::parse('2026-06-03 18:00:00'),
            'completed_at' => Carbon::parse('2026-06-03 18:30:00'),
        ]);
        AgendaItem::create($common + ['starts_at' => Carbon::parse('2026-06-04 18:00:00')]);

        // `endOfDay()` como hace el controlador: si no, la ocurrencia de las 18:00
        // del último día del rango queda fuera por medianoche.
        $occurrences = app(AgendaCalendarService::class)->expand(
            AgendaItem::query(),
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-07')->endOfDay(),
        );

        $completadas = array_filter($occurrences, fn ($o) => $o['item']->completed_at !== null);
        $vivas = array_filter($occurrences, fn ($o) => $o['item']->completed_at === null);

        $this->assertCount(1, $completadas, 'la completada sólo aparece en su día');
        $this->assertCount(4, $vivas, 'la viva se proyecta del 4 al 7');
    }
}
