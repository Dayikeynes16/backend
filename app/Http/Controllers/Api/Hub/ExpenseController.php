<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubExpenseResource;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /**
     * Gastos en efectivo del cajero (su turno) + árbol de categorías para el
     * formulario. Misma semántica que Caja\GastoController.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureModuleEnabled($user->branch_id);

        $expenses = Expense::where('branch_id', $user->branch_id)
            ->where('user_id', $user->id)
            ->whereNull('cancelled_by')
            ->with('subcategory.category')
            ->orderByDesc('expense_at')
            ->limit(50)
            ->get();

        $categories = ExpenseCategory::with([
            'subcategories' => fn ($q) => $q->where('status', 'active')->orderBy('name'),
        ])->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => HubExpenseResource::collection($expenses),
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subcategories' => $c->subcategories->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ])->values(),
            'total' => (float) $expenses->sum('amount'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureModuleEnabled($user->branch_id);

        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
        if (! $shift) {
            return response()->json(['message' => 'Abre un turno antes de registrar un gasto.'], 409);
        }

        $validated = $request->validate([
            'concept' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expense_subcategory_id' => [
                'required',
                Rule::exists('expense_subcategories', 'id')->where(
                    fn ($q) => $q->where('tenant_id', $user->tenant_id)->where('status', 'active')
                ),
            ],
            'description' => 'nullable|string|max:1000',
        ]);

        // El gasto del cajero siempre es en efectivo y ligado al turno.
        $expense = Expense::create([
            'tenant_id' => $user->tenant_id,
            'branch_id' => $shift->branch_id,
            'cash_register_shift_id' => $shift->id,
            'expense_subcategory_id' => $validated['expense_subcategory_id'],
            'user_id' => $user->id,
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'payment_method' => PaymentMethod::Cash->value,
            'expense_at' => now(),
            'description' => $validated['description'] ?? null,
        ]);

        app(AuditLogger::class)->logCreated($expense);

        $expense->load('subcategory.category');

        return response()->json(['data' => HubExpenseResource::make($expense)], 201);
    }

    private function ensureModuleEnabled(?int $branchId): void
    {
        $branch = Branch::withoutGlobalScopes()->find($branchId);

        abort_unless(
            $branch && $branch->cashier_expenses_enabled,
            403,
            'El registro de gastos no está habilitado para tu sucursal.'
        );
    }
}
