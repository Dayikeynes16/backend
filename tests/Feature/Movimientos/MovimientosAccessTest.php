<?php

namespace Tests\Feature\Movimientos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

/**
 * Quién entra a Movimientos.
 *
 * La pantalla vigila a quien opera la caja, y la cuenta de admin-sucursal es una
 * de las que puede quedar bajo sospecha: por eso su flag nace apagado y lo
 * enciende el admin-empresa, que entra siempre desde la suya. El cajero no entra
 * nunca.
 */
class MovimientosAccessTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function enableForBranchAdmin(bool $enabled = true): void
    {
        $this->branch->forceFill(['branch_admin_movements_enabled' => $enabled])->save();
    }

    public function test_el_admin_de_empresa_entra_siempre(): void
    {
        // Aunque el flag de la sucursal esté apagado: el flag gobierna a su par
        // de sucursal, no a él.
        $this->enableForBranchAdmin(false);

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.movimientos.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Empresa/Movimientos/Index')
                ->where('scope', 'empresa'));
    }

    public function test_el_admin_de_sucursal_sin_el_flag_no_entra(): void
    {
        $this->enableForBranchAdmin(false);

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.movimientos.index', $this->tenant->slug))
            ->assertForbidden();
    }

    public function test_el_admin_de_sucursal_con_el_flag_entra_y_solo_ve_la_suya(): void
    {
        $this->enableForBranchAdmin();

        $this->actingAs($this->adminSucursal)
            ->get(route('sucursal.movimientos.index', $this->tenant->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sucursal/Movimientos/Index')
                ->where('scope', 'sucursal')
                // Sin lista de sucursales no hay selector: no elige alcance.
                ->where('branches', []));
    }

    public function test_el_cajero_no_entra_por_ninguna_de_las_dos_puertas(): void
    {
        $this->enableForBranchAdmin();

        $this->actingAs($this->cajero)
            ->get(route('sucursal.movimientos.index', $this->tenant->slug))
            ->assertForbidden();

        $this->actingAs($this->cajero)
            ->get(route('empresa.movimientos.index', $this->tenant->slug))
            ->assertForbidden();
    }

    public function test_el_flag_nace_apagado(): void
    {
        // Un registro de vigilancia se enciende a propósito, nunca por omisión.
        // Es el único flag de sucursal que nace en false.
        $this->assertFalse((bool) $this->secondBranch->fresh()->branch_admin_movements_enabled);
    }

    public function test_una_sucursal_ajena_en_la_url_se_ignora(): void
    {
        // El admin-empresa sí elige sucursal, pero solo entre las suyas: un id
        // que no es de esta empresa se descarta en vez de filtrar por él, que
        // sería colar movimientos ajenos en su pantalla.
        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.movimientos.index', [$this->tenant->slug, 'branch_id' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.branch_id', null));

        $this->actingAs($this->adminEmpresa)
            ->get(route('empresa.movimientos.index', [$this->tenant->slug, 'branch_id' => $this->secondBranch->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.branch_id', (string) $this->secondBranch->id));
    }
}
