<?php

namespace Tests\Feature\Api\Hub;

use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class ExpenseCategoryApiTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        $this->branch->forceFill(['branch_admin_expense_categories_enabled' => true])->save();
    }

    private function asAdmin(): static
    {
        return $this->withToken($this->adminSucursal->createToken('hub')->plainTextToken);
    }

    private function makeCategory(string $name = 'Servicios', string $status = 'active'): ExpenseCategory
    {
        return ExpenseCategory::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'status' => $status,
            'created_by' => $this->adminSucursal->id,
        ]);
    }

    private function makeSubcategory(ExpenseCategory $category, string $name, string $status = 'active'): ExpenseSubcategory
    {
        return ExpenseSubcategory::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $category->id,
            'name' => $name,
            'status' => $status,
            'created_by' => $this->adminSucursal->id,
        ]);
    }

    public function test_index_returns_full_catalog_including_inactive(): void
    {
        $cat = $this->makeCategory('Servicios');
        $this->makeCategory('Insumos', 'inactive');
        $this->makeSubcategory($cat, 'Luz');
        $this->makeSubcategory($cat, 'Agua', 'inactive');

        $res = $this->asAdmin()->getJson('/api/v1/hub/expense-categories')->assertOk();

        $this->assertCount(2, $res->json('data'));
        // Orden alfabético: Insumos, Servicios.
        $res->assertJsonPath('data.0.name', 'Insumos')
            ->assertJsonPath('data.0.status', 'inactive')
            ->assertJsonPath('data.1.name', 'Servicios');
        $this->assertCount(2, $res->json('data.1.subcategories'));
        $res->assertJsonPath('data.1.subcategories.0.name', 'Agua')
            ->assertJsonPath('data.1.subcategories.0.status', 'inactive');
    }

    public function test_cajero_is_forbidden(): void
    {
        $token = $this->cajero->createToken('hub')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/hub/expense-categories')->assertStatus(403);
    }

    public function test_admin_forbidden_when_toggle_off(): void
    {
        $this->branch->forceFill(['branch_admin_expense_categories_enabled' => false])->save();

        $this->asAdmin()->getJson('/api/v1/hub/expense-categories')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu empresa no ha habilitado esta función para tu sucursal.');
    }

    public function test_store_category_creates_active_with_normalized_aliases(): void
    {
        $res = $this->asAdmin()->postJson('/api/v1/hub/expense-categories', [
            'name' => 'Vehículos',
            'description' => 'Gastos de flotilla',
            'aliases' => ['Combustible', ' combustible ', '', 'Logística'],
        ]);

        $res->assertCreated()
            ->assertJsonPath('message', 'Categoría creada.')
            ->assertJsonPath('data.name', 'Vehículos')
            ->assertJsonPath('data.status', 'active');
        // Dedupe case-insensitive + drop vacíos (ExpenseCategoryWriter::normalizeList).
        $this->assertSame(['Combustible', 'Logística'], $res->json('data.aliases'));
    }

    public function test_store_category_rejects_duplicate_name(): void
    {
        $this->makeCategory('Servicios');

        $this->asAdmin()->postJson('/api/v1/hub/expense-categories', ['name' => 'Servicios'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ya existe una categoría de gastos con ese nombre.');
    }

    public function test_update_category_changes_fields_and_status(): void
    {
        $cat = $this->makeCategory('Servicios');

        $this->asAdmin()->patchJson("/api/v1/hub/expense-categories/{$cat->id}", [
            'name' => 'Servicios Generales',
            'description' => 'Servicios de la sucursal',
            'aliases' => ['Luz', 'Agua'],
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('message', 'Categoría actualizada.')
            ->assertJsonPath('data.name', 'Servicios Generales')
            ->assertJsonPath('data.status', 'inactive');

        $this->assertSame(['Luz', 'Agua'], $cat->fresh()->aliases);
    }

    public function test_update_category_rejects_name_of_another_category(): void
    {
        $this->makeCategory('Servicios');
        $cat = $this->makeCategory('Insumos');

        $this->asAdmin()->patchJson("/api/v1/hub/expense-categories/{$cat->id}", [
            'name' => 'Servicios',
            'status' => 'active',
        ])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ya existe otra categoría con ese nombre.');
    }

    public function test_store_subcategory_and_duplicate_message(): void
    {
        $cat = $this->makeCategory('Servicios');

        $this->asAdmin()->postJson('/api/v1/hub/expense-subcategories', [
            'expense_category_id' => $cat->id,
            'name' => 'Luz',
        ])->assertCreated()
            ->assertJsonPath('message', 'Subcategoría creada.')
            ->assertJsonPath('data.name', 'Luz')
            ->assertJsonPath('data.expense_category_id', $cat->id);

        $this->asAdmin()->postJson('/api/v1/hub/expense-subcategories', [
            'expense_category_id' => $cat->id,
            'name' => 'Luz',
        ])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ya existe esa subcategoría dentro de la categoría.');
    }

    public function test_update_subcategory_rejects_duplicate_in_same_category(): void
    {
        $cat = $this->makeCategory('Servicios');
        $this->makeSubcategory($cat, 'Luz');
        $sub = $this->makeSubcategory($cat, 'Agua');

        $this->asAdmin()->patchJson("/api/v1/hub/expense-subcategories/{$sub->id}", [
            'name' => 'Luz',
            'status' => 'active',
        ])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Ya existe otra subcategoría con ese nombre en la categoría.');

        $this->asAdmin()->patchJson("/api/v1/hub/expense-subcategories/{$sub->id}", [
            'name' => 'Agua potable',
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Agua potable')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_auth_payload_exposes_categories_flag(): void
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email' => $this->adminSucursal->email,
            'password' => 'password',
            'device_name' => 'Hub Sucursal 1',
        ])->assertOk();

        $res->assertJsonPath('user.branch_admin_expense_categories_enabled', true);
    }
}
