<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaStatesTest extends TestCase
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

    public function test_state_accessor_and_scopes(): void
    {
        $pending = $this->make(['starts_at' => now()->addDay()]);
        $overdue = $this->make(['starts_at' => now()->subDay()]);
        $done = $this->make(['completed_at' => now()]);
        $cancelled = $this->make(['cancelled_at' => now(), 'cancel_reason' => 'ya no']);

        $this->assertSame('pending', $pending->state);
        $this->assertSame('overdue', $overdue->state);
        $this->assertSame('completed', $done->state);
        $this->assertSame('cancelled', $cancelled->state);

        $this->assertEqualsCanonicalizing(
            [$pending->id, $overdue->id],
            AgendaItem::active()->pluck('id')->all()
        );
        $this->assertEquals([$overdue->id], AgendaItem::overdue()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$done->id, $cancelled->id],
            AgendaItem::history()->pluck('id')->all()
        );
    }
}
