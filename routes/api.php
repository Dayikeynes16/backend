<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CategoryController;
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
