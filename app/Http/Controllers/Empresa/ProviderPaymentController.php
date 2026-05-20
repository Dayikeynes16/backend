<?php

namespace App\Http\Controllers\Empresa;

use App\Http\Controllers\Concerns\HandlesProviderPayments;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use Illuminate\Http\RedirectResponse;

class ProviderPaymentController extends Controller
{
    use HandlesProviderPayments;

    protected function assertCanPayPurchase(Purchase $purchase): void
    {
        if ($purchase->tenant_id !== app('tenant')->id) {
            abort(404);
        }
    }

    protected function assertCanPayProvider(Provider $provider): void
    {
        if ($provider->tenant_id !== app('tenant')->id) {
            abort(404);
        }
    }

    protected function assertCanCancelPayment(ProviderPayment $payment): void
    {
        if ($payment->tenant_id !== app('tenant')->id) {
            abort(404);
        }
    }

    protected function redirectAfterPayment(string $message): RedirectResponse
    {
        return back()->with('success', $message);
    }
}
