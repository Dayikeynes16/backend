<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaNotificationsTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function make(array $attrs): AgendaItem
    {
        return AgendaItem::create(array_merge([
            'tenant_id' => $this->tenant->id, 'type' => 'task', 'title' => 'X',
            'scope' => 'personal', 'user_id' => $this->cajero->id,
        ], $attrs));
    }

    public function test_notifications_lists_due_reminders_and_overdue(): void
    {
        $this->make(['remind_at' => now()->subMinute()]);          // due reminder
        $this->make(['starts_at' => now()->subDay()]);             // overdue
        $this->make(['starts_at' => now()->addDay()]);             // pending (no aparece)
        $this->make(['remind_at' => now()->subMinute(), 'reminder_seen_at' => now()]); // ya visto

        $res = $this->actingAs($this->cajero)
            ->getJson(route('agenda.notificaciones', $this->tenant->slug));

        $res->assertOk();
        $res->assertJsonPath('counts.due_reminders', 1);
        $res->assertJsonPath('counts.overdue', 1);
    }

    public function test_cancel_sets_reason_and_excludes_from_active(): void
    {
        $item = $this->make(['starts_at' => now()->addDay()]);

        $this->actingAs($this->cajero)
            ->patch(route('agenda.cancel', [$this->tenant->slug, $item->id]), ['cancel_reason' => 'ya no aplica'])
            ->assertRedirect();

        $item->refresh();
        $this->assertNotNull($item->cancelled_at);
        $this->assertSame('ya no aplica', $item->cancel_reason);
        $this->assertEquals(0, AgendaItem::active()->count());
    }

    public function test_snooze_moves_remind_at_and_clears_seen(): void
    {
        $item = $this->make(['starts_at' => now()->addDay(), 'remind_at' => now()->subMinute(), 'reminder_seen_at' => now()]);

        $this->actingAs($this->cajero)
            ->patch(route('agenda.snooze', [$this->tenant->slug, $item->id]), ['minutes' => 30])
            ->assertRedirect();

        $item->refresh();
        $this->assertNull($item->reminder_seen_at);
        $this->assertTrue($item->remind_at->gt(now()->addMinutes(25)));
    }

    public function test_mark_seen(): void
    {
        $item = $this->make(['remind_at' => now()->subMinute()]);
        $this->actingAs($this->cajero)
            ->patch(route('agenda.visto', [$this->tenant->slug, $item->id]))->assertRedirect();
        $this->assertNotNull($item->fresh()->reminder_seen_at);
    }

    public function test_completed_history(): void
    {
        $this->make(['completed_at' => now()]);
        $this->make(['cancelled_at' => now()]);
        $this->make(['starts_at' => now()->addDay()]); // activa, no aparece

        $res = $this->actingAs($this->cajero)
            ->getJson(route('agenda.completadas', $this->tenant->slug));

        $res->assertOk()->assertJsonCount(2, 'items.data');
    }
}
