<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Concerns\HandlesProviderPayments;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ProviderPaymentController extends Controller
{
    use HandlesProviderPayments;

    protected function assertCanPayPurchase(Purchase $purchase): void
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if ($purchase->tenant_id !== $tenant->id) {
            abort(404);
        }
        if ($purchase->branch_id !== $user->branch_id) {
            abort(403, 'Esta compra pertenece a otra sucursal.');
        }
    }

    protected function assertCanPayProvider(Provider $provider): void
    {
        // El proveedor es tenant-wide. admin-sucursal puede pagar a cualquiera,
        // pero el FIFO se restringe a las compras de SU sucursal vía
        // branchIdForAccountPayment().
        if ($provider->tenant_id !== app('tenant')->id) {
            abort(404);
        }
    }

    protected function assertCanCancelPayment(ProviderPayment $payment): void
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if ($payment->tenant_id !== $tenant->id) {
            abort(404);
        }
        if ($payment->branch_id !== $user->branch_id) {
            abort(403, 'Este pago pertenece a otra sucursal.');
        }
    }

    protected function branchIdForAccountPayment(): ?int
    {
        return (int) Auth::user()->branch_id;
    }

    protected function redirectAfterPayment(string $message): RedirectResponse
    {
        return back()->with('success', $message);
    }
}
