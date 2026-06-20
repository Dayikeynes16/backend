<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\ProviderType;
use App\Enums\PurchaseStatus;
use App\Http\Controllers\Concerns\HandlesProviderWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubProviderResource;
use App\Models\Branch;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use App\Models\User;
use App\Services\PurchasePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Catálogo de proveedores (tenant-wide) para el admin-sucursal desde el hub.
 * Lectura siempre; crear/editar solo si la empresa habilitó el toggle
 * `branch_admin_providers_enabled` de su sucursal (mismo modelo que la web).
 * El borrado queda en empresa. Reusa la validación de HandlesProviderWrites;
 * sobreescribe store/update para devolver JSON en lugar de redirects Inertia.
 */
class ProviderController extends Controller
{
    use HandlesProviderWrites;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureAdminSucursal($user);

        $request->validate([
            'q' => 'nullable|string|max:100',
            'type' => ['nullable', Rule::enum(ProviderType::class)],
        ]);

        $canManage = $this->canManage($user);
        $search = trim((string) $request->input('q', ''));
        $type = $request->input('type');

        $providers = Provider::query()
            // Cuando puede gestionar mostramos también inactivos (para editar/reactivar);
            // de lo contrario solo los activos.
            ->when(! $canManage, fn ($q) => $q->where('status', 'active'))
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => HubProviderResource::collection($providers),
            'can_manage' => $canManage,
            'types' => array_map(
                fn (ProviderType $t) => ['value' => $t->value, 'label' => $t->label()],
                ProviderType::cases()
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureCanManage($user);

        $validated = $this->validatedProviderRequest($request, $user->tenant_id);

        $provider = Provider::create(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'active',
            'created_by' => $user->id,
        ]));

        return response()->json(['data' => HubProviderResource::make($provider)], 201);
    }

    public function update(Request $request, int $provider): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureCanManage($user);

        // Scope de tenant explícito: cross-tenant → 404.
        $found = Provider::where('tenant_id', $user->tenant_id)->findOrFail($provider);

        $validated = $this->validatedProviderRequest($request, $user->tenant_id, $found->id, withStatus: true);

        $found->update($validated);

        return response()->json(['data' => HubProviderResource::make($found->refresh())]);
    }

    /** Detalle: KPIs + deuda + última compra (scopeado a la sucursal). */
    public function show(Request $request, int $provider): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);
        $found = $this->findProvider($user, $provider);

        $base = fn () => Purchase::withoutGlobalScopes()
            ->where('provider_id', $found->id)
            ->where('branch_id', $user->branch_id)
            ->where('status', '!=', PurchaseStatus::Cancelled->value);

        $agg = (clone $base())->selectRaw('
            COUNT(*) AS cnt,
            COALESCE(SUM(total), 0) AS comprado,
            COALESCE(SUM(amount_paid), 0) AS pagado,
            COALESCE(SUM(amount_pending), 0) AS deuda')->first();

        $last = (clone $base())->orderByDesc('purchased_at')->orderByDesc('id')
            ->first(['id', 'folio', 'total', 'purchased_at']);

        return response()->json([
            'data' => HubProviderResource::make($found),
            'resumen' => [
                'compras_count' => (int) $agg->cnt,
                'total_comprado' => round((float) $agg->comprado, 2),
                'total_pagado' => round((float) $agg->pagado, 2),
                'deuda_actual' => round((float) $agg->deuda, 2),
                'ultima_compra' => $last ? [
                    'id' => $last->id,
                    'folio' => $last->folio,
                    'total' => (float) $last->total,
                    'purchased_at' => $last->purchased_at?->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    public function compras(Request $request, int $provider): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);
        $found = $this->findProvider($user, $provider);

        $purchases = Purchase::withoutGlobalScopes()
            ->where('provider_id', $found->id)
            ->where('branch_id', $user->branch_id)
            ->where('status', '!=', PurchaseStatus::Cancelled->value)
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => $purchases->getCollection()->map(fn (Purchase $p) => [
                'id' => $p->id,
                'folio' => $p->folio,
                'invoice_number' => $p->invoice_number,
                'total' => (float) $p->total,
                'amount_pending' => (float) $p->amount_pending,
                'purchased_at' => $p->purchased_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $purchases->currentPage(), 'last_page' => $purchases->lastPage(), 'total' => $purchases->total()],
        ]);
    }

    public function pagos(Request $request, int $provider): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);
        $found = $this->findProvider($user, $provider);

        $payments = ProviderPayment::withoutGlobalScopes()
            ->where('provider_id', $found->id)
            ->where('branch_id', $user->branch_id)
            ->with('purchase:id,folio')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => $payments->getCollection()->map(fn (ProviderPayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'payment_method' => $p->payment_method instanceof \BackedEnum ? $p->payment_method->value : $p->payment_method,
                'purchase_folio' => $p->purchase?->folio,
                'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'cancelled_at' => $p->cancelled_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $payments->currentPage(), 'last_page' => $payments->lastPage(), 'total' => $payments->total()],
        ]);
    }

    public function productos(Request $request, int $provider): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);
        $found = $this->findProvider($user, $provider);

        $rows = DB::table('purchase_items as pi')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('p.provider_id', $found->id)
            ->where('p.branch_id', $user->branch_id)
            ->where('p.status', '!=', PurchaseStatus::Cancelled->value)
            ->whereNull('p.deleted_at')
            ->groupBy('pi.concept', 'pi.unit')
            ->selectRaw('pi.concept, pi.unit,
                COALESCE(SUM(pi.quantity), 0) AS total_quantity,
                COALESCE(SUM(pi.subtotal), 0) AS total_amount,
                COUNT(*) AS times_bought')
            ->orderByDesc('total_amount')
            ->limit(100)
            ->get();

        return response()->json([
            'items' => $rows->map(fn ($r) => [
                'concept' => $r->concept,
                'unit' => $r->unit,
                'total_quantity' => (float) $r->total_quantity,
                'total_amount' => round((float) $r->total_amount, 2),
                'times_bought' => (int) $r->times_bought,
            ])->values(),
        ]);
    }

    /** Pago a cuenta del proveedor: FIFO sobre sus compras pendientes. */
    public function accountPayment(Request $request, int $provider, PurchasePaymentService $payments): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdminSucursal($user);
        app()->instance('tenant', $user->tenant);
        $found = $this->findProvider($user, $provider);

        $branch = Branch::withoutGlobalScopes()->find($user->branch_id);
        $enabled = $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'];

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payment_method' => ['required', 'string', Rule::in($enabled)],
            'reference' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:500',
        ]);

        $created = $payments->applyAccountPayment($found, [
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
        ]);

        return response()->json([
            'applied_count' => count($created),
            'amount' => round((float) $validated['amount'], 2),
        ], 201);
    }

    private function findProvider(User $user, int $provider): Provider
    {
        // Provider usa TenantScope; enlazamos el tenant para que filtre por él.
        app()->instance('tenant', $user->tenant);

        return Provider::where('tenant_id', $user->tenant_id)->findOrFail($provider);
    }

    private function ensureAdminSucursal(User $user): void
    {
        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'Solo el administrador de sucursal puede gestionar proveedores.'
        );
    }

    /**
     * El admin-sucursal puede gestionar proveedores si la empresa habilitó el
     * toggle en su sucursal. superadmin siempre puede.
     */
    private function canManage(User $user): bool
    {
        if ($user->hasRole('superadmin')) {
            return true;
        }

        if (! $user->hasRole('admin-sucursal')) {
            return false;
        }

        $branch = $user->branch_id ? Branch::withoutGlobalScopes()->find($user->branch_id) : null;

        return (bool) ($branch?->branch_admin_providers_enabled);
    }

    private function ensureCanManage(User $user): void
    {
        abort_unless(
            $this->canManage($user),
            403,
            'Tu empresa no ha habilitado la gestión de proveedores para tu sucursal.'
        );
    }
}
