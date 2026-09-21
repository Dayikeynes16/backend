<?php

namespace Tests\Feature\Hub;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La llave de un equipo.
 *
 * Nace de un problema real: un hub instalado donde sólo hay cajeros no
 * conseguía API key nunca —crearlas era de admin-sucursal—, se quedaba sin
 * catálogo, y las básculas que emparejaba no recibían productos. Atar la llave
 * a un equipo concreto permite que la pida un cajero sin abrir la puerta a
 * fabricar credenciales sueltas.
 */
class DeviceApiKeyTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_a_cashier_can_get_the_key_of_a_device(): void
    {
        Sanctum::actingAs($this->cajero);

        $response = $this->postJson('/api/v1/hub/devices/hub-honor-1/api-key', ['name' => 'Hub Estrellas'])
            ->assertCreated();

        $this->assertStringStartsWith('csa_', $response->json('raw_key'));
        $this->assertFalse($response->json('rotated'));

        $key = ApiKey::withoutGlobalScopes()->first();
        $this->assertSame('hub-honor-1', $key->device_id);
        $this->assertSame($this->branch->id, $key->branch_id);
        // Se guarda el hash, nunca la llave.
        $this->assertSame(hash('sha256', $response->json('raw_key')), $key->key_hash);
    }

    public function test_but_a_cashier_still_cannot_create_loose_keys(): void
    {
        // Una API key vende sin sesión y no caduca: crear llaves sueltas sigue
        // siendo del administrador. Lo que se abrió es sólo la de un equipo.
        Sanctum::actingAs($this->cajero);

        $this->postJson('/api/v1/hub/config/api-keys', ['name' => 'Suelta'])->assertForbidden();
    }

    public function test_asking_twice_rotates_instead_of_piling_up_keys(): void
    {
        // Pedirla en cada arranque llenaría la sucursal de llaves huérfanas. Y
        // si la báscula se perdió y alguien la reinstala, la vieja tiene que
        // dejar de servir en ese mismo momento.
        Sanctum::actingAs($this->cajero);

        $primera = $this->postJson('/api/v1/hub/devices/balanza-1/api-key', ['name' => 'Balanza 1'])
            ->assertCreated()->json('raw_key');

        $segunda = $this->postJson('/api/v1/hub/devices/balanza-1/api-key', ['name' => 'Balanza 1'])
            ->assertCreated();

        $this->assertTrue($segunda->json('rotated'));
        $this->assertNotSame($primera, $segunda->json('raw_key'));
        // Una sola llave viva para ese equipo, y es la nueva.
        $vivas = ApiKey::withoutGlobalScopes()->where('device_id', 'balanza-1')->get();
        $this->assertCount(1, $vivas);
        $this->assertSame(hash('sha256', $segunda->json('raw_key')), $vivas->first()->key_hash);
    }

    public function test_each_device_keeps_its_own_key(): void
    {
        // Es la razón de ser del cambio: perder una tablet ya no obliga a
        // revocar la llave de todas las demás.
        Sanctum::actingAs($this->cajero);

        $this->postJson('/api/v1/hub/devices/balanza-1/api-key', ['name' => 'Balanza 1'])->assertCreated();
        $this->postJson('/api/v1/hub/devices/balanza-2/api-key', ['name' => 'Balanza 2'])->assertCreated();

        $this->assertSame(2, ApiKey::withoutGlobalScopes()->count());

        // Revocar una no toca a la otra.
        ApiKey::withoutGlobalScopes()->where('device_id', 'balanza-1')->delete();
        $this->assertSame(1, ApiKey::withoutGlobalScopes()->count());
        $this->assertSame('balanza-2', ApiKey::withoutGlobalScopes()->first()->device_id);
    }

    public function test_the_key_works_against_the_scale_api(): void
    {
        // Lo que de verdad importa: lo que sale de aquí tiene que servir para
        // vender. La Scale API no se toca, sólo se le emite una llave que ya
        // sabe aceptar.
        Sanctum::actingAs($this->cajero);
        $raw = $this->postJson('/api/v1/hub/devices/balanza-1/api-key', ['name' => 'Balanza 1'])
            ->json('raw_key');

        $this->withHeader('X-Api-Key', $raw)
            ->getJson('/api/v1/branches/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->branch->id);
    }

    public function test_it_needs_a_token(): void
    {
        $this->postJson('/api/v1/hub/devices/x/api-key', ['name' => 'X'])->assertUnauthorized();
    }
}
