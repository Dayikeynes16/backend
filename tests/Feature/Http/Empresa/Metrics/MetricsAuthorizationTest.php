<?php

namespace Tests\Feature\Http\Empresa\Metrics;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class MetricsAuthorizationTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
    }

    public function test_admin_empresa_can_view_index(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.metricas.index', $this->tenant->slug))
            ->assertOk();
    }

    public function test_admin_empresa_with_branch_filter(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.metricas.ventas', $this->tenant->slug).'?branch_id='.$this->branch->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected_branch_id', $this->branch->id));
    }

    public function test_admin_empresa_consolidated_without_branch_filter(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.metricas.ventas', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('selected_branch_id', null));
    }

    public function test_admin_empresa_foreign_branch_forbidden(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $otherBranch = Branch::create(['tenant_id' => $other->id, 'name' => 'O', 'address' => 'x', 'status' => 'active']);

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.metricas.ventas', $this->tenant->slug).'?branch_id='.$otherBranch->id)
            ->assertForbidden();
    }

    public function test_admin_sucursal_cannot_access_empresa_metricas(): void
    {
        $this->actingAs($this->adminSucursal)
            ->get(route('empresa.metricas.index', $this->tenant->slug))
            ->assertForbidden();
    }
}
