<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AiDraftStatus;
use App\Enums\PurchaseStatus;
use App\Models\AiPurchaseDraft;
use App\Models\Branch;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Services\PurchaseAttachmentService;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
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
        if ($id) {
            $found = PurchaseProduct::where('tenant_id', $tenantId)->whereKey($id)->first();
            if ($found) {
                return $found;
            }
        }

        $name = trim($name);
        $byName = PurchaseProduct::where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($byName) {
            return $byName;
        }

        return PurchaseProduct::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'unit' => $unit ?: 'kg',
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Crea la Purchase + sus PurchaseItem (resolviendo el catálogo) en una
     * transacción. `$extra` permite sellar atributos adicionales (p. ej.
     * cash_register_shift_id desde la caja).
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $extra
     */
    protected function createPurchaseWithItems(array $validated, int $branchId, \App\Models\Tenant $tenant, PurchaseFolioGenerator $folios, array $extra = []): Purchase
    {
        return DB::transaction(function () use ($validated, $branchId, $tenant, $folios, $extra) {
            $subtotal = 0.0;
            foreach ($validated['items'] as $line) {
                $subtotal += (float) $line['quantity'] * (float) $line['unit_price'];
            }
            $subtotal = round($subtotal, 2);

            $purchase = Purchase::create(array_merge([
                'tenant_id' => $tenant->id,
                'branch_id' => $branchId,
                'provider_id' => $validated['provider_id'],
                'folio' => $folios->nextFolio($tenant->id),
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchased_at' => CarbonImmutable::parse($validated['purchased_at']),
                'status' => PurchaseStatus::Received,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'amount_paid' => 0,
                'amount_pending' => $subtotal,
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
            ], $extra));

            foreach ($validated['items'] as $line) {
                $lineSubtotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $product = $this->resolvePurchaseProduct($tenant->id, $line['purchase_product_id'] ?? null, $line['concept'], $line['unit']);
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'purchase_product_id' => $product->id,
                    'concept' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $lineSubtotal,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $purchase;
        });
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

    public function update(Request $request, Purchase $compra, PurchasePaymentService $payments): RedirectResponse
    {
        $purchase = $compra;
        $this->assertCanMutate($purchase);
        if ($purchase->status === PurchaseStatus::Cancelled) {
            abort(422, 'No se puede editar una compra cancelada.');
        }

        $validated = $this->validatedPurchasePayload($request, $purchase);
        $branchId = $this->resolveBranchIdForWrite($request);
        $this->assertBranchBelongsToTenant($branchId, $purchase->tenant_id);

        DB::transaction(function () use ($purchase, $validated, $branchId) {
            $subtotal = 0.0;
            foreach ($validated['items'] as $line) {
                $subtotal += (float) $line['quantity'] * (float) $line['unit_price'];
            }
            $subtotal = round($subtotal, 2);

            $purchase->update([
                'branch_id' => $branchId,
                'provider_id' => $validated['provider_id'],
                'invoice_number' => $validated['invoice_number'] ?? null,
                'purchased_at' => CarbonImmutable::parse($validated['purchased_at']),
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Estrategia simple para items: borrar todos y recrear. Mantiene
            // la BD coherente sin lógica de diff que añade complejidad sin
            // ganancia para F2.
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

        // Si subió adjuntos nuevos en el update.
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

        return $this->redirectAfterWrite($request, 'Compra actualizada.');
    }

    // ─── Cancel ──────────────────────────────────────────────────────────

    public function cancel(Request $request, Purchase $compra): RedirectResponse
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

    // ─── Serialización compartida ────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    protected function serializePurchase(Purchase $p): array
    {
        return [
            'id' => $p->id,
            'folio' => $p->folio,
            'invoice_number' => $p->invoice_number,
            'purchased_at' => $p->purchased_at?->toIso8601String(),
            'status' => $p->status->value,
            'provider' => $p->provider ? [
                'id' => $p->provider->id,
                'name' => $p->provider->name,
            ] : null,
            'branch' => $p->branch ? [
                'id' => $p->branch->id,
                'name' => $p->branch->name,
            ] : null,
            'subtotal' => (float) $p->subtotal,
            'total' => (float) $p->total,
            'amount_paid' => (float) $p->amount_paid,
            'amount_pending' => (float) $p->amount_pending,
            'payment_status' => $this->paymentStatus($p),
            'notes' => $p->notes,
            'items' => $p->items->map(fn (PurchaseItem $i) => [
                'id' => $i->id,
                'purchase_product_id' => $i->purchase_product_id,
                'concept' => $i->concept,
                'quantity' => (float) $i->quantity,
                'unit' => $i->unit,
                'unit_price' => (float) $i->unit_price,
                'subtotal' => (float) $i->subtotal,
                'notes' => $i->notes,
            ])->values(),
            'payments' => $p->payments->map(fn ($pay) => [
                'id' => $pay->id,
                'paid_at' => $pay->paid_at?->toIso8601String(),
                'amount' => (float) $pay->amount,
                'payment_method' => $pay->payment_method?->value,
                'reference' => $pay->reference,
                'notes' => $pay->notes,
                'cancelled_at' => $pay->cancelled_at?->toIso8601String(),
                'cancel_reason' => $pay->cancel_reason,
            ])->values(),
            'attachments' => $p->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
            ])->values(),
            'cancelled_at' => $p->cancelled_at?->toIso8601String(),
            'cancel_reason' => $p->cancel_reason,
        ];
    }

    private function paymentStatus(Purchase $p): string
    {
        if ($p->status === PurchaseStatus::Cancelled) {
            return 'cancelled';
        }
        $paid = (float) $p->amount_paid;
        $total = (float) $p->total;
        if ($paid <= 0) {
            return 'pending';
        }
        if ($paid >= $total) {
            return 'paid';
        }

        return 'partial';
    }

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

        $status = $request->input('status', 'all');
        if ($status === 'received') {
            $query->where('status', PurchaseStatus::Received);
        } elseif ($status === 'cancelled') {
            $query->where('status', PurchaseStatus::Cancelled);
        }

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
