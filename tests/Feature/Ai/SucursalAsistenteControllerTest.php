<?php

namespace Tests\Feature\Ai;

use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class SucursalAsistenteControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');
        RateLimiter::clear('ai-assistant:user:'.$this->adminSucursal->id);
        RateLimiter::clear('ai-assistant:tenant:'.$this->tenant->id);
    }

    public function test_admin_sucursal_can_view_asistente_page(): void
    {
        $this->actingAs($this->adminSucursal);

        $response = $this->get(route('sucursal.asistente', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->component('Sucursal/Asistente'));
    }

    public function test_admin_empresa_cannot_view_sucursal_asistente(): void
    {
        $this->actingAs($this->adminEmpresa);

        $response = $this->get(route('sucursal.asistente', $this->tenant->slug));

        $response->assertForbidden();
    }

    public function test_cajero_cannot_view_sucursal_asistente(): void
    {
        $this->actingAs($this->cajero);

        $response = $this->get(route('sucursal.asistente', $this->tenant->slug));

        $response->assertForbidden();
    }

    public function test_admin_sucursal_can_create_session_and_send_message(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 8],
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Hola.'],
                ]],
            ]),
        ]);

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.asistente.sesiones.store', $this->tenant->slug));
        $session = AiAssistantSession::firstOrFail();

        $response = $this->postJson(
            route('sucursal.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => 'hola'],
        );

        $response->assertOk();
        $this->assertSame($this->adminSucursal->id, $session->user_id);
        $this->assertSame(2, AiAssistantMessage::count());
    }

    public function test_admin_sucursal_cannot_open_other_users_session_even_same_tenant(): void
    {
        $strangerSession = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'message_count' => 0,
        ]);
        Http::fake();

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(
            route('sucursal.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $strangerSession->id]),
            ['content' => 'fuga intersesión'],
        );

        $response->assertNotFound();
        $this->assertSame(0, AiAssistantMessage::count());
    }

    public function test_admin_sucursal_tool_call_is_scoped_to_own_branch_even_when_asking_for_other(): void
    {
        // Una venta en mi sucursal, otra grande en la otra sucursal.
        $this->makeCompletedSale([
            'branch_id' => $this->branch->id,
            'total' => 500,
            'amount_paid' => 500,
            'completed_at' => now(),
        ]);
        $this->makeCompletedSale([
            'branch_id' => $this->secondBranch->id,
            'total' => 99999,
            'amount_paid' => 99999,
            'completed_at' => now(),
        ]);

        $session = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminSucursal->id,
            'message_count' => 0,
        ]);

        // El modelo intenta pedir explícitamente la OTRA sucursal — debe ignorarse.
        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push([
                    'model' => 'gpt-4o-mini',
                    'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20],
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_x',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'consultar_ventas',
                                    'arguments' => json_encode([
                                        'scope' => 'today',
                                        'date_from' => null,
                                        'date_to' => null,
                                        'branch_name' => $this->secondBranch->name,
                                    ], JSON_UNESCAPED_UNICODE),
                                ],
                            ]],
                        ],
                    ]],
                ], 200)
                ->push([
                    'model' => 'gpt-4o-mini',
                    'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5],
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'Listo.'],
                    ]],
                ], 200),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(
            route('sucursal.asistente.mensajes.store', ['tenant' => $this->tenant->slug, 'session' => $session->id]),
            ['content' => 'cuánto vendió la sucursal 2 hoy'],
        );

        $response->assertOk();
        $card = $response->json('cards.0');
        $this->assertNotNull($card, 'Debe regresar una card de ventas.');
        // Debe haber tomado SOLO la venta de su sucursal (500), no la de la otra (99999).
        $this->assertSame(500.0, (float) $card['data']['net_sales']);
        $this->assertSame(1, (int) $card['data']['ticket_count']);
        // branch_name del card debe reflejar la sucursal propia.
        $this->assertSame($this->branch->name, $card['data']['branch_name']);
    }

    public function test_sucursal_sessions_dont_appear_in_empresa_view(): void
    {
        $sucursalSession = AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminSucursal->id,
            'title' => 'Solo mía',
            'message_count' => 0,
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->get(route('empresa.asistente', $this->tenant->slug));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p->where(
            'sessions',
            fn ($sessions) => collect($sessions)->every(fn ($s) => $s['id'] !== $sucursalSession->id),
        ));
    }
}
