<?php

namespace Tests\Feature\Hub;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Revocar la llave de nube de un equipo.
 *
 * Cuando en el hub se revoca una báscula, la báscula no sólo debe dejar de
 * hablar con el hub: su llave de nube también tiene que morir, o una tablet
 * perdida seguiría vendiendo directo contra la nube. En el hub suele estar
 * sólo un cajero, así que tiene que poder pedirlo; y como el hub reintenta
 * cuando vuelve la red, la ruta tiene que ser idempotente.
 */
class DeviceApiKeyRevokeTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    /**
     * Crea una llave con su raw conocido, para poder probarla contra la Scale API.
     */
    private function makeKey(int $tenantId, int $branchId, ?string $deviceId, string $raw): ApiKey
    {
        return ApiKey::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'device_id' => $deviceId,
            'name' => 'Llave '.($deviceId ?? 'suelta'),
            'key_hash' => hash('sha256', $raw),
        ]);
    }

    public function test_a_cashier_revokes_the_keys_of_a_device_in_their_branch(): void
    {
        $this->makeKey($this->tenant->id, $this->branch->id, 'balanza-1', 'csa_uno');

        Sanctum::actingAs($this->cajero);

        $this->deleteJson('/api/v1/hub/devices/balanza-1/api-key')
            ->assertOk()
            ->assertExactJson(['revoked' => 1]);

        $this->assertSame(0, ApiKey::withoutGlobalScopes()->where('device_id', 'balanza-1')->count());
    }

    public function test_a_branch_admin_can_revoke_too(): void
    {
        $this->makeKey($this->tenant->id, $this->branch->id, 'balanza-1', 'csa_uno');

        Sanctum::actingAs($this->adminSucursal);

        $this->deleteJson('/api/v1/hub/devices/balanza-1/api-key')
            ->assertOk()
            ->assertExactJson(['revoked' => 1]);
    }

    public function test_it_only_touches_that_device_in_that_branch(): void
    {
        // Lo que no debe caer: las llaves sueltas del admin (sin equipo), las
        // de otro equipo, y las de un equipo con el mismo id en otra sucursal
        // u otra empresa —el id lo inventa el equipo, no es único global—.
        $this->makeKey($this->tenant->id, $this->branch->id, 'balanza-1', 'csa_objetivo');
        $suelta = $this->makeKey($this->tenant->id, $this->branch->id, null, 'csa_suelta');
        $otroEquipo = $this->makeKey($this->tenant->id, $this->branch->id, 'balanza-2', 'csa_otro_equipo');
        $otraSucursal = $this->makeKey($this->tenant->id, $this->secondBranch->id, 'balanza-1', 'csa_otra_sucursal');

        $otraEmpresa = Tenant::create(['name' => 'Otra', 'slug' => 'otra', 'status' => 'active']);
        $sucursalAjena = Branch::create(['tenant_id' => $otraEmpresa->id, 'name' => 'Ajena', 'address' => 'C', 'status' => 'active']);
        $ajena = $this->makeKey($otraEmpresa->id, $sucursalAjena->id, 'balanza-1', 'csa_ajena');

        Sanctum::actingAs($this->cajero);

        $this->deleteJson('/api/v1/hub/devices/balanza-1/api-key')
            ->assertOk()
            ->assertExactJson(['revoked' => 1]);

        $vivas = ApiKey::withoutGlobalScopes()->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$suelta->id, $otroEquipo->id, $otraSucursal->id, $ajena->id],
            $vivas,
        );
    }

    public function test_revoking_twice_is_safe(): void
    {
        // El hub reintenta la revocación cuando vuelve la red: la segunda
        // llamada no puede fallar, sólo decir que ya no había nada.
        $this->makeKey($this->tenant->id, $this->branch->id, 'balanza-1', 'csa_uno');

        Sanctum::actingAs($this->cajero);

        $this->deleteJson('/api/v1/hub/devices/balanza-1/api-key')->assertOk()->assertExactJson(['revoked' => 1]);
        $this->deleteJson('/api/v1/hub/devices/balanza-1/api-key')->assertOk()->assertExactJson(['revoked' => 0]);
    }

    public function test_the_revoked_key_no_longer_sells(): void
    {
        // Lo que de verdad importa: después de revocar, la báscula ya no entra
        // a la Scale API con esa llave.
        $this->makeKey($this->tenant->id, $this->branch->id, 'balanza-1', 'csa_revocada_de_verdad');

        $this->withHeader('X-Api-Key', 'csa_revocada_de_verdad')
            ->getJson('/api/v1/branches/me')
            ->assertOk();

        Sanctum::actingAs($this->cajero);
        $this->deleteJson('/api/v1/hub/devices/balanza-1/api-key')->assertOk();

        $this->withHeader('X-Api-Key', 'csa_revocada_de_verdad')
            ->getJson('/api/v1/branches/me')
            ->assertUnauthorized();
    }

    public function test_it_needs_a_token(): void
    {
        $this->deleteJson('/api/v1/hub/devices/balanza-1/api-key')->assertUnauthorized();
    }

    public function test_a_company_admin_is_not_a_hub_role(): void
    {
        $this->makeKey($this->tenant->id, $this->branch->id, 'balanza-1', 'csa_uno');

        Sanctum::actingAs($this->adminEmpresa);

        $this->deleteJson('/api/v1/hub/devices/balanza-1/api-key')->assertForbidden();

        $this->assertSame(1, ApiKey::withoutGlobalScopes()->count());
    }
}
