<?php

namespace Tests\Feature\Empresa;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class DevicesTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    private function device(int $branchId, string $name): Device
    {
        return Device::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branchId,
            'device_id' => 'd-'.uniqid(), 'kind' => 'hub_windows', 'name' => $name, 'via' => 'hub',
            'last_seen_at' => now(), 'first_seen_at' => now(),
        ]);
    }

    public function test_company_admin_sees_all_branches_grouped(): void
    {
        $this->device($this->branch->id, 'Hub Centro');
        $this->device($this->secondBranch->id, 'Hub Norte');

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.devices.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Empresa/Equipos/Index')
                ->has('branches', 2)
                ->where('branches.0.name', 'Sucursal 1')
                ->has('branches.0.devices', 1)
                ->where('branches.1.devices.0.name', 'Hub Norte')
            );
    }

    public function test_company_admin_can_rename_any_device_of_the_tenant(): void
    {
        $device = $this->device($this->secondBranch->id, 'Hub Norte');

        $this->actingAs($this->adminEmpresa)
            ->patch(route('empresa.devices.update', [$this->tenant->slug, $device]), ['display_name' => 'Norte'])
            ->assertRedirect();

        $this->assertSame('Norte', $device->refresh()->display_name);
    }

    public function test_branch_admin_cannot_open_the_company_panel(): void
    {
        $this->actingAs($this->adminSucursal)->get(route('empresa.devices.index', $this->tenant->slug))->assertForbidden();
    }
}
