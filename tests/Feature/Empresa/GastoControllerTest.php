<?php

namespace Tests\Feature\Empresa;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsMetricsData;
use Tests\TestCase;

class GastoControllerTest extends TestCase
{
    use RefreshDatabase, SeedsMetricsData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant();
        app()->instance('tenant', $this->tenant);
    }

    private function makeSubcategory(?int $tenantId = null): ExpenseSubcategory
    {
        $tenantId ??= $this->tenant->id;
        $cat = ExpenseCategory::create([
            'tenant_id' => $tenantId,
            'name' => 'Servicios '.uniqid(),
            'status' => 'active',
        ]);

        return ExpenseSubcategory::create([
            'tenant_id' => $tenantId,
            'expense_category_id' => $cat->id,
            'name' => 'Luz '.uniqid(),
            'status' => 'active',
        ]);
    }

    public function test_admin_empresa_can_list_expenses(): void
    {
        $this->actingAs($this->adminEmpresa);
        $sub = $this->makeSubcategory();

        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $sub->id,
            'user_id' => $this->adminEmpresa->id,
            'concept' => 'Recibo de luz',
            'amount' => 1000,
            'expense_at' => now(),
        ]);

        // Default filter es "hoy" — el gasto recién creado se ve
        $response = $this->get(route('empresa.gastos.index', $this->tenant->slug));
        $response->assertOk();
        $page = $response->viewData('page');
        $this->assertCount(1, $page['props']['expenses']['data']);
    }

    public function test_default_filter_is_today(): void
    {
        $this->actingAs($this->adminEmpresa);
        $sub = $this->makeSubcategory();

        // Gasto de hace 5 días NO debe aparecer en el default
        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $sub->id,
            'user_id' => $this->adminEmpresa->id,
            'concept' => 'Viejo', 'amount' => 1, 'expense_at' => now()->subDays(5),
        ]);
        Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $sub->id,
            'user_id' => $this->adminEmpresa->id,
            'concept' => 'Hoy', 'amount' => 1, 'expense_at' => now(),
        ]);

        $response = $this->get(route('empresa.gastos.index', $this->tenant->slug));
        $rows = $response->viewData('page')['props']['expenses']['data'];
        $this->assertCount(1, $rows);
        $this->assertSame('Hoy', $rows[0]['concept']);
    }

    public function test_branch_id_is_required(): void
    {
        $this->actingAs($this->adminEmpresa);
        $sub = $this->makeSubcategory();

        $response = $this->from(route('empresa.gastos.index', $this->tenant->slug))
            ->post(route('empresa.gastos.store', $this->tenant->slug), [
                'concept' => 'Sin sucursal',
                'amount' => 100,
                'expense_subcategory_id' => $sub->id,
                'expense_date' => now()->toDateString(),
            ]);

        $response->assertSessionHasErrors('branch_id');
        $this->assertSame(0, Expense::count());
    }

    public function test_store_uses_expense_date_with_current_time(): void
    {
        $this->actingAs($this->adminEmpresa);
        $sub = $this->makeSubcategory();
        $pastDate = now()->subDays(3)->toDateString();

        $response = $this->post(route('empresa.gastos.store', $this->tenant->slug), [
            'concept' => 'Gasto de hace 3 días',
            'amount' => 500,
            'expense_subcategory_id' => $sub->id,
            'branch_id' => $this->branch->id,
            'expense_date' => $pastDate,
        ]);

        $response->assertSessionHasNoErrors();
        $expense = Expense::firstOrFail();
        // La fecha respeta lo capturado
        $this->assertSame($pastDate, $expense->expense_at->toDateString());
        // La hora coincide con la actual (margen de 1 minuto)
        $this->assertTrue(abs($expense->expense_at->diffInSeconds(now()->setDate(...explode('-', $pastDate)))) <= 60);
    }

    public function test_subcategory_must_belong_to_current_tenant(): void
    {
        $this->actingAs($this->adminEmpresa);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-tenant', 'status' => 'active']);
        app()->forgetInstance('tenant');
        $foreignSub = ExpenseSubcategory::create([
            'tenant_id' => $other->id,
            'expense_category_id' => ExpenseCategory::create([
                'tenant_id' => $other->id, 'name' => 'X', 'status' => 'active',
            ])->id,
            'name' => 'Y', 'status' => 'active',
        ]);
        app()->instance('tenant', $this->tenant);

        $response = $this->from(route('empresa.gastos.index', $this->tenant->slug))
            ->post(route('empresa.gastos.store', $this->tenant->slug), [
                'concept' => 'Inválido',
                'amount' => 100,
                'expense_subcategory_id' => $foreignSub->id,
                'branch_id' => $this->branch->id,
                'expense_date' => now()->toDateString(),
            ]);

        $response->assertSessionHasErrors('expense_subcategory_id');
    }

    public function test_attach_image_and_pdf_creates_attachments(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminEmpresa);
        $sub = $this->makeSubcategory();

        $response = $this->post(route('empresa.gastos.store', $this->tenant->slug), [
            'concept' => 'Con adjuntos',
            'amount' => 500,
            'expense_subcategory_id' => $sub->id,
            'branch_id' => $this->branch->id,
            'expense_date' => now()->toDateString(),
            'attachments' => [
                UploadedFile::fake()->image('ticket.jpg', 100, 100),
                UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $expense = Expense::where('concept', 'Con adjuntos')->firstOrFail();
        $this->assertSame(2, $expense->attachments()->count());
        foreach ($expense->attachments as $att) {
            Storage::disk('local')->assertExists($att->path);
        }
    }

    public function test_attach_rejects_disallowed_mime(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminEmpresa);
        $sub = $this->makeSubcategory();

        $response = $this->from(route('empresa.gastos.index', $this->tenant->slug))
            ->post(route('empresa.gastos.store', $this->tenant->slug), [
                'concept' => 'Mal archivo',
                'amount' => 100,
                'expense_subcategory_id' => $sub->id,
                'branch_id' => $this->branch->id,
                'expense_date' => now()->toDateString(),
                'attachments' => [
                    UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
                ],
            ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, Expense::count());
    }

    public function test_attach_rejects_video_mime(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminEmpresa);
        $sub = $this->makeSubcategory();

        $response = $this->from(route('empresa.gastos.index', $this->tenant->slug))
            ->post(route('empresa.gastos.store', $this->tenant->slug), [
                'concept' => 'Video',
                'amount' => 100,
                'expense_subcategory_id' => $sub->id,
                'branch_id' => $this->branch->id,
                'expense_date' => now()->toDateString(),
                'attachments' => [
                    UploadedFile::fake()->create('video.mp4', 10, 'video/mp4'),
                ],
            ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, Expense::count());
    }

    public function test_destroy_soft_deletes_and_records_canceller(): void
    {
        $this->actingAs($this->adminEmpresa);
        $sub = $this->makeSubcategory();
        $exp = Expense::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'expense_subcategory_id' => $sub->id,
            'user_id' => $this->adminEmpresa->id,
            'concept' => 'Bórrame',
            'amount' => 1, 'expense_at' => now(),
        ]);

        $this->delete(route('empresa.gastos.destroy', [$this->tenant->slug, $exp->id]), [
            'cancellation_reason' => 'Duplicado',
        ])->assertSessionHasNoErrors();

        $exp->refresh();
        $this->assertNotNull($exp->deleted_at);
        $this->assertSame($this->adminEmpresa->id, $exp->cancelled_by);
        $this->assertSame('Duplicado', $exp->cancellation_reason);
    }

    public function test_cross_tenant_access_to_expense_is_forbidden(): void
    {
        $this->actingAs($this->adminEmpresa);
        $this->makeSubcategory();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $otherBranch = Branch::create(['tenant_id' => $other->id, 'name' => 'X', 'address' => 'Y', 'status' => 'active']);
        app()->forgetInstance('tenant');
        $foreignSub = ExpenseSubcategory::create([
            'tenant_id' => $other->id,
            'expense_category_id' => ExpenseCategory::create([
                'tenant_id' => $other->id, 'name' => 'X', 'status' => 'active',
            ])->id,
            'name' => 'Y', 'status' => 'active',
        ]);
        $foreignExpense = Expense::create([
            'tenant_id' => $other->id,
            'branch_id' => $otherBranch->id,
            'expense_subcategory_id' => $foreignSub->id,
            'user_id' => $this->adminEmpresa->id,
            'concept' => 'Cross', 'amount' => 1, 'expense_at' => now(),
        ]);
        app()->instance('tenant', $this->tenant);

        $response = $this->delete(route('empresa.gastos.destroy', [$this->tenant->slug, $foreignExpense->id]));
        $response->assertNotFound();
    }

    public function test_cajero_cannot_access_empresa_gastos(): void
    {
        $this->actingAs($this->cajero);
        $response = $this->get(route('empresa.gastos.index', $this->tenant->slug));
        $response->assertForbidden();
    }
}
