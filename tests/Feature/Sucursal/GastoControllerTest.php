<?php

namespace Tests\Feature\Sucursal;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class GastoControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected ExpenseSubcategory $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);

        $cat = ExpenseCategory::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Servicios', 'status' => 'active',
        ]);
        $this->sub = ExpenseSubcategory::create([
            'tenant_id' => $this->tenant->id,
            'expense_category_id' => $cat->id,
            'name' => 'Luz', 'status' => 'active',
        ]);
    }

    public function test_admin_sucursal_only_sees_own_branch_expenses(): void
    {
        $own = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $this->adminSucursal->id,
            'concept' => 'Mío', 'amount' => 100, 'expense_at' => now(),
        ]);
        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $this->adminSucursal->id,
            'concept' => 'De B2', 'amount' => 100, 'expense_at' => now(),
        ]);

        $this->actingAs($this->adminSucursal);
        $response = $this->get(route('sucursal.gastos.index', $this->tenant->slug));
        $response->assertOk();
        $page = $response->viewData('page');
        $rows = $page['props']['expenses']['data'];
        $this->assertCount(1, $rows);
        $this->assertSame($own->id, $rows[0]['id']);
    }

    public function test_admin_sucursal_creates_expense_forced_to_own_branch(): void
    {
        $this->actingAs($this->adminSucursal);

        $this->post(route('sucursal.gastos.store', $this->tenant->slug), [
            'concept' => 'Cloro',
            'amount' => 280,
            'expense_subcategory_id' => $this->sub->id,
            'expense_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $exp = Expense::firstOrFail();
        $this->assertSame($this->branch->id, $exp->branch_id);
        $this->assertSame($this->adminSucursal->id, $exp->user_id);
    }

    public function test_admin_sucursal_cannot_edit_another_branch_expense(): void
    {
        $foreign = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->secondBranch->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $this->adminEmpresa->id,
            'concept' => 'Otra branch', 'amount' => 100, 'expense_at' => now(),
        ]);

        $this->actingAs($this->adminSucursal);
        $this->put(route('sucursal.gastos.update', [$this->tenant->slug, $foreign->id]), [
            'concept' => 'hack',
            'amount' => 1,
            'expense_subcategory_id' => $this->sub->id,
            'expense_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_attachment_preview_returns_inline_disposition(): void
    {
        Storage::fake('local');

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.gastos.store', $this->tenant->slug), [
            'concept' => 'Con adjunto',
            'amount' => 100,
            'expense_subcategory_id' => $this->sub->id,
            'expense_date' => now()->toDateString(),
            'attachments' => [UploadedFile::fake()->image('t.png')],
        ])->assertSessionHasNoErrors();

        $exp = Expense::firstOrFail();
        $att = $exp->attachments()->firstOrFail();

        $response = $this->get(route('sucursal.gastos.adjuntos.preview', [$this->tenant->slug, $exp->id, $att->id]));
        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition') ?? '');
    }

    public function test_attachment_download_is_protected_by_role(): void
    {
        Storage::fake('local');

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.gastos.store', $this->tenant->slug), [
            'concept' => 'Con adjunto',
            'amount' => 100,
            'expense_subcategory_id' => $this->sub->id,
            'expense_date' => now()->toDateString(),
            'attachments' => [UploadedFile::fake()->image('t.png')],
        ]);

        $exp = Expense::firstOrFail();
        $att = $exp->attachments()->firstOrFail();

        // Cajero del mismo tenant: 403 (rol no autorizado)
        $this->actingAs($this->cajero);
        $this->get(route('sucursal.gastos.adjuntos.download', [$this->tenant->slug, $exp->id, $att->id]))
            ->assertForbidden();
        $this->get(route('sucursal.gastos.adjuntos.preview', [$this->tenant->slug, $exp->id, $att->id]))
            ->assertForbidden();

        $this->actingAs($this->adminSucursal);
        $this->get(route('sucursal.gastos.adjuntos.download', [$this->tenant->slug, $exp->id, $att->id]))
            ->assertOk();
    }

    public function test_attachment_destroy_removes_file_from_disk(): void
    {
        Storage::fake('local');

        $this->actingAs($this->adminSucursal);
        $this->post(route('sucursal.gastos.store', $this->tenant->slug), [
            'concept' => 'Con adjunto',
            'amount' => 100,
            'expense_subcategory_id' => $this->sub->id,
            'expense_date' => now()->toDateString(),
            'attachments' => [UploadedFile::fake()->image('t.png')],
        ]);

        $exp = Expense::firstOrFail();
        $att = $exp->attachments()->firstOrFail();
        $path = $att->path;

        Storage::disk('local')->assertExists($path);

        $this->delete(route('sucursal.gastos.adjuntos.destroy', [$this->tenant->slug, $exp->id, $att->id]))
            ->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($path);
        $this->assertNull(ExpenseAttachment::find($att->id));
    }

    public function test_subcategory_with_expenses_cannot_be_deleted(): void
    {
        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $this->adminEmpresa->id,
            'concept' => 'Bloquea', 'amount' => 1, 'expense_at' => now(),
        ]);

        $this->actingAs($this->adminEmpresa);
        $this->from(route('empresa.gastos.index', $this->tenant->slug))
            ->delete(route('empresa.gastos.subcategorias.destroy', [$this->tenant->slug, $this->sub->id]))
            ->assertSessionHas('error');

        $this->assertNotNull(ExpenseSubcategory::find($this->sub->id));
    }
}
