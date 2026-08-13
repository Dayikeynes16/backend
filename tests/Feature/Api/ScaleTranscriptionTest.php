<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Dictado de la báscula: audio → texto. La báscula se autentica con X-Api-Key,
 * así que no puede usar el endpoint del asistente (sesión web).
 */
class ScaleTranscriptionTest extends TestCase
{
    use RefreshDatabase;

    private string $rawKey;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-tenant', 'status' => 'active']);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sucursal 1',
            'address' => 'A',
            'status' => 'active',
        ]);

        $this->rawKey = 'csa_test_'.str_repeat('x', 20);
        $this->apiKey = ApiKey::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'bascula de prueba',
            'key_hash' => hash('sha256', $this->rawKey),
            'last_used_at' => null,
        ]);
    }

    private function fakeAudio(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('nota.m4a', str_repeat('a', 1024));
    }

    /**
     * `AssistantTranscriber` es `final`, así que no se puede doblar con Mockery.
     * Se finge la respuesta de Whisper a nivel HTTP, como el resto de tests de
     * voz del proyecto — de paso se ejercita el transcriptor de verdad.
     */
    private function fakeWhisper(string $returns = 'Doña Mary'): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['text' => $returns], 200)]);
    }

    public function test_devuelve_el_texto_transcrito(): void
    {
        $this->fakeWhisper('Doña Mary');

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])
            ->assertOk()
            ->assertJsonPath('text', 'Doña Mary');
    }

    public function test_recorta_los_espacios_del_texto(): void
    {
        $this->fakeWhisper("  Doña Mary \n");

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])
            ->assertOk()
            ->assertJsonPath('text', 'Doña Mary');
    }

    public function test_sin_api_key_es_rechazado(): void
    {
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()])
            ->assertUnauthorized();
    }

    public function test_exige_el_audio(): void
    {
        $this->postJson('/api/v1/transcribe', [], ['X-Api-Key' => $this->rawKey])
            ->assertStatus(422)
            ->assertJsonValidationErrors('audio');
    }

    public function test_rechaza_un_formato_no_soportado(): void
    {
        $this->post(
            '/api/v1/transcribe',
            ['audio' => UploadedFile::fake()->create('nota.txt', 10, 'text/plain')],
            ['X-Api-Key' => $this->rawKey, 'Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrors('audio');
    }

    public function test_limita_los_dictados_por_hora_y_por_api_key(): void
    {
        config(['ai.scale.transcribe_per_hour' => 2]);
        $this->fakeWhisper();

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])->assertOk();
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])->assertOk();
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])
            ->assertStatus(429);
    }

    /** Dos básculas de la misma sucursal no se comen la cuota entre sí. */
    public function test_el_limite_es_por_api_key_no_global(): void
    {
        config(['ai.scale.transcribe_per_hour' => 1]);
        $this->fakeWhisper();

        $otraKey = 'csa_test_'.str_repeat('y', 20);
        $otra = ApiKey::create([
            'tenant_id' => $this->apiKey->tenant_id,
            'branch_id' => $this->apiKey->branch_id,
            'name' => 'segunda bascula',
            'key_hash' => hash('sha256', $otraKey),
            'last_used_at' => null,
        ]);

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])->assertOk();
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey])->assertStatus(429);

        // La otra báscula conserva su cuota.
        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $otraKey])->assertOk();

        RateLimiter::clear('scale-transcribe:api-key:'.$otra->id);
    }

    public function test_si_whisper_falla_responde_503_y_no_revienta(): void
    {
        Http::fake(['*/audio/transcriptions' => Http::response(['error' => 'server error'], 500)]);

        $this->post('/api/v1/transcribe', ['audio' => $this->fakeAudio()], ['X-Api-Key' => $this->rawKey, 'Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonStructure(['message']);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('scale-transcribe:api-key:'.$this->apiKey->id);
        parent::tearDown();
    }
}
