<?php

namespace Tests\Feature\Empresa;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class BranchEditReasonModeTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_default_value_after_migration_is_optional(): void
    {
        $this->assertSame('optional', $this->branch->fresh()->sale_item_edit_reason_mode);
    }

    public function test_admin_empresa_can_change_mode_to_required(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]), [
                'name' => $this->branch->name,
                'status' => 'active',
                'sale_item_edit_reason_mode' => 'required',
            ])
            ->assertRedirect();

        $this->assertSame('required', $this->branch->fresh()->sale_item_edit_reason_mode);
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->actingAs($this->adminEmpresa)
            ->put(route('empresa.sucursales.update', [$this->tenant->slug, $this->branch->id]), [
                'name' => $this->branch->name,
                'status' => 'active',
                'sale_item_edit_reason_mode' => 'something-else',
            ])
            ->assertSessionHasErrors('sale_item_edit_reason_mode');

        $this->assertSame('optional', $this->branch->fresh()->sale_item_edit_reason_mode);
    }
}
