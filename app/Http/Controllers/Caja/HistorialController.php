<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class HistorialController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $product = trim((string) $request->input('product', ''));
        $search = trim((string) $request->input('search', ''));
        $minTotal = $request->input('min_total');
        $maxTotal = $request->input('max_total');

        // Sales where this cajero registered at least one payment
        $saleIds = Payment::where('user_id', $user->id)
            ->distinct()
            ->pluck('sale_id');

        $sales = Sale::whereIn('id', $saleIds)
            ->with([
                'items',
                'payments.receipts:id,payment_id,customer_payment_id,original_name,mime_type,size_bytes',
                'customer:id,name,phone',
            ])
            // Buscar por folio ignora la fecha (paridad Sucursal\SaleHistoryController):
            // un cobro de hoy sobre una venta de anteayer —el caso típico del fiado—
            // aterrizaría en un historial vacío si el día siguiera filtrando.
            ->when(
                $search !== '',
                fn ($q) => $q->where('folio', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
                fn ($q) => $q->when(
                    $request->date,
                    fn ($inner, $d) => $inner->whereDate('created_at', $d),
                    fn ($inner) => $inner->whereDate('created_at', today())
                )
            )
            ->when($product !== '', fn ($q) => $q->whereHas(
                'items',
                fn ($iq) => $iq->where('product_name', 'ilike', '%'.addcslashes($product, '%_\\').'%')
            ))
            ->when(is_numeric($minTotal), fn ($q) => $q->where('total', '>=', (float) $minTotal))
            ->when(is_numeric($maxTotal), fn ($q) => $q->where('total', '<=', (float) $maxTotal))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(20)
            ->withQueryString();

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);

        return Inertia::render('Caja/Historial', [
            'sales' => $sales,
            'filters' => [
                'date' => $request->input('date'),
                'search' => $search !== '' ? $search : null,
                'product' => $product !== '' ? $product : null,
                'min_total' => is_numeric($minTotal) ? $minTotal : null,
                'max_total' => is_numeric($maxTotal) ? $maxTotal : null,
            ],
            'tenant' => app('tenant'),
            'branchInfo' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'ticket_config' => $branch->ticket_config,
                'payment_receipts_enabled' => (bool) ($branch->payment_receipts_enabled || $branch->payment_receipts_required),
                'payment_receipts_required' => (bool) $branch->payment_receipts_required,
            ],
        ]);
    }
}
