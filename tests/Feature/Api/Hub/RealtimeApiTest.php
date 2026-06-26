<?php

namespace Tests\Feature\Api\Hub;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class RealtimeApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        // phpunit fuerza BROADCAST_CONNECTION=null, así que los canales de
        // routes/channels.php quedan registrados en el broadcaster null del
        // arranque. Cambiamos el driver por defecto a Reverb (pusher, para una
        // firma de auth real) y RE-registramos los canales sobre él.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'carniceria_key',
            'broadcasting.connections.reverb.secret' => 'carniceria_secret',
            'broadcasting.connections.reverb.app_id' => 'carniceria',
            'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
        ]);
        require base_path('routes/channels.php');
    }

    private function token(): string
    {
        return $this->cajero->createToken('hub')->plainTextToken;
    }

    public function test_realtime_config_returns_reverb_params(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/v1/hub/realtime/config')
            ->assertOk()
            ->assertJson(['key' => 'carniceria_key', 'host' => 'localhost', 'port' => 8080, 'scheme' => 'http']);
    }

    public function test_realtime_auth_authorizes_own_branch_channel(): void
    {
        Sanctum::actingAs($this->cajero);

        $this->postJson('/api/v1/hub/realtime/auth', [
            'channel_name' => "private-sucursal.{$this->cajero->branch_id}",
            'socket_id' => '1234.5678',
        ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_realtime_auth_rejects_other_branch_channel(): void
    {
        $otherBranch = Branch::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Otra sucursal',
            'address' => 'B',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->cajero);

        $this->postJson('/api/v1/hub/realtime/auth', [
            'channel_name' => "private-sucursal.{$otherBranch->id}",
            'socket_id' => '1234.5678',
        ])
            ->assertForbidden();
    }

    public function test_realtime_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/hub/realtime/config')->assertUnauthorized();
    }
}
