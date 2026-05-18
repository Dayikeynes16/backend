<?php

namespace Tests\Feature\Ai;

use App\Enums\SaleStatus;
use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');
        RateLimiter::clear('ai-assistant:user:'.$this->adminEmpresa->id);
        RateLimiter::clear('ai-assistant:tenant:'.$this->tenant->id);
    }

    public function test_admin_empresa_can_view_asistente_page(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->get(route('empresa.asistente', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->component('Empresa/Asistente'));
    }

    public function test_admin_sucursal_cannot_view_empresa_asistente(): void
    {
        $this->actingAs($this->adminSucursal);

        $response = $this->get(route('empresa.asistente', $this->tenant->slug));

        $response->assertForbidden();
    }

    public function test_creating_a_session_redirects_back_to_asistente(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->post(route('empresa.asistente.sesiones.store', $this->tenant->slug));

        $session = AiAssistantSession::firstOrFail();
        $response->assertRedirect();
        $this->assertSame($this->adminEmpresa->id, $session->user_id);
        $this->assertSame($this->tenant->id, $session->tenant_id);
    }

    public function test_sending_a_message_persists_user_and_assistant_messages(): void
    {
        $session = $this->makeSession();
        Http::fake([
            '*/chat/completions' => Http::response($this->fakeFinalAssistantResponse('Hola, ¿en qué ayudo?')),
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => 'hola'],
        );

        $response->assertOk();
        $this->assertSame(2, AiAssistantMessage::count());
        $userMsg = AiAssistantMessage::where('role', 'user')->first();
        $this->assertSame('hola', $userMsg->content);
        $assistantMsg = AiAssistantMessage::where('role', 'assistant')->first();
        $this->assertSame('Hola, ¿en qué ayudo?', $assistantMsg->content);
        $this->assertSame('gpt-4o-mini', $assistantMsg->ai_model);
        $this->assertSame(50, $assistantMsg->prompt_tokens);
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
            route('empresa.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $strangerSession->id]),
            ['content' => 'fuga'],
        );

        $response->assertNotFound();
        $this->assertSame(0, AiAssistantMessage::count());
    }

    public function test_tool_call_runs_and_persists_card(): void
    {
        $session = $this->makeSession();
        Http::fake($this->sequenceToolThenFinal('consultar_ventas', [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => null,
        ], 'Total: $0.'));

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => '¿cuánto vendí hoy?'],
        );

        $response->assertOk()
            ->assertJsonPath('cards.0.kind', 'sales_summary')
            ->assertJsonPath('cards.0.tool_name', 'consultar_ventas');

        $toolMsg = AiAssistantMessage::where('role', 'tool')->firstOrFail();
        $this->assertSame('success', $toolMsg->tool_status);
        $this->assertSame('consultar_ventas', $toolMsg->tool_name);
    }

    public function test_unknown_tool_call_returns_error_without_executing(): void
    {
        $session = $this->makeSession();
        Http::fake($this->sequenceToolThenFinal('borrar_todo', [], 'No puedo hacer eso.'));

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => 'borra todo'],
        );

        $response->assertOk();
        $toolMsg = AiAssistantMessage::where('role', 'tool')->firstOrFail();
        $this->assertSame('error', $toolMsg->tool_status);
        $this->assertSame('unknown_tool', $toolMsg->error_code);
    }

    public function test_budget_exhausted_returns_402(): void
    {
        $session = $this->makeSession();
        $this->tenant->update(['ai_monthly_budget_cents' => 1]);

        AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'role' => 'assistant',
            'cost_cents' => 1,
        ]);

        Http::fake();
        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => 'hola'],
        );

        $response->assertStatus(402);
    }

    public function test_other_tenants_data_never_leaks_via_tool(): void
    {
        $session = $this->makeSession();

        // Crear venta en OTRO tenant — TenantScope debe bloquearla.
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $otherBranch = Branch::create(['tenant_id' => $otherTenant->id, 'name' => 'X', 'address' => 'X', 'status' => 'active']);
        $otherUser = User::create(['tenant_id' => $otherTenant->id, 'branch_id' => $otherBranch->id, 'name' => 'x', 'email' => 'x@x.test', 'password' => bcrypt('a')]);
        Sale::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'user_id' => $otherUser->id,
            'folio' => 'OTHER',
            'payment_method' => 'cash',
            'total' => 99999,
            'amount_paid' => 99999,
            'amount_pending' => 0,
            'origin' => 'admin',
            'status' => SaleStatus::Completed->value,
            'completed_at' => now(),
        ]);

        Http::fake($this->sequenceToolThenFinal('consultar_ventas', [
            'scope' => 'today',
            'date_from' => null,
            'date_to' => null,
            'branch_name' => null,
        ], 'Resumen.'));

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => '¿cuánto vendí hoy?'],
        );

        $response->assertOk();
        // El tool result no debe incluir el monto de la otra empresa.
        $card = $response->json('cards.0');
        $this->assertNotNull($card);
        $this->assertSame(0.0, (float) ($card['data']['net_sales'] ?? -1));
    }

    private function makeSession(): AiAssistantSession
    {
        return AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'message_count' => 0,
        ]);
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

    /**
     * Devuelve una secuencia que Http::fake reproducirá: primero el modelo pide
     * la tool, luego responde texto final.
     *
     * @param  array<string, mixed>  $params
     */
    private function sequenceToolThenFinal(string $toolName, array $params, string $finalText): array
    {
        return [
            '*/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'gpt-4o-mini',
                    'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20],
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_'.uniqid(),
                                'type' => 'function',
                                'function' => [
                                    'name' => $toolName,
                                    'arguments' => json_encode($params, JSON_UNESCAPED_UNICODE),
                                ],
                            ]],
                        ],
                    ]],
                ], 200)
                ->push($this->fakeFinalAssistantResponse($finalText), 200),
        ];
    }
}
