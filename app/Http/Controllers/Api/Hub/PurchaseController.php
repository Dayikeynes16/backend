<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\AiDraftStatus;
use App\Http\Controllers\Concerns\HandlesPurchases;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubPurchaseResource;
use App\Models\AiPurchaseDraft;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use App\Services\Ai\AiPurchaseDraftService;
use App\Services\PurchaseAttachmentService;
use App\Services\PurchaseFolioGenerator;
use App\Services\PurchasePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
        $branch = Branch::withoutGlobalScopes()->find($user->branch_id);

        return response()->json([
            'data' => HubPurchaseResource::collection($purchases),
            'providers' => $providers->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'payment_methods' => $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'],
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

        // Si vino de la captura por IA, consume el draft (mueve la foto de la
        // factura a la compra y lo marca consumido).
        $draftId = (int) $request->input('ai_draft_id', 0);
        if ($draftId > 0) {
            $draft = AiPurchaseDraft::query()
                ->where('id', $draftId)
                ->where('tenant_id', $user->tenant_id)
                ->where('status', AiDraftStatus::Ready->value)
                ->first();
            if ($draft) {
                app(PurchaseAttachmentService::class)->attachFromDraft($purchase, $draft, $user->id);
                $draft->update([
                    'status' => AiDraftStatus::Consumed->value,
                    'purchase_id' => $purchase->id,
                    'consumed_at' => now(),
                ]);
            }
        }

        $purchase->refresh()->load(['provider:id,name', 'items', 'attachments']);

        return response()->json(['data' => HubPurchaseResource::make($purchase)], 201);
    }

    /**
     * Borrador de compra desde IA (texto/imagen/audio → GPT-4o/Whisper). Reusa
     * AiPurchaseDraftService; devuelve la propuesta para prerrellenar el form.
     */
    public function aiDraft(Request $request, AiPurchaseDraftService $service): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureModuleEnabled($user->branch_id);

        $maxAudioKb = (int) (config('ai.expenses.max_audio_bytes', 10 * 1024 * 1024) / 1024);

        $validated = $request->validate([
            'input_text' => ['nullable', 'string', 'max:'.config('ai.expenses.max_input_text_length', 2000)],
            'attachments' => 'nullable|array|max:'.config('ai.purchases.max_images', 5),
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.(PurchaseAttachmentService::MAX_BYTES / 1024)],
            'audio' => ['nullable', 'file', 'mimes:webm,ogg,oga,mp3,mpga,m4a,mp4,wav,flac,aac', 'max:'.$maxAudioKb],
        ]);

        $text = $validated['input_text'] ?? null;
        $files = $request->file('attachments') ?? [];
        $audio = $request->file('audio');

        if (trim((string) $text) === '' && $files === [] && $audio === null) {
            return response()->json(['message' => 'Aporta al menos un texto, una imagen o un audio para analizar.'], 422);
        }

        try {
            $draft = $service->createDraft($user->tenant, $user, $text, $files, $audio);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No pude analizar la compra. Intenta de nuevo o captúrala manualmente.'], 502);
        }

        return response()->json([
            'draft_id' => $draft->id,
            'status' => $draft->status->value,
            'proposal' => $draft->parsed_proposal,
            'audio_transcription' => $draft->audio_transcription,
        ]);
    }

    public function show(Request $request, int $purchase): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);

        $found = Purchase::where('branch_id', $user->branch_id)
            ->with(['provider:id,name', 'items', 'payments', 'attachments'])
            ->findOrFail($purchase);

        return response()->json(['data' => HubPurchaseResource::make($found)]);
    }

    public function storeAttachment(Request $request, int $purchase, PurchaseAttachmentService $attachments): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $found = Purchase::where('branch_id', $user->branch_id)->findOrFail($purchase);

        $request->validate([
            'attachments' => 'required|array|max:'.PurchaseAttachmentService::MAX_PER_PURCHASE,
            'attachments.*' => [
                'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:'.implode(',', PurchaseAttachmentService::ALLOWED_MIMES),
                'max:'.(PurchaseAttachmentService::MAX_BYTES / 1024),
            ],
        ]);

        $attachments->attach($found, $request->file('attachments'), $user->id);

        return response()->json(['data' => HubPurchaseResource::make($found->refresh()->load(['provider:id,name', 'items', 'payments', 'attachments']))]);
    }

    public function downloadAttachment(Request $request, int $purchase, int $attachment): StreamedResponse
    {
        $user = $request->user();
        $found = Purchase::where('branch_id', $user->branch_id)->findOrFail($purchase);
        $att = $found->attachments()->findOrFail($attachment);

        return Storage::disk(PurchaseAttachmentService::disk())->download($att->path, $att->original_name);
    }

    public function destroyAttachment(Request $request, int $purchase, int $attachment): JsonResponse
    {
        $user = $request->user();
        $found = Purchase::where('branch_id', $user->branch_id)->findOrFail($purchase);
        $att = $found->attachments()->findOrFail($attachment);

        Storage::disk(PurchaseAttachmentService::disk())->delete($att->path);
        $att->delete();

        return response()->json(['data' => HubPurchaseResource::make($found->refresh()->load(['provider:id,name', 'items', 'payments', 'attachments']))]);
    }

    public function update(Request $request, int $purchase, PurchasePaymentService $payments): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);
        $this->ensureModuleEnabled($user->branch_id);

        $found = Purchase::where('branch_id', $user->branch_id)->findOrFail($purchase);
        $request->merge(['branch_id' => $user->branch_id]);

        // Reusa la lógica de edición compartida (valida, reemplaza items,
        // recalcula). Devuelve un redirect que aquí se ignora.
        $this->updatePurchase($request, $found, $payments);

        $found->refresh()->load(['provider:id,name', 'items', 'payments']);

        return response()->json(['data' => HubPurchaseResource::make($found)]);
    }

    public function cancel(Request $request, int $purchase): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);

        $found = Purchase::where('branch_id', $user->branch_id)->findOrFail($purchase);
        $this->cancelPurchase($request, $found);

        return response()->json(['data' => HubPurchaseResource::make($found->refresh()->load(['provider:id,name', 'items', 'payments']))]);
    }

    public function addPayment(Request $request, int $purchase, PurchasePaymentService $payments): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);

        $found = Purchase::where('branch_id', $user->branch_id)->findOrFail($purchase);

        $branch = Branch::withoutGlobalScopes()->find($user->branch_id);
        $enabled = $branch?->enabledPaymentMethods() ?? ['cash', 'card', 'transfer'];

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payment_method' => ['required', 'string', Rule::in($enabled)],
            'reference' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:500',
        ]);

        // Un pago en efectivo sale del cajón → exige turno abierto y se ata a él.
        $shiftId = null;
        if ($validated['payment_method'] === 'cash') {
            $shift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->first();
            if (! $shift) {
                return response()->json(['message' => 'Abre un turno antes de registrar un pago en efectivo.'], 409);
            }
            $shiftId = $shift->id;
        }

        // applyPayment lanza ValidationException (422) si sobre-paga.
        $payments->applyPayment($found, [
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'user_id' => $user->id,
            'cash_register_shift_id' => $shiftId,
        ]);

        return response()->json(['data' => HubPurchaseResource::make($found->refresh()->load(['provider:id,name', 'items', 'payments']))]);
    }

    public function cancelPayment(Request $request, int $purchase, int $payment, PurchasePaymentService $payments): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        Auth::setUser($user);

        $found = Purchase::where('branch_id', $user->branch_id)->findOrFail($purchase);
        $pago = ProviderPayment::where('purchase_id', $found->id)->findOrFail($payment);

        $validated = $request->validate(['reason' => 'required|string|max:500']);
        $payments->cancelPayment($pago, $user->id, $validated['reason']);

        return response()->json(['data' => HubPurchaseResource::make($found->refresh()->load(['provider:id,name', 'items', 'payments']))]);
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
