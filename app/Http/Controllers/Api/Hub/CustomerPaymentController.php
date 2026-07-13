<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\CustomerGlobalPaymentService;
use App\Services\RecalculateClosedShifts;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Cobro global de fiado (abono distribuido FIFO sobre las ventas pendientes del
 * cliente) desde el hub. Replica la lógica de Sucursal\CustomerPaymentController
 * reusando SalePaymentService::recalculate y RecalculateClosedShifts; devuelve
 * JSON en lugar de respuestas Inertia.
 */
class CustomerPaymentController extends Controller
{
    public function __construct(
        private SalePaymentService $salePaymentService,
        private CustomerGlobalPaymentService $globalPayments,
    ) {}

    /** Ledger del cliente: ventas pendientes + cobros globales recientes + deuda. */
    public function index(Request $request, int $customer): JsonResponse
    {
        $found = $this->findCustomer($request, $customer);
        $branch = Branch::withoutGlobalScopes()->find($request->user()->branch_id);

        $pending = Sale::withoutGlobalScopes()
            ->where('customer_id', $found->id)
            ->where('status', '!=', SaleStatus::Cancelled->value)
            ->accountable()
            ->where('amount_pending', '>', 0)
            ->orderBy('created_at')
            ->get(['id', 'folio', 'total', 'amount_pending', 'created_at']);

        $movements = CustomerPayment::withoutGlobalScopes()
            ->where('customer_id', $found->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json([
            'pending_sales' => $pending->map(fn ($s) => [
                'id' => $s->id,
                'folio' => $s->folio,
                'total' => (float) $s->total,
                'amount_pending' => (float) $s->amount_pending,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values(),
            'recent_movements' => $movements->map(fn ($m) => [
                'id' => $m->id,
                'folio' => $m->folio,
                'method' => $m->method,
                'amount_received' => (float) $m->amount_received,
                'amount_applied' => (float) $m->amount_applied,
                'change_given' => (float) $m->change_given,
                'sales_affected_count' => $m->sales_affected_count,
                'cancelled_at' => $m->cancelled_at?->toIso8601String(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'total_owed' => round((float) $pending->sum('amount_pending'), 2),
            'payment_methods' => $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'],
        ]);
    }

    /** Cobro global FIFO. Solo admin-sucursal (paridad con RegisterCustomerPaymentRequest web). Requiere turno abierto. */
    public function store(Request $request, int $customer): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede registrar cobros globales.'
        );
        $found = $this->findCustomer($request, $customer);

        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->exists();
        if (! $hasOpenShift) {
            return response()->json(['message' => 'Debes tener un turno abierto para registrar pagos.'], 409);
        }

        $branch = Branch::withoutGlobalScopes()->find($user->branch_id);
        $enabled = $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'];

        $validated = $request->validate([
            'amount_received' => 'required|numeric|gt:0',
            'method' => ['required', 'string', Rule::in($enabled)],
            'excluded_sale_ids' => 'nullable|array',
            'excluded_sale_ids.*' => 'integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        // La distribución FIFO vive en CustomerGlobalPaymentService (compartida
        // con la web y el asistente IA). abort(422) del servicio sale como JSON
        // por el exception handler de la API.
        $result = $this->globalPayments->apply($found, $user, [
            'amount_received' => (float) $validated['amount_received'],
            'method' => $validated['method'],
            'excluded_sale_ids' => $validated['excluded_sale_ids'] ?? [],
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->broadcast($result['affected_sale_ids']);

        $cp = $result['customer_payment'];

        return response()->json([
            'customer_payment' => [
                'id' => $cp->id,
                'folio' => $cp->folio,
                'method' => $cp->method,
                'amount_received' => (float) $cp->amount_received,
                'amount_applied' => (float) $cp->amount_applied,
                'change_given' => (float) $cp->change_given,
                'sales_affected_count' => $cp->sales_affected_count,
            ],
            'applied' => $result['applied'],
        ], 201);
    }

    /** Cancela un cobro global (solo admin-sucursal). */
    public function destroy(Request $request, int $customer, int $payment): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('admin-sucursal') || $user->hasRole('superadmin'), 403, 'No tienes permiso para cancelar cobros globales.');

        $found = $this->findCustomer($request, $customer);
        $cp = CustomerPayment::withoutGlobalScopes()
            ->where('customer_id', $found->id)
            ->findOrFail($payment);

        if ($cp->cancelled_at !== null) {
            return response()->json(['message' => 'Este cobro ya fue cancelado.'], 422);
        }

        $validated = $request->validate(['cancel_reason' => 'required|string|max:500']);

        $affected = [];
        DB::transaction(function () use ($cp, $user, $validated, &$affected) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$cp->branch_id]);

            $children = Payment::where('customer_payment_id', $cp->id)->get();
            $saleIds = $children->pluck('sale_id')->unique()->values();
            foreach ($children as $child) {
                $child->delete();
            }
            $sales = Sale::withoutGlobalScopes()->whereIn('id', $saleIds)->lockForUpdate()->get();
            foreach ($sales as $sale) {
                $this->salePaymentService->recalculate($sale, $user);
            }
            $affected = $saleIds->all();

            $cp->update(['cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $validated['cancel_reason']]);
            $cp->delete();
        });

        foreach ($affected as $saleId) {
            $sale = Sale::withoutGlobalScopes()->find($saleId);
            if ($sale) {
                app(RecalculateClosedShifts::class)->forSale($sale);
            }
        }
        $this->broadcast($affected);

        return response()->json(['message' => "Cobro {$cp->folio} cancelado.", 'affected_sale_ids' => $affected]);
    }

    private function findCustomer(Request $request, int $customer): Customer
    {
        return Customer::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->findOrFail($customer);
    }

    /** @param  array<int, int>  $saleIds */
    private function broadcast(array $saleIds): void
    {
        foreach ($saleIds as $saleId) {
            $sale = Sale::withoutGlobalScopes()->find($saleId);
            if (! $sale) {
                continue;
            }
            try {
                SaleUpdated::dispatch($sale);
            } catch (\Throwable $e) {
                Log::warning('SaleUpdated broadcast failed', ['sale_id' => $saleId, 'error' => $e->getMessage()]);
            }
        }
    }
}
