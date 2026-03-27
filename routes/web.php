<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmpresaController;
use App\Http\Controllers\Admin\PasswordResetController as AdminPasswordResetController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Caja\DashboardController as CajaDashboardController;
use App\Http\Controllers\Caja\SaleController as CajaSaleController;
use App\Http\Controllers\Caja\ShiftController as CajaShiftController;
use App\Http\Controllers\Empresa\ConfiguracionController;
use App\Http\Controllers\Empresa\DashboardController as EmpresaDashboardController;
use App\Http\Controllers\Empresa\PasswordResetController as EmpresaPasswordResetController;
use App\Http\Controllers\Empresa\SucursalController;
use App\Http\Controllers\Empresa\UsuarioController as EmpresaUsuarioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Sucursal\ApiKeyController;
use App\Http\Controllers\Sucursal\CategoryController;
use App\Http\Controllers\Sucursal\ConfiguracionController as SucursalConfiguracionController;
use App\Http\Controllers\Sucursal\DashboardController as SucursalDashboardController;
use App\Http\Controllers\Sucursal\PaymentController;
use App\Http\Controllers\Sucursal\ProductoController;
use App\Http\Controllers\Sucursal\ShiftController as SucursalShiftController;
use App\Http\Controllers\Sucursal\UsuarioController as SucursalUsuarioController;
use App\Http\Controllers\Sucursal\WorkbenchController;
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

    Route::get('/change-password', [ForcePasswordChangeController::class, 'show'])->name('password.force-change');
    Route::put('/change-password', [ForcePasswordChangeController::class, 'update'])->name('password.force-change.update');
});

// Superadmin routes
Route::prefix('admin')
    ->middleware(['auth', 'role:superadmin'])
    ->name('admin.')
    ->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        Route::resource('empresas', EmpresaController::class)
            ->except('show');

        Route::post('usuarios/{usuario}/send-reset', [AdminPasswordResetController::class, 'sendResetLink'])->name('usuarios.send-reset');
        Route::post('usuarios/{usuario}/force-reset', [AdminPasswordResetController::class, 'forceReset'])->name('usuarios.force-reset');
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
                Route::get('/', [EmpresaDashboardController::class, 'index'])->name('dashboard');

                Route::resource('sucursales', SucursalController::class)
                    ->except('show')
                    ->parameters(['sucursales' => 'sucursal']);

                Route::resource('usuarios', EmpresaUsuarioController::class)
                    ->except('show');

                Route::post('usuarios/{usuario}/send-reset', [EmpresaPasswordResetController::class, 'sendResetLink'])->name('usuarios.send-reset');
                Route::post('usuarios/{usuario}/force-reset', [EmpresaPasswordResetController::class, 'forceReset'])->name('usuarios.force-reset');

                Route::get('configuracion', [ConfiguracionController::class, 'edit'])->name('configuracion');
                Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
            });

        // Admin sucursal routes
        Route::middleware('role:admin-sucursal|superadmin')
            ->prefix('sucursal')
            ->name('sucursal.')
            ->group(function () {
                Route::get('/', [SucursalDashboardController::class, 'index'])->name('dashboard');

                Route::resource('productos', ProductoController::class)
                    ->except('show');

                Route::resource('usuarios', SucursalUsuarioController::class)
                    ->except('show');

                Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
                Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
                Route::delete('api-keys/{api_key}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

                Route::get('cortes', [SucursalShiftController::class, 'index'])->name('cortes.index');

                // Categories
                Route::get('categorias', [CategoryController::class, 'index'])->name('categorias.index');
                Route::post('categorias', [CategoryController::class, 'store'])->name('categorias.store');
                Route::put('categorias/{category}', [CategoryController::class, 'update'])->name('categorias.update');
                Route::delete('categorias/{category}', [CategoryController::class, 'destroy'])->name('categorias.destroy');

                // Workbench
                Route::get('mesa-de-trabajo', [WorkbenchController::class, 'index'])->name('workbench');
                Route::post('mesa-de-trabajo/ventas', [WorkbenchController::class, 'store'])->name('workbench.store');
                Route::patch('mesa-de-trabajo/ventas/{sale}/cancelar', [WorkbenchController::class, 'cancel'])->name('workbench.cancel');

                // Payments
                Route::post('mesa-de-trabajo/ventas/{sale}/pagos', [PaymentController::class, 'store'])->name('workbench.payment');

                // Config
                Route::get('configuracion', [SucursalConfiguracionController::class, 'edit'])->name('configuracion');
                Route::put('configuracion', [SucursalConfiguracionController::class, 'update'])->name('configuracion.update');
            });

        // Cajero routes
        Route::middleware('role:cajero|superadmin')
            ->prefix('caja')
            ->name('caja.')
            ->group(function () {
                Route::get('/', [CajaSaleController::class, 'index'])->name('queue');
                Route::patch('sales/{sale}/complete', [CajaSaleController::class, 'complete'])->name('sales.complete');

                Route::get('dashboard', [CajaDashboardController::class, 'index'])->name('dashboard');

                Route::get('turno/abrir', [CajaShiftController::class, 'create'])->name('shift.create');
                Route::post('turno', [CajaShiftController::class, 'store'])->name('shift.store');
                Route::get('turno', [CajaShiftController::class, 'show'])->name('shift.show');
                Route::patch('turno/cerrar', [CajaShiftController::class, 'close'])->name('shift.close');
            });
    });

require __DIR__.'/auth.php';
