<?php

namespace Tests\Feature\Ai;

use App\Models\AiAssistantMessage;
use App\Models\AiAssistantSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantSpeakTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.elevenlabs.api_key', 'sk_test');
        config()->set('ai.elevenlabs.voice_id', 'voice_abc');
        RateLimiter::clear('ai-assistant-speak:user:'.$this->adminEmpresa->id);
        RateLimiter::clear('ai-assistant-speak:user:'.$this->adminSucursal->id);
        RateLimiter::clear('ai-assistant-speak:user:'.$this->cajero->id);
    }

    public function test_admin_empresa_can_synthesize_own_assistant_message(): void
    {
        Http::fake([
            '*/text-to-speech/*' => Http::response('FAKE_MP3_BYTES', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $session = $this->makeSession($this->adminEmpresa->id);
        $message = $this->makeAssistantMessage($session, 'Hola, ¿en qué ayudo?');

        $this->actingAs($this->adminEmpresa);
        $response = $this->post(route('empresa.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
            'message' => $message->id,
        ]));

        $response->assertOk();
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame('FAKE_MP3_BYTES', $response->streamedContent());

        Http::assertSent(fn ($req) => str_contains($req->url(), '/text-to-speech/voice_abc'));
    }

    public function test_admin_sucursal_can_synthesize_own_assistant_message(): void
    {
        Http::fake([
            '*/text-to-speech/*' => Http::response('SUCURSAL_MP3', 200),
        ]);

        $session = $this->makeSession($this->adminSucursal->id);
        $message = $this->makeAssistantMessage($session, 'Vendiste $500 hoy.');

        $this->actingAs($this->adminSucursal);
        $response = $this->post(route('sucursal.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
            'message' => $message->id,
        ]));

        $response->assertOk();
        $this->assertSame('SUCURSAL_MP3', $response->streamedContent());
    }

    public function test_user_cannot_speak_message_from_another_users_session(): void
    {
        $strangerSession = $this->makeSession($this->adminSucursal->id);
        $strangerMessage = $this->makeAssistantMessage($strangerSession, 'datos privados');

        Http::fake();
        $this->actingAs($this->adminEmpresa);
        $response = $this->post(route('empresa.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $strangerSession->id,
            'message' => $strangerMessage->id,
        ]));

        $response->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_speak_rejects_user_message(): void
    {
        Http::fake();
        $session = $this->makeSession($this->adminEmpresa->id);
        $userMsg = AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminEmpresa->id,
            'role' => 'user',
            'content' => 'pregunta',
        ]);

        $this->actingAs($this->adminEmpresa);
        $response = $this->post(route('empresa.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
            'message' => $userMsg->id,
        ]));

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_speak_rejects_message_from_different_session(): void
    {
        Http::fake();
        $sessionA = $this->makeSession($this->adminEmpresa->id);
        $sessionB = $this->makeSession($this->adminEmpresa->id);
        $messageB = $this->makeAssistantMessage($sessionB, 'distinto');

        $this->actingAs($this->adminEmpresa);
        // Pedir el mensaje de sesión B usando ID de sesión A.
        $response = $this->post(route('empresa.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $sessionA->id,
            'message' => $messageB->id,
        ]));

        $response->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_speak_returns_502_when_elevenlabs_fails(): void
    {
        Http::fake([
            '*/text-to-speech/*' => Http::response(['detail' => 'voice_not_found'], 404),
        ]);

        $session = $this->makeSession($this->adminEmpresa->id);
        $message = $this->makeAssistantMessage($session, 'algo');

        $this->actingAs($this->adminEmpresa);
        $response = $this->post(route('empresa.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
            'message' => $message->id,
        ]));

        $response->assertStatus(502);
    }

    public function test_speak_rate_limit(): void
    {
        config()->set('ai.assistant.rate_limit_per_user_per_hour', 2);
        Http::fake([
            '*/text-to-speech/*' => Http::response('ok', 200),
        ]);

        $session = $this->makeSession($this->adminEmpresa->id);
        $message = $this->makeAssistantMessage($session, 'algo');

        $this->actingAs($this->adminEmpresa);
        $url = route('empresa.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
            'message' => $message->id,
        ]);
        $this->post($url)->assertOk();
        $this->post($url)->assertOk();
        $this->post($url)->assertStatus(429);
    }

    public function test_cajero_cannot_speak_in_empresa_or_sucursal(): void
    {
        $session = $this->makeSession($this->adminSucursal->id);
        $message = $this->makeAssistantMessage($session, 'x');

        $this->actingAs($this->cajero);
        $this->post(route('empresa.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
            'message' => $message->id,
        ]))->assertForbidden();
        $this->post(route('sucursal.asistente.mensajes.voz', [
            'tenant' => $this->tenant->slug,
            'session' => $session->id,
            'message' => $message->id,
        ]))->assertForbidden();
    }

    private function makeSession(int $userId): AiAssistantSession
    {
        return AiAssistantSession::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $userId,
            'message_count' => 0,
        ]);
    }

    private function makeAssistantMessage(AiAssistantSession $session, string $content): AiAssistantMessage
    {
        return AiAssistantMessage::create([
            'session_id' => $session->id,
            'tenant_id' => $this->tenant->id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => $content,
            'ai_model' => 'gpt-4o-mini',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ]);
    }
}
