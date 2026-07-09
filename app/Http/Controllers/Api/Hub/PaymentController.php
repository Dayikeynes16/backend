<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private SalePaymentService $payments) {}

    /**
     * Listado de pagos del día (pantalla de Pagos). Espeja la web:
     * admin-sucursal ve TODOS los cobros de la sucursal y puede filtrar por
     * cajero (`user_id`); el cajero solo ve los suyos (Caja\PagosController).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'method' => 'nullable|string',
            'date' => 'nullable|date',
            'user_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $branchId = $user->branch_id;
        $isAdmin = $user->hasRole('admin-sucursal');
        $date = $request->date ?: today()->toDateString();

        $baseQuery = Payment::whereHas('sale', fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->whereDate('payments.created_at', $date);

        if ($isAdmin) {
            $baseQuery->when($request->user_id, fn ($q, $id) => $q->where('payments.user_id', $id));
        } else {
            $baseQuery->where('payments.user_id', $user->id);
        }

        // Totales con split "de hoy" vs "cuentas anteriores" (JOIN a sales).
        $totals = (clone $baseQuery)
            ->join('sales as s', 's.id', '=', 'payments.sale_id')
            ->selectRaw("
                COALESCE(SUM(payments.amount), 0) AS total,
                COALESCE(SUM(CASE WHEN payments.method = 'cash' THEN payments.amount END), 0) AS cash,
                COALESCE(SUM(CASE WHEN payments.method = 'card' THEN payments.amount END), 0) AS card,
                COALESCE(SUM(CASE WHEN payments.method = 'transfer' THEN payments.amount END), 0) AS transfer,
                COALESCE(SUM(CASE WHEN DATE(s.created_at) = DATE(payments.created_at) THEN payments.amount END), 0) AS from_today,
                COALESCE(SUM(CASE WHEN DATE(s.created_at) < DATE(payments.created_at) THEN payments.amount END), 0) AS from_previous
            ")
            ->first();

        $payments = $baseQuery
            ->with([
                'sale:id,folio,total,status,branch_id,amount_pending,customer_id',
                'sale.customer:id,name',
                'user:id,name',
            ])
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->paginate(20);

        $data = $payments->getCollection()->map(fn (Payment $p) => [
            'id' => $p->id,
            'amount' => (float) $p->amount,
            'method' => $p->method,
            'created_at' => $p->created_at?->toIso8601String(),
            'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
            'sale' => $p->sale ? [
                'id' => $p->sale->id,
                'folio' => $p->sale->folio,
                'total' => (float) $p->sale->total,
                'status' => $p->sale->status instanceof \BackedEnum ? $p->sale->status->value : $p->sale->status,
                'amount_pending' => (float) $p->sale->amount_pending,
                'customer' => $p->sale->customer ? ['id' => $p->sale->customer->id, 'name' => $p->sale->customer->name] : null,
            ] : null,
        ])->values();

        $branch = Branch::withoutGlobalScopes()->find($branchId);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'total' => $payments->total(),
            ],
            'summary' => [
                'date' => $date,
                'total' => (float) $totals->total,
                'by_method' => [
                    'cash' => (float) $totals->cash,
                    'card' => (float) $totals->card,
                    'transfer' => (float) $totals->transfer,
                ],
                'from_today' => (float) $totals->from_today,
                'from_previous' => (float) $totals->from_previous,
                'payment_count' => $payments->total(),
            ],
            'users' => $isAdmin
                ? User::where('branch_id', $branchId)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()
                : [],
            'payment_methods' => $branch?->payment_methods_enabled ?? ['cash', 'card', 'transfer'],
            'is_admin' => $isAdmin,
        ]);
    }

    public function store(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();

        // Resolución manual con filtro de sucursal (sin TenantScope): 404 si no
        // es de la sucursal del usuario del token.
        $sale = Sale::withoutGlobalScopes()
            ->where('branch_id', $user->branch_id)
            ->findOrFail($sale);

        if (in_array($sale->status, [SaleStatus::Completed, SaleStatus::Cancelled], true)) {
            return response()->json(['message' => 'No se pueden registrar pagos en esta venta.'], 422);
        }

        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->exists();
        if (! $hasOpenShift) {
            return response()->json(['message' => 'Abre un turno antes de cobrar.'], 409);
        }

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $allowed = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        $validated = $request->validate([
            'method' => 'required|in:'.implode(',', $allowed),
            'amount' => 'required|numeric|gt:0',
            'client_reference' => 'nullable|string|max:64',
        ]);

        $clientReference = $validated['client_reference'] ?? null;

        // Idempotencia: si ya existe un pago con este (sale_id, client_reference),
        // devolverlo sin crear otro (reintento del hub).
        if ($clientReference !== null) {
            $existing = Payment::where('sale_id', $sale->id)
                ->where('client_reference', $clientReference)
                ->first();

            if ($existing) {
                $sale->load(['items', 'payments']);

                return response()->json([
                    'payment' => ['id' => $existing->id, 'method' => $existing->method, 'amount' => (float) $existing->amount],
                    'change' => 0.0,
                    'sale' => HubSaleResource::make($sale),
                ], 200);
            }
        }

        $change = 0.0;
        $payment = DB::transaction(function () use ($sale, $user, $validated, $clientReference, &$change) {
            $actualPayment = min((float) $validated['amount'], (float) $sale->amount_pending);

            $payment = Payment::create([
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'method' => $validated['method'],
                'amount' => round($actualPayment, 2),
                'client_reference' => $clientReference,
            ]);

            $this->payments->recalculate($sale, $user);
            $change = round((float) $validated['amount'] - $actualPayment, 2);

            return $payment;
        });

        $sale->load(['items', 'payments']);

        return response()->json([
            'payment' => ['id' => $payment->id, 'method' => $payment->method, 'amount' => (float) $payment->amount],
            'change' => $change,
            'sale' => HubSaleResource::make($sale),
        ], 201);
    }
}
