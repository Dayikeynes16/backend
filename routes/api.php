<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\Hub\HistoryController as HubHistoryController;
use App\Http\Controllers\Api\Hub\PaymentController as HubPaymentController;
use App\Http\Controllers\Api\Hub\SaleController as HubSaleController;
use App\Http\Controllers\Api\Hub\ShiftController as HubShiftController;
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
        Route::get('shift/current', [HubShiftController::class, 'current'])->name('api.hub.shift.current');
        Route::post('shift/open', [HubShiftController::class, 'open'])->name('api.hub.shift.open');
        Route::post('shift/close', [HubShiftController::class, 'close'])->name('api.hub.shift.close');

        Route::get('history', [HubHistoryController::class, 'index'])->name('api.hub.history.index');

        Route::get('sales', [HubSaleController::class, 'index'])->name('api.hub.sales.index');
        Route::get('sales/{sale}', [HubSaleController::class, 'show'])->name('api.hub.sales.show');
        Route::post('sales/{sale}/payments', [HubPaymentController::class, 'store'])->name('api.hub.sales.payments.store');
    });

// Public endpoints for online ordering SPA. No auth. Rate-limited.
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
