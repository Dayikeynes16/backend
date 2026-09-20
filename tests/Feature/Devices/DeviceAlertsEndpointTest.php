<?php

namespace Tests\Feature\Devices;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * La franja se pinta con esto, cada 60 s y en varias pantallas. Tiene que
 * devolver poco, solo de la sucursal de quien pregunta, y no romperse con un
 * usuario que no tiene sucursal.
 */
class DeviceAlertsEndpointTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    public function test_it_lists_only_the_devices_in_alert(): void
    {
        [$tenant, $branch, $cashier] = $this->branchWithCashier();

        $low = $this->device($tenant, $branch, ['device_id' => 'baja', 'battery_level' => 14]);
        $this->device($tenant, $branch, ['device_id' => 'sana', 'battery_level' => 90]);
        $this->device($tenant, $branch, ['device_id' => 'cargando', 'battery_level' => 5, 'battery_charging' => true]);

        $this->actingAs($cashier)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_id', $low->device_id)
            ->assertJsonPath('data.0.severity', 'warn');
    }

    public function test_a_muted_or_retired_device_never_shows_up(): void
    {
        [$tenant, $branch, $cashier] = $this->branchWithCashier();

        $this->device($tenant, $branch, ['device_id' => 'callado', 'battery_level' => 8, 'muted_at' => now()]);
        $this->device($tenant, $branch, ['device_id' => 'de-baja', 'battery_level' => 8, 'retired_at' => now()]);

        $this->actingAs($cashier)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_critical_severity_travels(): void
    {
        [$tenant, $branch, $cashier] = $this->branchWithCashier();
        $branch->update(['battery_warn_threshold' => 30, 'battery_critical_threshold' => 15]);

        $this->device($tenant, $branch, ['device_id' => 'critica', 'battery_level' => 9]);

        $this->actingAs($cashier)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertJsonPath('data.0.severity', 'critical');
    }

    public function test_a_cashier_never_sees_another_branch(): void
    {
        [$tenant, $branch, $cashier] = $this->branchWithCashier();
        $other = $this->otherBranchOf($tenant);

        $this->device($tenant, $other, ['device_id' => 'de-la-otra', 'battery_level' => 8]);

        $this->actingAs($cashier)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_user_without_branch_gets_an_empty_list_not_a_403(): void
    {
        // El superadmin entra a los paneles y no tiene sucursal. La franja vive
        // en el layout: un 403 sería un error cada 60 segundos.
        [$tenant, $branch] = $this->branchWithCashier();
        $superadmin = $this->superadminOf($tenant);

        $this->device($tenant, $branch, ['device_id' => 'baja', 'battery_level' => 8]);

        $this->actingAs($superadmin)
            ->getJson("/{$tenant->slug}/equipos/alertas")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Monta tenant + dos sucursales + cajero, igual que DevicesTest: es el
     * mismo escenario, así que se reutiliza su forma en vez de inventar una
     * nueva.
     *
     * @return array{0: Tenant, 1: Branch, 2: User}
     */
    private function branchWithCashier(): array
    {
        $this->seedTenant();

        return [$this->tenant, $this->branch, $this->cajero];
    }

    private function device(Tenant $tenant, Branch $branch, array $attrs = []): Device
    {
        return Device::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'device_id' => 'd-'.uniqid(),
            'kind' => 'scale_android',
            'name' => 'Balanza 1',
            'app_version' => '1.6.1',
            'via' => 'cloud',
            'battery_level' => 90,
            'battery_charging' => false,
            'last_seen_at' => now(),
            'first_seen_at' => now(),
        ], $attrs));
    }

    private function otherBranchOf(Tenant $tenant): Branch
    {
        return $this->secondBranch;
    }

    private function superadminOf(Tenant $tenant): User
    {
        return $this->makeUser('super@test.local', 'superadmin', null);
    }
}
