<?php

namespace Tests\Feature\Agenda;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaAiDraftTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');
    }

    public function test_endpoint_returns_normalized_proposal_and_persists_nothing(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 40],
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'type' => 'task',
                            'title' => 'Entregar carne',
                            'body' => 'Pedido para el cliente',
                            'scope' => 'personal',
                            'starts_at' => now()->addDay()->setTime(14, 0)->toIso8601String(),
                            'remind_at' => now()->addDay()->setTime(13, 30)->toIso8601String(),
                            'recurrence' => 'none',
                            'priority' => 'high',
                            'confianza' => 'alta',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(route('agenda.ia.store', $this->tenant->slug), [
            'input_text' => 'recuérdame entregar carne a las 2pm mañana',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.type', 'task')
            ->assertJsonPath('proposal.title', 'Entregar carne')
            ->assertJsonPath('proposal.scope', 'personal')
            ->assertJsonPath('proposal.recurrence', 'none')
            ->assertJsonPath('proposal.priority', 'high')
            ->assertJsonPath('proposal.confianza', 'alta');

        // No se debe persistir NADA: la propuesta es sólo un borrador en memoria.
        $this->assertDatabaseCount('agenda_items', 0);

        // La propuesta nunca debe traer un asignado (la asignación es manual).
        $this->assertArrayNotHasKey('assigned_to_user_id', $response->json('proposal'));
    }

    public function test_endpoint_clamps_invalid_enums_to_safe_defaults(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'gpt-4o',
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'type' => 'meeting',          // inválido → task
                            'title' => 'Algo',
                            'scope' => 'galaxy',           // inválido → personal
                            'recurrence' => 'yearly',      // inválido → none
                            'priority' => 'urgent',        // inválido → null
                            'confianza' => 'altisima',     // inválido → baja
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(route('agenda.ia.store', $this->tenant->slug), [
            'input_text' => 'algo',
        ]);

        $response->assertOk()
            ->assertJsonPath('proposal.type', 'task')
            ->assertJsonPath('proposal.scope', 'personal')
            ->assertJsonPath('proposal.recurrence', 'none')
            ->assertJsonPath('proposal.priority', null)
            ->assertJsonPath('proposal.confianza', 'baja');

        $this->assertDatabaseCount('agenda_items', 0);
    }

    public function test_priority_is_dropped_for_non_task_types(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'type' => 'event',
                            'title' => 'Junta con proveedor',
                            'priority' => 'high', // debe ignorarse para event
                            'confianza' => 'media',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminSucursal);
        $this->postJson(route('agenda.ia.store', $this->tenant->slug), [
            'input_text' => 'junta con el proveedor el viernes',
        ])->assertOk()
            ->assertJsonPath('proposal.type', 'event')
            ->assertJsonPath('proposal.priority', null);
    }

    public function test_audio_is_transcribed_then_parsed(): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::response([
                'text' => 'recuérdame pagar al proveedor mañana',
            ], 200),
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'type' => 'task',
                            'title' => 'Pagar al proveedor',
                            'confianza' => 'alta',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(route('agenda.ia.store', $this->tenant->slug), [
            'audio' => UploadedFile::fake()->createWithContent('voice.webm', str_repeat('a', 1024)),
        ]);

        $response->assertOk()
            ->assertJsonPath('transcription', 'recuérdame pagar al proveedor mañana')
            ->assertJsonPath('proposal.title', 'Pagar al proveedor');

        Http::assertSentInOrder([
            fn ($req) => str_contains($req->url(), '/audio/transcriptions'),
            fn ($req) => str_contains($req->url(), '/chat/completions'),
        ]);

        $this->assertDatabaseCount('agenda_items', 0);
    }

    public function test_endpoint_requires_text_or_audio(): void
    {
        $this->actingAs($this->adminSucursal);
        $this->postJson(route('agenda.ia.store', $this->tenant->slug), [])
            ->assertStatus(422);
    }

    public function test_openai_failure_returns_502_and_persists_nothing(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'down'], 500),
        ]);

        $this->actingAs($this->adminSucursal);
        $this->postJson(route('agenda.ia.store', $this->tenant->slug), [
            'input_text' => 'algo',
        ])->assertStatus(502);

        $this->assertDatabaseCount('agenda_items', 0);
    }
}
