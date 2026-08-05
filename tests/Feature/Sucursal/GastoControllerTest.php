<?php

namespace Tests\Feature\Sucursal;

use App\Models\CashRegisterShift;
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

    public function test_caja_can_only_view_own_expense_attachments_in_own_branch(): void
    {
        Storage::fake('local');

        $shift = CashRegisterShift::create([
            'user_id' => $this->cajero->id,
            'branch_id' => $this->branch->id,
            'tenant_id' => $this->tenant->id,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $own = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $this->cajero->id,
            'concept' => 'Mío', 'amount' => 100, 'expense_at' => now(),
        ]);
        $ownAtt = ExpenseAttachment::create([
            'tenant_id' => $this->tenant->id,
            'expense_id' => $own->id,
            'original_name' => 'propio.jpg',
            'path' => "tenants/{$this->tenant->id}/expenses/{$own->id}/propio.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($ownAtt->path, 'fake');

        $otherCajero = $this->makeUser('caja2@test.local', 'cajero', $this->branch->id);
        $othersExpense = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $otherCajero->id,
            'concept' => 'De otro cajero', 'amount' => 100, 'expense_at' => now(),
        ]);
        $othersAtt = ExpenseAttachment::create([
            'tenant_id' => $this->tenant->id,
            'expense_id' => $othersExpense->id,
            'original_name' => 'ajeno.jpg',
            'path' => "tenants/{$this->tenant->id}/expenses/{$othersExpense->id}/ajeno.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($othersAtt->path, 'fake');

        $this->actingAs($this->cajero);

        // Puede ver el suyo.
        $this->get(route('caja.gastos.adjuntos.download', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertOk();
        $this->get(route('caja.gastos.adjuntos.preview', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertOk();

        // NO puede ver el de otro cajero de su misma sucursal.
        $this->get(route('caja.gastos.adjuntos.download', [$this->tenant->slug, $othersExpense->id, $othersAtt->id]))
            ->assertForbidden();
        $this->get(route('caja.gastos.adjuntos.preview', [$this->tenant->slug, $othersExpense->id, $othersAtt->id]))
            ->assertForbidden();

        // Puede eliminar el suyo (dentro de su turno abierto).
        $this->delete(route('caja.gastos.adjuntos.destroy', [$this->tenant->slug, $own->id, $ownAtt->id]))
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($ownAtt->path);

        // NO puede eliminar el de otro cajero.
        $this->delete(route('caja.gastos.adjuntos.destroy', [$this->tenant->slug, $othersExpense->id, $othersAtt->id]))
            ->assertForbidden();
    }

    public function test_admin_sucursal_access_to_expense_attachments_unaffected_by_caja_routes(): void
    {
        Storage::fake('local');

        $exp = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $this->sub->id,
            'user_id' => $this->cajero->id,
            'concept' => 'De cajero', 'amount' => 100, 'expense_at' => now(),
        ]);
        $att = ExpenseAttachment::create([
            'tenant_id' => $this->tenant->id,
            'expense_id' => $exp->id,
            'original_name' => 'x.jpg',
            'path' => "tenants/{$this->tenant->id}/expenses/{$exp->id}/x.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
        ]);
        Storage::disk('local')->put($att->path, 'fake');

        // admin-sucursal sigue pudiendo ver/borrar cualquier adjunto de su sucursal,
        // sin importar quién lo creó ni si tiene turno abierto.
        $this->actingAs($this->adminSucursal);
        $this->get(route('sucursal.gastos.adjuntos.download', [$this->tenant->slug, $exp->id, $att->id]))->assertOk();
        $this->delete(route('sucursal.gastos.adjuntos.destroy', [$this->tenant->slug, $exp->id, $att->id]))
            ->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($att->path);
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
