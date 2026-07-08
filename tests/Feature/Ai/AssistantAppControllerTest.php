<?php

namespace Tests\Feature\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AssistantDraftType;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\AssistantDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantAppControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');
        RateLimiter::clear('ai-assistant:user:'.$this->adminEmpresa->id);
        RateLimiter::clear('ai-assistant:user:'.$this->adminSucursal->id);
        RateLimiter::clear('ai-assistant:tenant:'.$this->tenant->id);
    }

    public function test_admin_empresa_can_view_mini_app(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->get(route('asistente.index', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->component('Asistente/App'));
    }

    public function test_admin_sucursal_can_view_mini_app(): void
    {
        $this->actingAs($this->adminSucursal);

        $response = $this->get(route('asistente.index', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->component('Asistente/App'));
    }

    public function test_cajero_can_view_mini_app(): void
    {
        // F5 (D5): el cajero entra con toolset operativo de caja.
        $this->actingAs($this->cajero);

        $response = $this->get(route('asistente.index', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->component('Asistente/App'));
    }

    public function test_index_auto_creates_first_session(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->get(route('asistente.index', $this->tenant->slug));

        $response->assertOk();
        $this->assertSame(1, AiAssistantSession::count());
        $session = AiAssistantSession::firstOrFail();
        $this->assertSame($this->adminEmpresa->id, $session->user_id);
        $response->assertInertia(fn ($p) => $p->where('activeSessionId', $session->id));
    }

    public function test_index_does_not_duplicate_sessions(): void
    {
        AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'message_count' => 0,
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->get(route('asistente.index', $this->tenant->slug))->assertOk();

        $this->assertSame(1, AiAssistantSession::count());
    }

    public function test_reloaded_draft_cards_reflect_current_draft_status(): void
    {
        $session = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'message_count' => 0,
        ]);
        $userMsg = AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'role' => 'user',
            'content' => 'registra un gasto',
        ]);
        $draft = AssistantDraft::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => null,
            'user_id' => $this->adminEmpresa->id,
            'session_id' => $session->id,
            'message_id' => $userMsg->id,
            'type' => AssistantDraftType::Expense->value,
            'status' => AiDraftStatus::Consumed->value,
            'payload' => [],
            'expires_at' => now()->addHours(6),
        ]);
        // Card congelada con status "ready" en el momento de prepararse.
        AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'role' => 'tool',
            'tool_name' => 'preparar_borrador_gasto',
            'tool_status' => 'success',
            'tool_result' => ['draft_id' => $draft->id, 'draft_type' => 'expense', 'status' => 'ready'],
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('asistente.index', ['tenant' => $this->tenant->slug, 'session' => $session->id]));

        // Al recargar, la card debe reflejar el estado ACTUAL (consumed), no el congelado.
        $response->assertInertia(fn ($page) => $page
            ->where('messages.1.tool_result.status', 'consumed'));
    }

    public function test_creating_a_session_redirects_to_mini_app(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->post(route('asistente.sesiones.store', $this->tenant->slug));

        $session = AiAssistantSession::firstOrFail();
        $response->assertRedirect(route('asistente.index', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
        ]));
        $this->assertSame($this->adminEmpresa->id, $session->user_id);
    }

    public function test_sending_a_message_works_via_neutral_route(): void
    {
        $session = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'message_count' => 0,
        ]);
        Http::fake([
            '*/chat/completions' => Http::response($this->fakeFinalAssistantResponse('Hola, ¿en qué ayudo?')),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => 'hola'],
        );

        $response->assertOk();
        $this->assertSame(2, AiAssistantMessage::count());
    }

    public function test_user_cannot_post_to_another_users_session(): void
    {
        $strangerSession = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminSucursal->id,
            'message_count' => 0,
        ]);
        Http::fake();

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $strangerSession->id]),
            ['content' => 'fuga'],
        );

        $response->assertNotFound();
        $this->assertSame(0, AiAssistantMessage::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeFinalAssistantResponse(string $text): array
    {
        return [
            'model' => 'gpt-4o-mini',
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $text],
            ]],
        ];
    }
}
