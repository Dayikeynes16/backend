<?php

use App\Http\Controllers\Admin\EmpresaController;
use App\Http\Controllers\Caja\SaleController as CajaSaleController;
use App\Http\Controllers\Empresa\SucursalController;
use App\Http\Controllers\Empresa\UsuarioController as EmpresaUsuarioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Sucursal\ApiKeyController;
use App\Http\Controllers\Sucursal\ProductoController;
use App\Http\Controllers\Sucursal\UsuarioController as SucursalUsuarioController;
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

// Superadmin routes
Route::prefix('admin')
    ->middleware(['auth', 'role:superadmin'])
    ->name('admin.')
    ->group(function () {
        Route::get('/', function () {
            return Inertia::render('Admin/Dashboard');
        })->name('dashboard');

        Route::resource('empresas', EmpresaController::class)
            ->except('show');
    });

// Tenant-scoped routes
Route::prefix('{tenant}')
    ->middleware(['web', 'resolve.tenant', 'auth', 'ensure.tenant'])
    ->group(function () {

        // Admin empresa routes
        Route::middleware('role:admin-empresa|superadmin')
            ->prefix('empresa')
            ->name('empresa.')
            ->group(function () {
                Route::get('/', function () {
                    return Inertia::render('Empresa/Dashboard');
                })->name('dashboard');

                Route::resource('sucursales', SucursalController::class)
                    ->except('show')
                    ->parameters(['sucursales' => 'sucursal']);

                Route::resource('usuarios', EmpresaUsuarioController::class)
                    ->except('show');
            });

        // Admin sucursal routes
        Route::middleware('role:admin-sucursal|superadmin')
            ->prefix('sucursal')
            ->name('sucursal.')
            ->group(function () {
                Route::get('/', function () {
                    return Inertia::render('Sucursal/Dashboard');
                })->name('dashboard');

                Route::resource('productos', ProductoController::class)
                    ->except('show');

                Route::resource('usuarios', SucursalUsuarioController::class)
                    ->except('show');

                Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
                Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
                Route::delete('api-keys/{api_key}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
            });

        // Cajero routes
        Route::middleware('role:cajero|superadmin')
            ->prefix('caja')
            ->name('caja.')
            ->group(function () {
                Route::get('/', [CajaSaleController::class, 'index'])->name('queue');
                Route::patch('sales/{sale}/complete', [CajaSaleController::class, 'complete'])->name('sales.complete');
            });
    });

require __DIR__.'/auth.php';
