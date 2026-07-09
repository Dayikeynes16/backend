<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantCustomerDraftConfirmTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeDraft(User $user): AssistantDraft
    {
        $session = AiAssistantSession::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'message_count' => 0]);
        $msg = AiAssistantMessage::create(['session_id' => $session->id, 'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'user', 'content' => 'agrega cliente']);

        return AssistantDraft::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $user->branch_id, 'user_id' => $user->id,
            'session_id' => $session->id, 'message_id' => $msg->id,
            'type' => AssistantDraftType::Customer->value, 'status' => AiDraftStatus::Ready->value,
            'payload' => [], 'expires_at' => now()->addHours(6),
        ]);
    }

    private function confirmUrl(AssistantDraft $draft): string
    {
        return route('asistente.drafts.confirm', ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_cajero_creates_customer_with_forced_branch(): void
    {
        $draft = $this->makeDraft($this->cajero);

        $this->actingAs($this->cajero)
            ->postJson($this->confirmUrl($draft), ['name' => 'Cachorro', 'phone' => '9933058731', 'notes' => null, 'branch_id' => $this->secondBranch->id])
            ->assertOk();

        $c = Customer::firstOrFail();
        $this->assertSame('Cachorro', $c->name);
        $this->assertSame($this->branch->id, $c->branch_id); // forzado, ignora el payload
        $this->assertSame(AiDraftStatus::Consumed, $draft->refresh()->status);
    }

    public function test_admin_empresa_must_choose_branch(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), ['name' => 'Cachorro', 'phone' => null, 'notes' => null])
            ->assertStatus(422);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl($draft), ['name' => 'Cachorro', 'phone' => null, 'notes' => null, 'branch_id' => $this->secondBranch->id])
            ->assertOk();

        $this->assertSame($this->secondBranch->id, Customer::firstOrFail()->branch_id);
    }

    public function test_name_is_required(): void
    {
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl($draft), ['name' => null, 'phone' => null, 'notes' => null])
            ->assertStatus(422);

        $this->assertSame(0, Customer::count());
    }
}
