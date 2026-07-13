<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\Hub\CancelRequestController as HubCancelRequestController;
use App\Http\Controllers\Api\Hub\ConfigController as HubConfigController;
use App\Http\Controllers\Api\Hub\CustomerController as HubCustomerController;
use App\Http\Controllers\Api\Hub\CustomerPaymentController as HubCustomerPaymentController;
use App\Http\Controllers\Api\Hub\CustomerPriceController as HubCustomerPriceController;
use App\Http\Controllers\Api\Hub\DashboardController as HubDashboardController;
use App\Http\Controllers\Api\Hub\ExpenseController as HubExpenseController;
use App\Http\Controllers\Api\Hub\HistoryController as HubHistoryController;
use App\Http\Controllers\Api\Hub\PaymentController as HubPaymentController;
use App\Http\Controllers\Api\Hub\ProductController as HubProductController;
use App\Http\Controllers\Api\Hub\ProviderController as HubProviderController;
use App\Http\Controllers\Api\Hub\PurchaseController as HubPurchaseController;
use App\Http\Controllers\Api\Hub\PurchaseProductController as HubPurchaseProductController;
use App\Http\Controllers\Api\Hub\RealtimeController as HubRealtimeController;
use App\Http\Controllers\Api\Hub\SaleController as HubSaleController;
use App\Http\Controllers\Api\Hub\ShiftController as HubShiftController;
use App\Http\Controllers\Api\Hub\WithdrawalController as HubWithdrawalController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Public\DeliveryController as PublicDeliveryController;
use App\Http\Controllers\Public\MenuController as PublicMenuController;
use App\Http\Controllers\Public\OrderController as PublicOrderController;
use App\Http\Controllers\Public\TenantController as PublicTenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('auth.apikey')
    ->group(function () {
        Route::get('branches/me', [BranchController::class, 'me'])->name('api.branches.me');
        Route::get('categories', [CategoryController::class, 'index'])->name('api.categories.index');
        Route::get('products', [ProductController::class, 'index'])->name('api.products.index');
        Route::post('sales', [SaleController::class, 'store'])->name('api.sales.store');
        Route::get('sales', [SaleController::class, 'index'])->name('api.sales.index');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->name('api.sales.show');
    });

// Autenticación de usuario para el hub de escritorio (Sanctum token).
// Fuera del grupo auth.apikey: NO afecta a las básculas ni a la sesión Inertia.
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    });
});

// Hub de escritorio: operaciones de caja autenticadas por usuario (Sanctum).
// Separado del grupo auth.apikey (básculas) y de Inertia.
Route::prefix('v1/hub')
    ->middleware(['auth:sanctum', 'hub.role'])
    ->group(function () {
        Route::get('dashboard', [HubDashboardController::class, 'index'])->name('api.hub.dashboard.index');

        // Tiempo real (Reverb/Echo): parámetros de conexión + auth de canal privado vía Sanctum.
        Route::get('realtime/config', [HubRealtimeController::class, 'config'])->name('api.hub.realtime.config');
        Route::post('realtime/auth', [HubRealtimeController::class, 'authenticate'])->name('api.hub.realtime.auth');

        // Configuración de negocio de la sucursal (admin-sucursal): métodos de pago + API keys.
        Route::get('config', [HubConfigController::class, 'index'])->name('api.hub.config.index');
        Route::put('config/payment-methods', [HubConfigController::class, 'updatePaymentMethods'])->name('api.hub.config.payment-methods');
        Route::post('config/api-keys', [HubConfigController::class, 'storeApiKey'])->name('api.hub.config.api-keys.store');
        Route::delete('config/api-keys/{apiKey}', [HubConfigController::class, 'revokeApiKey'])->whereNumber('apiKey')->name('api.hub.config.api-keys.revoke');
        Route::delete('config/api-keys/{apiKey}/force', [HubConfigController::class, 'deleteApiKey'])->whereNumber('apiKey')->name('api.hub.config.api-keys.delete');

        Route::get('shift/current', [HubShiftController::class, 'current'])->name('api.hub.shift.current');
        Route::post('shift/open', [HubShiftController::class, 'open'])->name('api.hub.shift.open');
        Route::post('shift/close', [HubShiftController::class, 'close'])->name('api.hub.shift.close');
        // Retiros de efectivo del turno abierto (cajero dueño; admin-sucursal
        // puede eliminar incluso con turno cerrado — mismas reglas que la web).
        Route::post('shift/withdrawals', [HubWithdrawalController::class, 'store'])->name('api.hub.shift.withdrawals.store');
        Route::delete('shift/withdrawals/{withdrawal}', [HubWithdrawalController::class, 'destroy'])->whereNumber('withdrawal')->name('api.hub.shift.withdrawals.destroy');
        // Cortes históricos: lista (admin toda la sucursal, cajero los suyos),
        // detalle persistente y, solo admin, recalcular/reabrir (paridad
        // Sucursal\CashShiftController).
        Route::get('shifts', [HubShiftController::class, 'index'])->name('api.hub.shifts.index');
        Route::get('shifts/{shift}', [HubShiftController::class, 'show'])->whereNumber('shift')->name('api.hub.shifts.show');
        Route::post('shifts/{shift}/recalculate', [HubShiftController::class, 'recalculate'])->whereNumber('shift')->name('api.hub.shifts.recalculate');
        Route::post('shifts/{shift}/reopen', [HubShiftController::class, 'reopen'])->whereNumber('shift')->name('api.hub.shifts.reopen');

        Route::get('history', [HubHistoryController::class, 'index'])->name('api.hub.history.index');
        Route::get('payments', [HubPaymentController::class, 'index'])->name('api.hub.payments.index');

        Route::get('customers', [HubCustomerController::class, 'index'])->name('api.hub.customers.index');
        Route::post('customers', [HubCustomerController::class, 'store'])->name('api.hub.customers.store');
        Route::get('customers/{customer}', [HubCustomerController::class, 'show'])->whereNumber('customer')->name('api.hub.customers.show');
        Route::patch('customers/{customer}', [HubCustomerController::class, 'update'])->whereNumber('customer')->name('api.hub.customers.update');
        Route::delete('customers/{customer}', [HubCustomerController::class, 'destroy'])->whereNumber('customer')->name('api.hub.customers.destroy');
        Route::get('customers/{customer}/history', [HubCustomerController::class, 'history'])->whereNumber('customer')->name('api.hub.customers.history');
        // Fiado: ledger + cobro global FIFO + cancelar.
        Route::get('customers/{customer}/payments', [HubCustomerPaymentController::class, 'index'])->whereNumber('customer')->name('api.hub.customers.payments.index');
        Route::post('customers/{customer}/payments', [HubCustomerPaymentController::class, 'store'])->whereNumber('customer')->name('api.hub.customers.payments.store');
        Route::delete('customers/{customer}/payments/{payment}', [HubCustomerPaymentController::class, 'destroy'])->whereNumber('customer')->whereNumber('payment')->name('api.hub.customers.payments.destroy');
        // Precios preferenciales por producto.
        Route::post('customers/{customer}/prices', [HubCustomerPriceController::class, 'store'])->whereNumber('customer')->name('api.hub.customers.prices.store');
        Route::patch('customers/{customer}/prices/{price}', [HubCustomerPriceController::class, 'update'])->whereNumber('customer')->whereNumber('price')->name('api.hub.customers.prices.update');
        Route::delete('customers/{customer}/prices/{price}', [HubCustomerPriceController::class, 'destroy'])->whereNumber('customer')->whereNumber('price')->name('api.hub.customers.prices.destroy');
        Route::get('products', [HubProductController::class, 'index'])->name('api.hub.products.index');

        Route::get('expenses', [HubExpenseController::class, 'index'])->name('api.hub.expenses.index');
        Route::post('expenses', [HubExpenseController::class, 'store'])->name('api.hub.expenses.store');
        Route::post('expenses/ai-draft', [HubExpenseController::class, 'aiDraft'])->name('api.hub.expenses.ai-draft');
        Route::get('expenses/{expense}', [HubExpenseController::class, 'show'])->whereNumber('expense')->name('api.hub.expenses.show');
        Route::patch('expenses/{expense}', [HubExpenseController::class, 'update'])->whereNumber('expense')->name('api.hub.expenses.update');
        Route::delete('expenses/{expense}', [HubExpenseController::class, 'destroy'])->whereNumber('expense')->name('api.hub.expenses.destroy');
        Route::post('expenses/{expense}/attachments', [HubExpenseController::class, 'storeAttachment'])->whereNumber('expense')->name('api.hub.expenses.attachments.store');
        Route::get('expenses/{expense}/attachments/{attachment}', [HubExpenseController::class, 'downloadAttachment'])->whereNumber('expense')->whereNumber('attachment')->name('api.hub.expenses.attachments.download');
        Route::delete('expenses/{expense}/attachments/{attachment}', [HubExpenseController::class, 'destroyAttachment'])->whereNumber('expense')->whereNumber('attachment')->name('api.hub.expenses.attachments.destroy');

        Route::get('purchases', [HubPurchaseController::class, 'index'])->name('api.hub.purchases.index');
        Route::post('purchases', [HubPurchaseController::class, 'store'])->name('api.hub.purchases.store');
        Route::post('purchases/ai-draft', [HubPurchaseController::class, 'aiDraft'])->name('api.hub.purchases.ai-draft');
        Route::get('purchases/{purchase}', [HubPurchaseController::class, 'show'])->whereNumber('purchase')->name('api.hub.purchases.show');
        Route::patch('purchases/{purchase}', [HubPurchaseController::class, 'update'])->whereNumber('purchase')->name('api.hub.purchases.update');
        Route::post('purchases/{purchase}/cancel', [HubPurchaseController::class, 'cancel'])->whereNumber('purchase')->name('api.hub.purchases.cancel');
        Route::post('purchases/{purchase}/payments', [HubPurchaseController::class, 'addPayment'])->whereNumber('purchase')->name('api.hub.purchases.payments.store');
        Route::delete('purchases/{purchase}/payments/{payment}', [HubPurchaseController::class, 'cancelPayment'])->whereNumber('purchase')->whereNumber('payment')->name('api.hub.purchases.payments.cancel');
        Route::post('purchases/{purchase}/attachments', [HubPurchaseController::class, 'storeAttachment'])->whereNumber('purchase')->name('api.hub.purchases.attachments.store');
        Route::get('purchases/{purchase}/attachments/{attachment}', [HubPurchaseController::class, 'downloadAttachment'])->whereNumber('purchase')->whereNumber('attachment')->name('api.hub.purchases.attachments.download');
        Route::delete('purchases/{purchase}/attachments/{attachment}', [HubPurchaseController::class, 'destroyAttachment'])->whereNumber('purchase')->whereNumber('attachment')->name('api.hub.purchases.attachments.destroy');
        Route::get('purchase-products', [HubPurchaseProductController::class, 'index'])->name('api.hub.purchase-products.index');

        // Proveedores (admin-sucursal): lectura siempre; crear/editar gateado por
        // el toggle branch_admin_providers_enabled. Sin borrar.
        Route::get('providers', [HubProviderController::class, 'index'])->name('api.hub.providers.index');
        Route::post('providers', [HubProviderController::class, 'store'])->name('api.hub.providers.store');
        Route::put('providers/{provider}', [HubProviderController::class, 'update'])->whereNumber('provider')->name('api.hub.providers.update');
        // Detalle de proveedor (admin-sucursal): resumen/compras/pagos/productos + pago a cuenta.
        Route::get('providers/{provider}', [HubProviderController::class, 'show'])->whereNumber('provider')->name('api.hub.providers.show');
        Route::get('providers/{provider}/compras', [HubProviderController::class, 'compras'])->whereNumber('provider')->name('api.hub.providers.compras');
        Route::get('providers/{provider}/pagos', [HubProviderController::class, 'pagos'])->whereNumber('provider')->name('api.hub.providers.pagos');
        Route::get('providers/{provider}/productos', [HubProviderController::class, 'productos'])->whereNumber('provider')->name('api.hub.providers.productos');
        Route::post('providers/{provider}/pagos', [HubProviderController::class, 'accountPayment'])->whereNumber('provider')->name('api.hub.providers.account-payment');

        Route::get('sales', [HubSaleController::class, 'index'])->name('api.hub.sales.index');
        Route::get('sales/{sale}', [HubSaleController::class, 'show'])->whereNumber('sale')->name('api.hub.sales.show');
        Route::post('sales/{sale}/payments', [HubPaymentController::class, 'store'])->whereNumber('sale')->name('api.hub.sales.payments.store');
        // Corrección de pagos (solo admin-sucursal, paridad con la web).
        Route::put('sales/{sale}/payments/{payment}', [HubPaymentController::class, 'update'])->whereNumber('sale')->whereNumber('payment')->name('api.hub.sales.payments.update');
        Route::delete('sales/{sale}/payments/{payment}', [HubPaymentController::class, 'destroy'])->whereNumber('sale')->whereNumber('payment')->name('api.hub.sales.payments.destroy');
        // Acciones de mesa de trabajo (cajero): estado, cancelación, cliente, WhatsApp.
        Route::patch('sales/{sale}/status', [HubSaleController::class, 'updateStatus'])->whereNumber('sale')->name('api.hub.sales.update-status');
        Route::post('sales/{sale}/request-cancel', [HubSaleController::class, 'requestCancel'])->whereNumber('sale')->name('api.hub.sales.request-cancel');
        // Potestades de admin-sucursal sobre la venta (paridad con Sucursal\Workbench).
        Route::post('sales/{sale}/cancel', [HubSaleController::class, 'cancel'])->whereNumber('sale')->name('api.hub.sales.cancel');
        Route::post('sales/{sale}/reopen', [HubSaleController::class, 'reopen'])->whereNumber('sale')->name('api.hub.sales.reopen');
        // Solicitudes de cancelación (aprobar/rechazar, solo admin-sucursal).
        Route::get('cancel-requests', [HubCancelRequestController::class, 'index'])->name('api.hub.cancel-requests.index');
        Route::post('cancel-requests/{sale}/approve', [HubCancelRequestController::class, 'approve'])->whereNumber('sale')->name('api.hub.cancel-requests.approve');
        Route::post('cancel-requests/{sale}/reject', [HubCancelRequestController::class, 'reject'])->whereNumber('sale')->name('api.hub.cancel-requests.reject');
        Route::patch('sales/{sale}/customer', [HubSaleController::class, 'assignCustomer'])->whereNumber('sale')->name('api.hub.sales.assign-customer');
        Route::get('sales/{sale}/whatsapp', [HubSaleController::class, 'whatsappLink'])->whereNumber('sale')->name('api.hub.sales.whatsapp');
        // Lock de concurrencia (5 min con heartbeat).
        Route::post('sales/{sale}/lock', [HubSaleController::class, 'lock'])->whereNumber('sale')->name('api.hub.sales.lock');
        Route::post('sales/{sale}/unlock', [HubSaleController::class, 'unlock'])->whereNumber('sale')->name('api.hub.sales.unlock');
        Route::post('sales/{sale}/heartbeat', [HubSaleController::class, 'heartbeat'])->whereNumber('sale')->name('api.hub.sales.heartbeat');
        Route::post('sales/{sale}/whatsapp-phone', [HubSaleController::class, 'storeWhatsappPhone'])->whereNumber('sale')->name('api.hub.sales.whatsapp-phone');
    });

// Public endpoints for online ordering SPA. No auth. Rate-limited.
if (config('features.web_orders')) {
    Route::prefix('public/{tenantSlug}')
        ->where(['tenantSlug' => '[a-z0-9-]+'])
        ->middleware(['resolve.public.tenant', 'throttle:60,1'])
        ->group(function () {
            Route::get('/', [PublicTenantController::class, 'show'])->name('api.public.tenant.show');
            Route::get('branches/{branch}/menu', [PublicMenuController::class, 'show'])->name('api.public.menu');
            Route::post('branches/{branch}/delivery/quote', [PublicDeliveryController::class, 'quote'])
                ->middleware('throttle:20,1')
                ->name('api.public.delivery.quote');
            Route::post('branches/{branch}/orders', [PublicOrderController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('api.public.orders.store');
        });
}
