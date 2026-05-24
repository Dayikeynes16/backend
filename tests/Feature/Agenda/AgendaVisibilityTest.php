<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class AgendaVisibilityTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function make(array $attrs): AgendaItem
    {
        return AgendaItem::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'type' => 'task',
            'title' => 'X',
            'scope' => 'personal',
            'user_id' => $this->adminSucursal->id,
        ], $attrs));
    }

    public function test_admin_empresa_sees_all_branches(): void
    {
        $this->make(['scope' => 'branch', 'branch_id' => $this->branch->id, 'user_id' => $this->adminSucursal->id]);
        $this->make(['scope' => 'branch', 'branch_id' => $this->secondBranch->id, 'user_id' => $this->adminSucursal->id]);

        $visible = AgendaItem::visibleTo($this->adminEmpresa)->get();

        $this->assertCount(2, $visible);
    }

    public function test_admin_sucursal_sees_company_branch_personal_assigned(): void
    {
        $company = $this->make(['scope' => 'company', 'branch_id' => null, 'user_id' => $this->adminEmpresa->id]);
        $mine = $this->make(['scope' => 'branch', 'branch_id' => $this->branch->id]);
        $other = $this->make(['scope' => 'branch', 'branch_id' => $this->secondBranch->id]);
        $personalOther = $this->make(['scope' => 'personal', 'user_id' => $this->adminEmpresa->id]);
        $assigned = $this->make(['scope' => 'personal', 'user_id' => $this->adminEmpresa->id, 'assigned_to_user_id' => $this->adminSucursal->id]);

        $ids = AgendaItem::visibleTo($this->adminSucursal)->pluck('id');

        $this->assertTrue($ids->contains($company->id));
        $this->assertTrue($ids->contains($mine->id));
        $this->assertTrue($ids->contains($assigned->id));
        $this->assertFalse($ids->contains($other->id));
        $this->assertFalse($ids->contains($personalOther->id));
    }

    public function test_only_company_admin_creates_company_scope(): void
    {
        $this->assertTrue($this->adminEmpresa->can('createScope', [AgendaItem::class, 'company']));
        $this->assertFalse($this->adminSucursal->can('createScope', [AgendaItem::class, 'company']));
        $this->assertTrue($this->adminSucursal->can('createScope', [AgendaItem::class, 'personal']));
    }

    public function test_cannot_update_others_personal_item(): void
    {
        $item = $this->make(['scope' => 'personal', 'user_id' => $this->adminEmpresa->id]);
        $this->assertFalse($this->cajero->can('update', $item));
        $this->assertTrue($this->adminEmpresa->can('update', $item));
    }
}
