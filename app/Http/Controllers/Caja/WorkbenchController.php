<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class WorkbenchController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();

        $hasShift = CashRegisterShift::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if (! $hasShift) {
            return redirect()->route('caja.turno', app('tenant')->slug);
        }

        $sales = Sale::where('branch_id', $user->branch_id)
            ->where('status', 'active')
            ->with(['items', 'payments', 'lockedByUser:id,name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $paymentMethods = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        return Inertia::render('Caja/Workbench', [
            'sales' => $sales,
            'tenant' => app('tenant'),
            'branchId' => $user->branch_id,
            'branchInfo' => [
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'ticket_config' => $branch->ticket_config,
            ],
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function requestCancel(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === 'completed' || $sale->status === 'cancelled') {
            return back()->with('error', 'Esta venta no se puede cancelar.');
        }

        if ($sale->cancel_requested_at) {
            return back()->with('error', 'Ya existe una solicitud de cancelacion.');
        }

        $validated = $request->validate([
            'cancel_request_reason' => 'required|string|max:500',
        ]);

        $sale->update([
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $user->id,
            'cancel_request_reason' => $validated['cancel_request_reason'],
        ]);

        return back()->with('success', "Solicitud de cancelacion enviada para {$sale->folio}.");
    }
}
