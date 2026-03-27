<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Tenant-scoped routes
Route::prefix('{tenant}')
    ->middleware(['web', 'resolve.tenant'])
    ->group(function () {

        Route::middleware('auth')->group(function () {

            // Admin empresa routes
            Route::middleware('role:admin-empresa')
                ->prefix('empresa')
                ->name('empresa.')
                ->group(function () {
                    Route::get('/', function () {
                        return Inertia::render('Empresa/Dashboard');
                    })->name('dashboard');
                });

            // Admin sucursal routes
            Route::middleware('role:admin-sucursal')
                ->prefix('sucursal')
                ->name('sucursal.')
                ->group(function () {
                    Route::get('/', function () {
                        return Inertia::render('Sucursal/Dashboard');
                    })->name('dashboard');
                });

            // Cajero routes
            Route::middleware('role:cajero')
                ->prefix('caja')
                ->name('caja.')
                ->group(function () {
                    Route::get('/', function () {
                        return Inertia::render('Caja/Queue');
                    })->name('queue');
                });
        });
    });

require __DIR__.'/auth.php';
