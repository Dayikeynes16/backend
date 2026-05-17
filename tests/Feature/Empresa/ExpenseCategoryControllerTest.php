<?php

namespace Tests\Feature\Empresa;

use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseCategoryControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    public function test_admin_empresa_can_create_category_with_description_and_aliases(): void
    {
        $this->actingAs($this->adminEmpresa);

        $this->post(route('empresa.gastos.categorias.store', $this->tenant->slug), [
            'name' => 'Transporte',
            'description' => 'Gastos de vehículos y traslados.',
            'aliases' => ['Vehículos', 'Combustible', 'Logística'],
        ])->assertSessionHasNoErrors();

        $cat = ExpenseCategory::firstOrFail();
        $this->assertSame('Transporte', $cat->name);
        $this->assertSame('Gastos de vehículos y traslados.', $cat->description);
        $this->assertSame(['Vehículos', 'Combustible', 'Logística'], $cat->aliases);
    }

    public function test_aliases_are_normalized_on_create(): void
    {
        $this->actingAs($this->adminEmpresa);

        $this->post(route('empresa.gastos.categorias.store', $this->tenant->slug), [
            'name' => 'Servicios',
            'aliases' => ['  CFE ', 'cfe', 'Electricidad', '', 'Electricidad'],
        ])->assertSessionHasNoErrors();

        $cat = ExpenseCategory::firstOrFail();
        // Trim, drop empties, dedupe case-insensitive
        $this->assertSame(['CFE', 'Electricidad'], $cat->aliases);
    }

    public function test_aliases_null_when_empty_list_submitted(): void
    {
        $this->actingAs($this->adminEmpresa);

        $this->post(route('empresa.gastos.categorias.store', $this->tenant->slug), [
            'name' => 'Otros',
            'aliases' => ['  ', ''],
        ])->assertSessionHasNoErrors();

        $cat = ExpenseCategory::firstOrFail();
        $this->assertNull($cat->aliases);
    }

    public function test_update_persists_description_and_aliases(): void
    {
        $this->actingAs($this->adminEmpresa);

        $cat = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Insumos',
            'status' => 'active',
        ]);

        $this->put(route('empresa.gastos.categorias.update', [$this->tenant->slug, $cat->id]), [
            'name' => 'Insumos',
            'description' => 'Materiales operativos.',
            'aliases' => ['Material', 'Consumibles'],
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $cat->refresh();
        $this->assertSame('Materiales operativos.', $cat->description);
        $this->assertSame(['Material', 'Consumibles'], $cat->aliases);
    }

    public function test_subcategory_create_accepts_description_and_aliases(): void
    {
        $this->actingAs($this->adminEmpresa);

        $cat = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Transporte', 'status' => 'active',
        ]);

        $this->post(route('empresa.gastos.subcategorias.store', $this->tenant->slug), [
            'expense_category_id' => $cat->id,
            'name' => 'Gasolina',
            'description' => 'Combustible para vehículos.',
            'aliases' => ['Diésel', 'Nafta'],
        ])->assertSessionHasNoErrors();

        $sub = ExpenseSubcategory::firstOrFail();
        $this->assertSame('Combustible para vehículos.', $sub->description);
        $this->assertSame(['Diésel', 'Nafta'], $sub->aliases);
    }

    public function test_aliases_must_be_array_of_strings(): void
    {
        $this->actingAs($this->adminEmpresa);

        $this->from(route('empresa.gastos.index', $this->tenant->slug))
            ->post(route('empresa.gastos.categorias.store', $this->tenant->slug), [
                'name' => 'Algo',
                'aliases' => 'no-soy-array',
            ])->assertSessionHasErrors('aliases');
    }
}
