<?php

namespace App\Http\Controllers\Api\Hub;

use App\Events\SaleUpdated;
use App\Exceptions\SaleItemEditNoOp;
use App\Exceptions\SaleItemEditNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemChange;
use App\Models\User;
use App\Services\SaleItemEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Edición de items de una venta desde el hub (solo admin-sucursal, paridad con
 * Sucursal\SaleItemController). Las reglas de dominio viven en SaleItemEditor
 * (estados Active/Pending, lock, presentaciones, recálculo vía
 * SalePaymentService); aquí solo validación y transporte JSON.
 */
class SaleItemController extends Controller
{
    public function __construct(protected SaleItemEditor $editor) {}

    public function store(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);
        $found = $this->findSale($request, $sale);

        $validated = $request->validate(array_merge([
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $found->tenant_id)),
            ],
            'presentation_id' => 'nullable|integer|exists:product_presentations,id',
            'quantity' => 'required|numeric|gt:0',
            'unit_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ], $this->reasonRules($user)));

        try {
            $this->editor->add(
                $found,
                [
                    'product_id' => (int) $validated['product_id'],
                    'presentation_id' => $validated['presentation_id'] ?? null,
                    'quantity' => $validated['quantity'],
                    'unit_price' => $validated['unit_price'],
                    'notes' => $validated['notes'] ?? null,
                ],
                $this->normalizeReason($request->input('reason')),
                $user,
            );
        } catch (SaleItemEditNotAllowed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->broadcastSaleUpdate($found);

        return $this->saleResponse($found, 201);
    }

    public function update(Request $request, int $sale, int $item): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);
        $found = $this->findSale($request, $sale);
        $foundItem = SaleItem::where('sale_id', $found->id)->findOrFail($item);

        $validated = $request->validate(array_merge([
            'quantity' => 'required|numeric|gt:0',
            'unit_price' => 'required|numeric|min:0',
        ], $this->reasonRules($user)));

        try {
            $this->editor->update(
                $found,
                $foundItem,
                [
                    'quantity' => $validated['quantity'],
                    'unit_price' => $validated['unit_price'],
                ],
                $this->normalizeReason($request->input('reason')),
                $user,
            );
        } catch (SaleItemEditNoOp|SaleItemEditNotAllowed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->broadcastSaleUpdate($found);

        return $this->saleResponse($found);
    }

    public function destroy(Request $request, int $sale, int $item): JsonResponse
    {
        $user = $request->user();
        app()->instance('tenant', $user->tenant);
        $this->ensureAdmin($user);
        $found = $this->findSale($request, $sale);
        $foundItem = SaleItem::where('sale_id', $found->id)->findOrFail($item);

        // Eliminar SIEMPRE exige motivo (regla dura, sin importar la config).
        $validated = $request->validate(['reason' => 'required|string|min:1|max:500']);

        try {
            $this->editor->remove($found, $foundItem, (string) $validated['reason'], $user);
        } catch (SaleItemEditNotAllowed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->broadcastSaleUpdate($found);

        return $this->saleResponse($found);
    }

    /** Historial de cambios de items de la venta (modal "Historial de cambios"). */
    public function history(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdmin($user);
        $found = $this->findSale($request, $sale);

        $changes = SaleItemChange::where('sale_id', $found->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'sale_id', 'sale_item_id', 'event', 'before', 'after', 'diff', 'reason', 'user_id', 'created_at']);

        return response()->json(['changes' => $changes]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Regla del motivo en add/update según la config de la sucursal
     * (paridad con withValidator de los FormRequests web).
     *
     * @return array<string, string>
     */
    private function reasonRules(User $user): array
    {
        $mode = Branch::withoutGlobalScopes()
            ->where('id', $user->branch_id)
            ->value('sale_item_edit_reason_mode') ?? 'optional';

        return [
            'reason' => $mode === 'required' ? 'required|string|min:1|max:500' : 'nullable|string|max:500',
        ];
    }

    private function ensureAdmin(User $user): void
    {
        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'No tienes permiso para editar items de venta.'
        );
    }

    /** Venta de la sucursal del token; cross-branch → 404. */
    private function findSale(Request $request, int $sale): Sale
    {
        return Sale::withoutGlobalScopes()
            ->where('branch_id', $request->user()->branch_id)
            ->findOrFail($sale);
    }

    private function saleResponse(Sale $sale, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => HubSaleResource::make(
                $sale->refresh()->load(['items', 'payments.user:id,name', 'payments.updatedByUser:id,name', 'customer'])
            )->resolve(request()),
        ], $status);
    }

    private function normalizeReason(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function broadcastSaleUpdate(Sale $sale): void
    {
        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('Hub SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }
    }
}
