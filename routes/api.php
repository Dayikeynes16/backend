<?php

use Illuminate\Support\Facades\Route;

// Public API — authenticated by API Key (middleware added in Phase 4)
Route::prefix('v1')->group(function () {
    Route::get('branches/me', function () {
        return response()->json(['message' => 'Not implemented'], 501);
    })->name('api.branches.me');

    Route::get('products', function () {
        return response()->json(['message' => 'Not implemented'], 501);
    })->name('api.products.index');

    Route::post('sales', function () {
        return response()->json(['message' => 'Not implemented'], 501);
    })->name('api.sales.store');

    Route::get('sales', function () {
        return response()->json(['message' => 'Not implemented'], 501);
    })->name('api.sales.index');

    Route::get('sales/{sale}', function () {
        return response()->json(['message' => 'Not implemented'], 501);
    })->name('api.sales.show');
});
