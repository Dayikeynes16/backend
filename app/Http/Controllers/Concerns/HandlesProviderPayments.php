<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\PaymentMethod;
use App\Models\Provider;
use App\Models\ProviderPayment;
use App\Models\Purchase;
use App\Services\PurchasePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Lógica común para registrar y cancelar pagos a proveedores. La única
 * diferencia entre Empresa y Sucursal es:
 *  - Empresa: ve y opera sobre cualquier compra/proveedor del tenant.
 *  - Sucursal: branch_id forzado a $user->branch_id y rechaza compras de otra
 *    sucursal con 403.
 *
 * El controller concreto provee `assertCanPayPurchase()` y `assertCanPayProvider()`.
 */
trait HandlesProviderPayments
{
    abstract protected function assertCanPayPurchase(Purchase $purchase): void;

    abstract protected function assertCanPayProvider(Provider $provider): void;

    abstract protected function assertCanCancelPayment(ProviderPayment $payment): void;

    abstract protected function redirectAfterPayment(string $message): RedirectResponse;

    /**
     * POST /compras/{compra}/pagos — registra un pago a una compra específica.
     */
    public function storeForPurchase(Request $request, Purchase $compra, PurchasePaymentService $service): RedirectResponse
    {
        $this->assertCanPayPurchase($compra);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_at' => 'nullable|date',
            'reference' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:500',
        ]);

        $service->applyPayment($compra, array_merge($validated, [
            'user_id' => Auth::id(),
        ]));

        return $this->redirectAfterPayment('Pago registrado.');
    }

    /**
     * POST /proveedores/{provider}/pagos — pago "a cuenta" FIFO sobre compras
     * pendientes del proveedor.
     */
    public function storeForProvider(Request $request, Provider $provider, PurchasePaymentService $service): RedirectResponse
    {
        $this->assertCanPayProvider($provider);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_at' => 'nullable|date',
            'reference' => 'nullable|string|max:60',
            'notes' => 'nullable|string|max:500',
        ]);

        $service->applyAccountPayment($provider, array_merge($validated, [
            'user_id' => Auth::id(),
            'branch_id' => $this->branchIdForAccountPayment(),
        ]));

        return $this->redirectAfterPayment('Pago a cuenta aplicado.');
    }

    /**
     * DELETE /compras/{compra}/pagos/{pago} — cancela un pago. Body requiere `reason`.
     */
    public function destroyPayment(Request $request, Purchase $compra, ProviderPayment $pago, PurchasePaymentService $service): RedirectResponse
    {
        $this->assertCanCancelPayment($pago);

        if ($pago->purchase_id !== $compra->id) {
            abort(404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $service->cancelPayment($pago, Auth::id(), $validated['reason']);

        return $this->redirectAfterPayment('Pago cancelado.');
    }

    /**
     * Override en Sucursal para devolver $user->branch_id.
     */
    protected function branchIdForAccountPayment(): ?int
    {
        return null;
    }
}
