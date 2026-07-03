<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AiDraftStatus;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Models\AiPurchaseDraft;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\PurchaseAttachmentService;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
use App\Services\Purchases\PurchaseWriter;
use App\Services\RecalculateClosedShifts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lógica común CRUD de Purchase para Empresa y Sucursal. La única diferencia
 * entre roles es:
 *  - admin-empresa: elige branch_id de cualquier sucursal del tenant
 *  - admin-sucursal: branch_id forzado a $user->branch_id; index/show scopeados
 *
 * El controller concreto sólo provee `enforceBranchScope()` y las rutas.
 */
trait HandlesPurchases
{
    use SerializesPurchases;

    /**
     * Devuelve el branch_id que debe usarse al crear/editar. admin-empresa
     * recibe lo que el form mandó (tras validar pertenencia al tenant);
     * admin-sucursal recibe forzosamente su propia sucursal.
     */
    abstract protected function resolveBranchIdForWrite(Request $request): int;

    /**
     * Aplica el scope de sucursal al listado/show. admin-empresa no lo restringe;
     * admin-sucursal sí filtra por su `branch_id`.
     */
    abstract protected function applyBranchScopeToQuery(Builder $query): Builder;

    /**
     * Si admin-sucursal intenta operar sobre una compra que NO es de su
     * sucursal, abort(403). admin-empresa siempre puede.
     */
    abstract protected function assertCanMutate(Purchase $purchase): void;

    // ─── Validación de payload común ─────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validatedPurchasePayload(Request $request, ?Purchase $existing = null): array
    {
        $tenant = app('tenant');

        $rules = [
            'provider_id' => [
                'required', 'integer',
                Rule::exists('providers', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->whereNull('deleted_at')),
            ],
            'branch_id' => [
                'required', 'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)),
            ],
            'invoice_number' => 'nullable|string|max:60',
            'purchased_at' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.purchase_product_id' => [
                'nullable', 'integer',
                Rule::exists('purchase_products', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->whereNull('deleted_at')),
            ],
            'items.*.concept' => 'required|string|max:160',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit' => 'required|string|max:10',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',
        ];

        return $request->validate($rules);
    }

    /**
     * Resuelve la línea a un producto de catálogo: usa el id si vino, si no
     * busca por nombre (case-insensitive) dentro del tenant y, si no existe,
     * lo crea. Devuelve el PurchaseProduct (su name se usa como snapshot).
     */
    private function resolvePurchaseProduct(int $tenantId, ?int $id, string $name, string $unit): PurchaseProduct
    {
        return app(PurchaseWriter::class)->resolvePurchaseProduct($tenantId, $id, $name, $unit, Auth::user());
    }

    /**
     * Crea la Purchase + sus PurchaseItem (resolviendo el catálogo) en una
     * transacción. `$extra` permite sellar atributos adicionales (p. ej.
     * cash_register_shift_id desde la caja). Delega en {@see PurchaseWriter}.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $extra
     */
    protected function createPurchaseWithItems(array $validated, int $branchId, Tenant $tenant, PurchaseFolioGenerator $folios, array $extra = []): Purchase
    {
        return app(PurchaseWriter::class)->buildPurchaseWithItems($tenant, Auth::user(), $validated, $branchId, $extra);
    }

    // ─── Store ───────────────────────────────────────────────────────────

    public function store(Request $request, PurchaseFolioGenerator $folios, PurchasePaymentService $payments): RedirectResponse
    {
        $tenant = app('tenant');
        $validated = $this->validatedPurchasePayload($request);

        // Sobreescribimos branch_id según rol (defensa).
        $branchId = $this->resolveBranchIdForWrite($request);
        $this->assertBranchBelongsToTenant($branchId, $tenant->id);

        $purchase = $this->createPurchaseWithItems($validated, $branchId, $tenant, $folios);

        // Adjuntos opcionales en el mismo POST.
        if ($request->hasFile('attachments')) {
            $request->validate([
                'attachments' => 'array|max:'.PurchaseAttachmentService::MAX_PER_PURCHASE,
                'attachments.*' => [
                    'file',
                    'mimes:jpg,jpeg,png,webp,pdf',
                    'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                    'max:'.(PurchaseAttachmentService::MAX_BYTES / 1024),
                ],
            ]);
            app(PurchaseAttachmentService::class)->attach($purchase, $request->file('attachments'), Auth::id());
        }

        // Si el form venía prerellenado por IA, consume el draft: mueve sus
        // adjuntos a la compra recién creada y marca el draft como `consumed`.
        // Si el draft no pertenece al tenant o no está `ready`, se ignora en
        // silencio (la compra ya se creó por la vía manual).
        if ($request->filled('ai_draft_id')) {
            $this->consumeAiDraft($purchase, (int) $request->input('ai_draft_id'));
        }

        $payments->recalculate($purchase);

        app(AuditLogger::class)->logCreated($purchase);

        return $this->redirectAfterWrite($request, 'Compra registrada.');
    }

    private function consumeAiDraft(Purchase $purchase, int $draftId): void
    {
        DB::transaction(function () use ($purchase, $draftId) {
            $draft = AiPurchaseDraft::query()
                ->where('id', $draftId)
                ->where('tenant_id', $purchase->tenant_id)
                ->where('status', AiDraftStatus::Ready)
                ->lockForUpdate()
                ->first();

            if (! $draft) {
                return;
            }

            app(PurchaseAttachmentService::class)
                ->attachFromDraft($purchase, $draft, Auth::id());

            $draft->update([
                'status' => AiDraftStatus::Consumed,
                'purchase_id' => $purchase->id,
                'consumed_at' => now(),
            ]);
        });
    }

    // ─── Update ──────────────────────────────────────────────────────────

    public function updatePurchase(Request $request, Purchase $compra, PurchasePaymentService $payments): RedirectResponse
    {
        $purchase = $compra;
        $this->assertCanMutate($purchase);
        if ($purchase->status === PurchaseStatus::Cancelled) {
            abort(422, 'No se puede editar una compra cancelada.');
        }

        $validated = $this->validatedPurchasePayload($request, $purchase);
        $branchId = $this->resolveBranchIdForWrite($request);
        $this->assertBranchBelongsToTenant($branchId, $purchase->tenant_id);

        $newSubtotal = 0.0;
        foreach ($validated['items'] as $line) {
            $newSubtotal += (float) $line['quantity'] * (float) $line['unit_price'];
        }
        $newSubtotal = round($newSubtotal, 2);
        if ($newSubtotal + 0.001 < (float) $purchase->amount_paid) {
            abort(422, 'El total no puede ser menor a lo ya pagado ($'.number_format((float) $purchase->amount_paid, 2).'). Cancela un pago primero.');
        }

        $auditor = app(AuditLogger::class);
        $before = $auditor->purchaseSnapshot($purchase->loadMissing('provider', 'items'));

        DB::transaction(function () use ($purchase, $validated, $branchId, $newSubtotal) {
            $purchase->update([
                'branch_id' => $branchId,
                'provider_id' => $validated['provider_id'],
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchased_at' => CarbonImmutable::parse($validated['purchased_at']),
                'subtotal' => $newSubtotal,
                'total' => $newSubtotal,
                'notes' => $validated['notes'] ?? null,
            ]);

            $purchase->items()->delete();
            foreach ($validated['items'] as $line) {
                $product = $this->resolvePurchaseProduct($purchase->tenant_id, $line['purchase_product_id'] ?? null, $line['concept'], $line['unit']);
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'purchase_product_id' => $product->id,
                    'concept' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    'notes' => $line['notes'] ?? null,
                ]);
            }
        });

        if ($request->hasFile('attachments')) {
            $request->validate([
                'attachments.*' => [
                    'file',
                    'mimes:jpg,jpeg,png,webp,pdf',
                    'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                    'max:'.(PurchaseAttachmentService::MAX_BYTES / 1024),
                ],
            ]);
            app(PurchaseAttachmentService::class)->attach($purchase, $request->file('attachments'), Auth::id());
        }

        $payments->recalculate($purchase);

        $after = $auditor->purchaseSnapshot($purchase->fresh()->loadMissing('provider', 'items'));
        $auditor->logUpdatedIfChanged($purchase, $before, $after);

        return $this->redirectAfterWrite($request, 'Compra actualizada.');
    }

    // ─── Cancel ──────────────────────────────────────────────────────────

    public function cancelPurchase(Request $request, Purchase $compra): RedirectResponse
    {
        $purchase = $compra;
        $this->assertCanMutate($purchase);
        if ($purchase->status === PurchaseStatus::Cancelled) {
            return back()->with('success', 'La compra ya estaba cancelada.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $purchase->update([
            'status' => PurchaseStatus::Cancelled,
            'cancelled_by' => Auth::id(),
            'cancelled_at' => now(),
            'cancel_reason' => $validated['reason'],
        ]);

        // Devolver el efectivo: cancelar los pagos en efectivo vivos y
        // recalcular los cortes CERRADOS afectados (los abiertos suman en vivo).
        $payments = app(PurchasePaymentService::class);
        $affectedShiftIds = [];
        $cashPayments = $purchase->payments()
            ->whereNull('cancelled_at')
            ->where('payment_method', PaymentMethod::Cash->value)
            ->get();
        foreach ($cashPayments as $pago) {
            if ($pago->cash_register_shift_id) {
                $affectedShiftIds[$pago->cash_register_shift_id] = true;
            }
            $payments->cancelPayment($pago, Auth::id(), 'Compra cancelada: '.$validated['reason']);
        }
        $recalc = app(RecalculateClosedShifts::class);
        foreach (array_keys($affectedShiftIds) as $shiftId) {
            $shift = CashRegisterShift::find($shiftId);
            if ($shift && $shift->closed_at) {
                $recalc->forShift($shift);
            }
        }

        app(AuditLogger::class)->logCancelled($purchase, $validated['reason']);

        return back()->with('success', 'Compra cancelada.');
    }

    private function assertBranchBelongsToTenant(int $branchId, int $tenantId): void
    {
        $belongs = Branch::query()->where('id', $branchId)->where('tenant_id', $tenantId)->exists();
        if (! $belongs) {
            abort(422, 'La sucursal seleccionada no pertenece al tenant.');
        }
    }

    abstract protected function redirectAfterWrite(Request $request, string $message): RedirectResponse;

    /**
     * Aplica filtros comunes a la query del index.
     */
    protected function applyIndexFilters(Builder $query, Request $request): Builder
    {
        $tenant = app('tenant');

        $from = $request->input('from');
        $to = $request->input('to');
        if ($from) {
            $query->whereDate('purchased_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('purchased_at', '<=', $to);
        }

        if ($providerId = $request->integer('provider_id')) {
            $query->where('provider_id', $providerId);
        }

        // Las compras canceladas no se listan (siguen en BD para reportes).
        $query->where('status', '!=', PurchaseStatus::Cancelled);

        $paymentStatus = $request->input('payment_status');
        if ($paymentStatus === 'pending') {
            $query->where('amount_paid', '<=', 0)->where('status', '!=', PurchaseStatus::Cancelled);
        } elseif ($paymentStatus === 'partial') {
            $query->whereColumn('amount_paid', '>', DB::raw('0'))
                ->whereColumn('amount_paid', '<', DB::raw('total'));
        } elseif ($paymentStatus === 'paid') {
            $query->whereColumn('amount_paid', '>=', DB::raw('total'));
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $query->where(function ($qq) use ($q) {
                $qq->where('folio', 'like', '%'.$q.'%')
                    ->orWhere('invoice_number', 'like', '%'.$q.'%')
                    ->orWhereHas('provider', fn ($pp) => $pp->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']));
            });
        }

        return $query;
    }
}
