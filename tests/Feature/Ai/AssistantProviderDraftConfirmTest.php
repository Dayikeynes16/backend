<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantProviderDraftConfirmTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeDraft(User $user, array $attrs = []): AssistantDraft
    {
        app()->instance('tenant', $this->tenant);

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
            'content' => 'agrega proveedor',
        ]);

        return AssistantDraft::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
            'session_id' => $session->id,
            'message_id' => $msg->id,
            'type' => AssistantDraftType::Provider->value,
            'status' => AiDraftStatus::Ready->value,
            'payload' => ['name' => 'Proveedor X', 'type' => 'insumos'],
            'expires_at' => now()->addHours(6),
        ], $attrs));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Distribuidora La Unión',
            'type' => 'insumos',
            'phone' => '5512345678',
            'email' => null,
            'rfc' => null,
            'address' => null,
            'notes' => null,
        ], $over);
    }

    private function confirmUrl(string $prefix, AssistantDraft $draft): string
    {
        return route("{$prefix}.asistente.drafts.confirm", ['tenant' => $this->tenant->slug, 'draft' => $draft->id]);
    }

    public function test_confirm_creates_provider_and_consumes_draft(): void
    {
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload())
            ->assertOk();

        $this->assertSame(1, Provider::count());
        $provider = Provider::first();
        $this->assertSame('Distribuidora La Unión', $provider->name);
        $this->assertSame('active', $provider->status);
        $this->assertSame($this->adminEmpresa->id, $provider->created_by);

        $draft->refresh();
        $this->assertSame(AiDraftStatus::Consumed, $draft->status);
        $this->assertSame($provider->id, $draft->result_id);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        Provider::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Distribuidora La Unión',
            'type' => 'insumos',
            'status' => 'active',
        ]);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminEmpresa)
            ->postJson($this->confirmUrl('empresa', $draft), $this->payload())
            ->assertStatus(422);

        $this->assertSame(1, Provider::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_admin_sucursal_without_feature_cannot_confirm(): void
    {
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, Provider::count());
        $this->assertSame(AiDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_admin_sucursal_with_feature_can_confirm(): void
    {
        $this->branch->update(['branch_admin_providers_enabled' => true]);
        $draft = $this->makeDraft($this->adminSucursal);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload())
            ->assertOk();

        $this->assertSame(1, Provider::count());
    }

    public function test_user_cannot_confirm_another_users_draft(): void
    {
        $this->branch->update(['branch_admin_providers_enabled' => true]);
        $draft = $this->makeDraft($this->adminEmpresa);

        $this->actingAs($this->adminSucursal)
            ->postJson($this->confirmUrl('sucursal', $draft), $this->payload())
            ->assertNotFound();

        $this->assertSame(0, Provider::count());
    }
}
