<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SaleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('auth.apikey')
    ->group(function () {
        Route::get('branches/me', [BranchController::class, 'me'])->name('api.branches.me');
        Route::get('products', [ProductController::class, 'index'])->name('api.products.index');

        // Temporary debug endpoint — remove after fixing the 500
        Route::get('debug/products', function (\Illuminate\Http\Request $request) {
            try {
                $products = \App\Models\Product::withoutGlobalScopes()
                    ->where('branch_id', $request->branch_id)
                    ->where('status', 'active')
                    ->limit(3)
                    ->get(['id', 'name', 'price', 'image_path', 'visibility', 'sale_mode']);

                return response()->json([
                    'ok' => true,
                    'count' => $products->count(),
                    'disk' => config('filesystems.default'),
                    'products' => $products->toArray(),
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ], 500);
            }
        });
        Route::post('sales', [SaleController::class, 'store'])->name('api.sales.store');
        Route::get('sales', [SaleController::class, 'index'])->name('api.sales.index');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->name('api.sales.show');
    });
