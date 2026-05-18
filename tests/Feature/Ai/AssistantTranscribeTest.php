<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AssistantTranscribeTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
        config()->set('ai.openai.api_key', 'sk-test');
        RateLimiter::clear('ai-assistant-transcribe:user:'.$this->adminEmpresa->id);
        RateLimiter::clear('ai-assistant-transcribe:user:'.$this->adminSucursal->id);
        RateLimiter::clear('ai-assistant-transcribe:user:'.$this->cajero->id);
    }

    public function test_empresa_transcribe_calls_whisper_and_returns_text(): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::response([
                'text' => 'Cuánto vendí hoy en la sucursal centro',
            ], 200),
        ]);

        $audio = UploadedFile::fake()->createWithContent('audio.webm', str_repeat('a', 1024));

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.transcribir', $this->tenant->slug),
            ['audio' => $audio],
        );

        $response->assertOk()
            ->assertJsonPath('text', 'Cuánto vendí hoy en la sucursal centro');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/audio/transcriptions'));
    }

    public function test_sucursal_transcribe_works_for_admin_sucursal(): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::response(['text' => 'Top productos esta semana'], 200),
        ]);

        $audio = UploadedFile::fake()->createWithContent('audio.webm', str_repeat('a', 1024));

        $this->actingAs($this->adminSucursal);
        $response = $this->postJson(
            route('sucursal.asistente.transcribir', $this->tenant->slug),
            ['audio' => $audio],
        );

        $response->assertOk()->assertJsonPath('text', 'Top productos esta semana');
    }

    public function test_cajero_cannot_transcribe_in_empresa_or_sucursal(): void
    {
        $audio = UploadedFile::fake()->createWithContent('audio.webm', str_repeat('a', 1024));

        $this->actingAs($this->cajero);
        $this->postJson(route('empresa.asistente.transcribir', $this->tenant->slug), ['audio' => $audio])
            ->assertForbidden();
        $this->postJson(route('sucursal.asistente.transcribir', $this->tenant->slug), ['audio' => $audio])
            ->assertForbidden();
    }

    public function test_admin_empresa_cannot_use_sucursal_transcribe(): void
    {
        $audio = UploadedFile::fake()->createWithContent('audio.webm', str_repeat('a', 1024));

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('sucursal.asistente.transcribir', $this->tenant->slug), ['audio' => $audio])
            ->assertForbidden();
    }

    public function test_transcribe_rejects_unsupported_audio_format(): void
    {
        $audio = UploadedFile::fake()->create('audio.txt', 5, 'text/plain');

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.transcribir', $this->tenant->slug),
            ['audio' => $audio],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['audio']);
    }

    public function test_transcribe_requires_audio_file(): void
    {
        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.transcribir', $this->tenant->slug),
            [],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['audio']);
    }

    public function test_transcribe_returns_502_when_whisper_fails(): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::response(['error' => 'invalid request'], 400),
        ]);

        $audio = UploadedFile::fake()->createWithContent('audio.webm', str_repeat('a', 1024));

        $this->actingAs($this->adminEmpresa);
        $response = $this->postJson(
            route('empresa.asistente.transcribir', $this->tenant->slug),
            ['audio' => $audio],
        );

        $response->assertStatus(502);
    }

    public function test_transcribe_rate_limit_per_user(): void
    {
        config()->set('ai.assistant.rate_limit_per_user_per_hour', 2);

        Http::fake([
            '*/audio/transcriptions' => Http::response(['text' => 'ok'], 200),
        ]);

        $audio = UploadedFile::fake()->createWithContent('audio.webm', str_repeat('a', 1024));

        $this->actingAs($this->adminEmpresa);
        $this->postJson(route('empresa.asistente.transcribir', $this->tenant->slug), ['audio' => $audio])
            ->assertOk();
        $this->postJson(route('empresa.asistente.transcribir', $this->tenant->slug), ['audio' => $audio])
            ->assertOk();
        $this->postJson(route('empresa.asistente.transcribir', $this->tenant->slug), ['audio' => $audio])
            ->assertStatus(429);
    }
}
