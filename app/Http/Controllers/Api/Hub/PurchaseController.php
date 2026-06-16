<?php

namespace App\Http\Controllers\Api\Hub;

use App\Http\Controllers\Concerns\HandlesPurchases;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubPurchaseResource;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\Purchase;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Compras en efectivo del cajero. Reusa el trait HandlesPurchases
 * (validación + creación de compra con items + folio) igual que la web.
 */
class PurchaseController extends Controller
{
    use HandlesPurchases;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureModuleEnabled($user->branch_id);

        $purchases = Purchase::where('branch_id', $user->branch_id)
            ->where('created_by', $user->id)
            ->with(['provider:id,name', 'items'])
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $providers = Provider::where('status', 'active')->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => HubPurchaseResource::collection($purchases),
            'providers' => $providers->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
        ]);
    }

    public function store(Request $request, PurchaseFolioGenerator $folios, PurchasePaymentService $payments): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureModuleEnabled($user->branch_id);

        $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
        if (! $shift) {
            return response()->json(['message' => 'Abre un turno antes de registrar una compra.'], 409);
        }

        // El branch lo fija el turno; se inyecta para la validación compartida.
        $request->merge(['branch_id' => $shift->branch_id]);
        $validated = $this->validatedPurchasePayload($request);

        $paid = round((float) $request->input('paid_amount', 0), 2);
        if ($paid < 0) {
            $paid = 0;
        }

        $purchase = $this->createPurchaseWithItems(
            $validated,
            $shift->branch_id,
            $user->tenant,
            $folios,
            ['cash_register_shift_id' => $shift->id],
        );

        if ($paid > 0) {
            $payments->applyPayment($purchase, [
                'amount' => $paid,
                'payment_method' => 'cash',
                'user_id' => $user->id,
                'cash_register_shift_id' => $shift->id,
            ]);
        } else {
            $payments->recalculate($purchase);
        }

        $purchase->refresh()->load(['provider:id,name', 'items']);

        return response()->json(['data' => HubPurchaseResource::make($purchase)], 201);
    }

    private function ensureModuleEnabled(?int $branchId): void
    {
        $branch = Branch::withoutGlobalScopes()->find($branchId);

        abort_unless(
            $branch && $branch->cashier_purchases_enabled,
            403,
            'El registro de compras no está habilitado para tu sucursal.'
        );
    }

    // ─── Contrato del trait HandlesPurchases ───────────────────────────────
    // El hub sobreescribe index()/store(); estos satisfacen los métodos
    // abstractos del trait (usados por sus flujos no expuestos aquí).

    protected function resolveBranchIdForWrite(Request $request): int
    {
        return (int) $request->user()->branch_id;
    }

    protected function applyBranchScopeToQuery(Builder $query): Builder
    {
        return $query->where('branch_id', Auth::user()->branch_id);
    }

    protected function assertCanMutate(Purchase $purchase): void
    {
        abort_unless($purchase->branch_id === Auth::user()->branch_id, 404);
    }

    protected function redirectAfterWrite(Request $request, string $message): RedirectResponse
    {
        return redirect('/');
    }
}
