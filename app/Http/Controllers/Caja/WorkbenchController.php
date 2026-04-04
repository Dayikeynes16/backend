<?php

namespace App\Http\Controllers\Caja;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Sale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Enum;
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
            ->whereIn('status', [SaleStatus::Active, SaleStatus::Pending])
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

    public function updateStatus(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['required', new Enum(SaleStatus::class)],
        ]);

        $targetStatus = SaleStatus::from($validated['status']);

        // Cajero solo puede: Active <-> Pending
        if (! in_array($targetStatus, [SaleStatus::Active, SaleStatus::Pending])) {
            return back()->with('error', 'No tienes permiso para esta transicion.');
        }

        if (! $sale->status->canTransitionTo($targetStatus)) {
            return back()->with('error', "No se puede cambiar de {$sale->status->label()} a {$targetStatus->label()}.");
        }

        // Lock check
        if ($sale->locked_by && $sale->locked_by !== $user->id && $sale->locked_at > now()->subMinutes(5)) {
            return back()->with('error', 'Esta venta esta siendo operada por otro usuario.');
        }

        $sale->update(['status' => $targetStatus]);
        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }

        $msg = $targetStatus === SaleStatus::Pending
            ? "Venta {$sale->folio} marcada como pendiente."
            : "Venta {$sale->folio} reactivada.";

        return back()->with('success', $msg);
    }

    public function requestCancel(Request $request, Sale $sale): RedirectResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        if ($sale->status === SaleStatus::Cancelled) {
            return back()->with('error', 'Esta venta ya esta cancelada.');
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
