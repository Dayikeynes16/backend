<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiExpenseDraft;
use App\Models\AssistantDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpireAiDraftsCommandTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function assistantDraft(array $attrs = []): AssistantDraft
    {
        return AssistantDraft::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminEmpresa->id,
            'type' => AssistantDraftType::Expense->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['monto' => 10],
            'expires_at' => now()->addHours(6),
        ], $attrs));
    }

    public function test_expires_stale_assistant_drafts_but_leaves_fresh_ones(): void
    {
        $stale = $this->assistantDraft(['expires_at' => now()->subHour()]);
        $fresh = $this->assistantDraft(['expires_at' => now()->addHour()]);

        $this->artisan('ai:expire-drafts')->assertSuccessful();

        $this->assertSame(AiDraftStatus::Expired, $stale->refresh()->status);
        $this->assertSame(AiDraftStatus::Ready, $fresh->refresh()->status);
    }

    public function test_does_not_touch_consumed_drafts(): void
    {
        $consumed = $this->assistantDraft([
            'status' => AiDraftStatus::Consumed->value,
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('ai:expire-drafts')->assertSuccessful();

        $this->assertSame(AiDraftStatus::Consumed, $consumed->refresh()->status);
    }

    public function test_expires_legacy_expense_drafts_past_ttl(): void
    {
        $draft = AiExpenseDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->adminEmpresa->id,
            'status' => AiDraftStatus::Ready->value,
            'input_text' => 'algo',
        ]);
        // TTL heredado por defecto: 24h. Envejecemos el registro más allá de eso.
        DB::table('ai_expense_drafts')->where('id', $draft->id)->update([
            'created_at' => now()->subHours(48),
        ]);

        $this->artisan('ai:expire-drafts')->assertSuccessful();

        $this->assertSame(AiDraftStatus::Expired, $draft->refresh()->status);
    }
}
