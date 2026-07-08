<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\CashRegisterShift;
use App\Models\CashWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantCashWithdrawalConfirmTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function openShift(User $user): CashRegisterShift
    {
        return CashRegisterShift::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'opened_at' => now()->subHour(),
            'opening_amount' => 1000,
        ]);
    }

    private function makeDraft(User $user): AssistantDraft
    {
        $session = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'message_count' => 0,
        ]);
        $msg = AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => 'retira 500',
        ]);

        return AssistantDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::CashWithdrawal->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['amount' => 500],
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function confirmUrl(AssistantDraft $draft): string
    {
        return route('asistente.drafts.confirm', ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_cajero_confirms_withdrawal_on_own_shift(): void
    {
        $shift = $this->openShift($this->cajero);
        $draft = $this->makeDraft($this->cajero);

        $this->actingAs($this->cajero)
            ->postJson($this->confirmUrl($draft), ['amount' => 500, 'reason' => 'Gasolina'])
            ->assertOk();

        $withdrawal = CashWithdrawal::firstOrFail();
        $this->assertSame($shift->id, $withdrawal->shift_id);
        $this->assertSame($this->cajero->id, $withdrawal->user_id);
        $this->assertEqualsWithDelta(500.0, (float) $withdrawal->amount, 0.001);
        $this->assertSame('Gasolina', $withdrawal->reason);

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
    }

    public function test_requires_open_shift(): void
    {
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), ['amount' => 500, 'reason' => 'Gasolina'])
            ->assertForbidden();

        $this->assertSame(0, CashWithdrawal::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_admin_empresa_cannot_confirm_withdrawals(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), ['amount' => 500, 'reason' => 'Gasolina'])
            ->assertForbidden();

        $this->assertSame(0, CashWithdrawal::count());
    }

    public function test_draft_is_single_use(): void
    {
        $this->openShift($this->cajero);
        $draft = $this->makeDraft($this->cajero);

        $this->actingAs($this->cajero)
            ->postJson($this->confirmUrl($draft), ['amount' => 500, 'reason' => 'Gasolina'])
            ->assertOk();

        $this->actingAs($this->cajero)
            ->postJson($this->confirmUrl($draft), ['amount' => 500, 'reason' => 'Gasolina'])
            ->assertStatus(409);

        $this->assertSame(1, CashWithdrawal::count());
    }
}
