<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Regresión: el middleware AuthenticateApiKey debe registrar last_used_at
 * en cada petición autenticada. last_used_at no es fillable (se escribe
 * con forceFill), por lo que un update() masivo lo descartaría en silencio.
 */
class ApiKeyLastUsedTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    private string $rawKey;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();

        $this->rawKey = 'csa_test_'.bin2hex(random_bytes(8));
        $this->apiKey = ApiKey::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'name' => 'Báscula test',
            'key_hash' => hash('sha256', $this->rawKey),
        ]);
    }

    public function test_authenticated_request_updates_last_used_at(): void
    {
        $this->assertNull($this->apiKey->last_used_at);

        Carbon::setTestNow('2026-07-15 10:30:00');

        $this->getJson('/api/v1/branches/me', ['X-Api-Key' => $this->rawKey])
            ->assertOk();

        $this->assertTrue(
            $this->apiKey->fresh()->last_used_at?->eq('2026-07-15 10:30:00'),
            'last_used_at debe quedar registrado tras una petición autenticada.'
        );
    }

    public function test_rejected_request_does_not_update_last_used_at(): void
    {
        $this->getJson('/api/v1/branches/me', ['X-Api-Key' => 'csa_wrong_key'])
            ->assertUnauthorized();

        $this->assertNull($this->apiKey->fresh()->last_used_at);
    }
}
