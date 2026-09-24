<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use App\Services\Agenda\AgendaReminderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Al desplegar, la isla no anuncia recordatorios de hace días: sólo los de las
 * últimas 24 h que nadie vio, lo que la campana de agenda habría mostrado.
 */
class AgendaReminderMigrationTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    public function test_the_column_exists_and_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('agenda_items', 'reminder_notified_at'));

        $column = collect(Schema::getColumns('agenda_items'))->firstWhere('name', 'reminder_notified_at');
        $this->assertTrue($column['nullable']);
    }

    public function test_backfill_marks_only_reminders_older_than_a_day(): void
    {
        $this->seedTenant();
        $make = fn (array $attrs) => AgendaItem::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->cajero->id,
        ], $attrs));

        $old = $make(['remind_at' => now()->subDays(3)]);
        $oldDeleted = $make(['remind_at' => now()->subDays(3)]);
        $oldDeleted->delete();
        $recent = $make(['remind_at' => now()->subHours(2)]);
        $future = $make(['remind_at' => now()->addHour()]);
        $none = $make(['remind_at' => null]);

        $this->assertSame(2, AgendaReminderNotifier::backfillOld());

        $marked = fn (AgendaItem $i) => DB::table('agenda_items')->where('id', $i->id)->value('reminder_notified_at');
        $this->assertNotNull($marked($old));
        $this->assertNotNull($marked($oldDeleted));
        $this->assertNull($marked($recent));
        $this->assertNull($marked($future));
        $this->assertNull($marked($none));
    }
}
