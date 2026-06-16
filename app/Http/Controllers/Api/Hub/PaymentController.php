<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\SalePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private SalePaymentService $payments) {}

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
