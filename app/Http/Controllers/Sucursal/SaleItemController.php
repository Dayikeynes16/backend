<?php

namespace App\Http\Controllers\Sucursal;

use App\Events\SaleUpdated;
use App\Exceptions\SaleItemEditNoOp;
use App\Exceptions\SaleItemEditNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\DestroySaleItemRequest;
use App\Http\Requests\StoreSaleItemRequest;
use App\Http\Requests\UpdateSaleItemRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemChange;
use App\Services\SaleItemEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Edición de items de una venta desde Mesa de Trabajo.
 * Solo admin-sucursal+ (gated por el grupo de rutas).
 * No se expone en Caja.
 */
class SaleItemController extends Controller
{
    public function __construct(protected SaleItemEditor $editor) {}

    public function store(StoreSaleItemRequest $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        try {
            $this->editor->add(
                $sale,
                [
                    'product_id' => (int) $request->input('product_id'),
                    'presentation_id' => $request->input('presentation_id'),
                    'quantity' => $request->input('quantity'),
                    'unit_price' => $request->input('unit_price'),
                    'notes' => $request->input('notes'),
                ],
                $this->normalizeReason($request->input('reason')),
                $user,
            );
        } catch (SaleItemEditNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->broadcastSaleUpdate($sale);

        return back()->with('success', 'Producto agregado a la venta.');
    }

    public function update(UpdateSaleItemRequest $request, Sale $sale, SaleItem $item): RedirectResponse
    {
        $user = Auth::user();

        try {
            $this->editor->update(
                $sale,
                $item,
                [
                    'quantity' => $request->input('quantity'),
                    'unit_price' => $request->input('unit_price'),
                ],
                $this->normalizeReason($request->input('reason')),
                $user,
            );
        } catch (SaleItemEditNoOp $e) {
            return back()->with('error', $e->getMessage());
        } catch (SaleItemEditNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->broadcastSaleUpdate($sale);

        return back()->with('success', 'Producto actualizado.');
    }

    /**
     * Devuelve el historial de cambios de items para la venta, ordenado
     * cronológicamente desc. Lo consume el modal "Historial de cambios"
     * del Workbench. Solo admin-sucursal+ (gated por el grupo).
     */
    public function history(Sale $sale): JsonResponse
    {
        $user = Auth::user();
        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $changes = SaleItemChange::where('sale_id', $sale->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'sale_id', 'sale_item_id', 'event', 'before', 'after', 'diff', 'reason', 'user_id', 'created_at']);

        return response()->json(['changes' => $changes]);
    }

    public function destroy(DestroySaleItemRequest $request, Sale $sale, SaleItem $item): RedirectResponse
    {
        $user = Auth::user();

        try {
            $this->editor->remove($sale, $item, (string) $request->input('reason'), $user);
        } catch (SaleItemEditNotAllowed $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->broadcastSaleUpdate($sale);

        return back()->with('success', 'Producto eliminado de la venta.');
    }

    private function broadcastSaleUpdate(Sale $sale): void
    {
        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeReason(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
