<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmpresaController;
use App\Http\Controllers\Admin\PasswordResetController as AdminPasswordResetController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Caja\HistorialController as CajaHistorialController;
use App\Http\Controllers\Caja\TurnoController as CajaTurnoController;
use App\Http\Controllers\Caja\WorkbenchController as CajaWorkbenchController;
use App\Http\Controllers\Empresa\ConfiguracionController;
use App\Http\Controllers\Empresa\DashboardController as EmpresaDashboardController;
use App\Http\Controllers\Empresa\PasswordResetController as EmpresaPasswordResetController;
use App\Http\Controllers\Empresa\SucursalController;
use App\Http\Controllers\Empresa\UsuarioController as EmpresaUsuarioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Sucursal\ApiKeyController;
use App\Http\Controllers\Sucursal\CancelRequestController;
use App\Http\Controllers\Sucursal\CashShiftController;
use App\Http\Controllers\Sucursal\SaleLockController;
use App\Http\Controllers\Sucursal\CategoryController;
use App\Http\Controllers\Sucursal\ConfiguracionController as SucursalConfiguracionController;
use App\Http\Controllers\Sucursal\DashboardController as SucursalDashboardController;
use App\Http\Controllers\Sucursal\PaymentController;
use App\Http\Controllers\Sucursal\ProductoController;
use App\Http\Controllers\Sucursal\SaleHistoryController;
use App\Http\Controllers\Sucursal\ShiftController as SucursalShiftController;
use App\Http\Controllers\Sucursal\WithdrawalController;
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

                // Turno (shift)
                Route::get('turno', [CashShiftController::class, 'active'])->name('turno.active');
                Route::post('turno/abrir', [CashShiftController::class, 'open'])->name('turno.open');
                Route::post('turno/cerrar', [CashShiftController::class, 'close'])->name('turno.close');

                // Withdrawals
                Route::post('turno/retiros', [WithdrawalController::class, 'store'])->name('turno.withdrawal.store');
                Route::delete('turno/retiros/{withdrawal}', [WithdrawalController::class, 'destroy'])->name('turno.withdrawal.destroy');

                // Sales history
                Route::get('historial', [SaleHistoryController::class, 'index'])->name('historial.index');

                // Cortes (history)
                Route::get('cortes', [CashShiftController::class, 'history'])->name('cortes.index');
                Route::get('cortes/{shift}', [CashShiftController::class, 'show'])->name('cortes.show');

                // Categories
                Route::get('categorias', [CategoryController::class, 'index'])->name('categorias.index');
                Route::post('categorias', [CategoryController::class, 'store'])->name('categorias.store');
                Route::put('categorias/{category}', [CategoryController::class, 'update'])->name('categorias.update');
                Route::delete('categorias/{category}', [CategoryController::class, 'destroy'])->name('categorias.destroy');

                // Workbench
                Route::get('mesa-de-trabajo', [WorkbenchController::class, 'index'])->name('workbench');
                Route::post('mesa-de-trabajo/ventas', [WorkbenchController::class, 'store'])->name('workbench.store');
                Route::patch('mesa-de-trabajo/ventas/{sale}/cancelar', [WorkbenchController::class, 'cancel'])->name('workbench.cancel');
                Route::post('mesa-de-trabajo/ventas/{sale}/solicitar-cancelacion', [WorkbenchController::class, 'requestCancel'])->name('workbench.request-cancel');

                // Sale locking
                Route::post('ventas/{sale}/lock', [SaleLockController::class, 'lock'])->name('sale.lock');
                Route::post('ventas/{sale}/unlock', [SaleLockController::class, 'unlock'])->name('sale.unlock');
                Route::post('ventas/{sale}/heartbeat', [SaleLockController::class, 'heartbeat'])->name('sale.heartbeat');

                // Payments
                Route::post('mesa-de-trabajo/ventas/{sale}/pagos', [PaymentController::class, 'store'])->name('workbench.payment');
                Route::put('mesa-de-trabajo/ventas/{sale}/pagos/{payment}', [PaymentController::class, 'update'])->name('workbench.payment.update');
                Route::delete('mesa-de-trabajo/ventas/{sale}/pagos/{payment}', [PaymentController::class, 'destroy'])->name('workbench.payment.destroy');

                // Cancelation requests
                Route::get('cancelaciones', [CancelRequestController::class, 'index'])->name('cancelaciones.index');
                Route::patch('cancelaciones/{sale}/aprobar', [CancelRequestController::class, 'approve'])->name('cancelaciones.approve');
                Route::patch('cancelaciones/{sale}/rechazar', [CancelRequestController::class, 'reject'])->name('cancelaciones.reject');

                // Config
                Route::get('configuracion', [SucursalConfiguracionController::class, 'edit'])->name('configuracion');
                Route::put('configuracion', [SucursalConfiguracionController::class, 'update'])->name('configuracion.update');
            });

        // Cajero routes
        Route::middleware('role:cajero|superadmin')
            ->prefix('caja')
            ->name('caja.')
            ->group(function () {
                Route::get('/', [CajaWorkbenchController::class, 'index'])->name('workbench');
                Route::post('ventas/{sale}/pagos', [PaymentController::class, 'store'])->name('payment.store');
                Route::post('ventas/{sale}/lock', [SaleLockController::class, 'lock'])->name('sale.lock');
                Route::post('ventas/{sale}/unlock', [SaleLockController::class, 'unlock'])->name('sale.unlock');
                Route::post('ventas/{sale}/heartbeat', [SaleLockController::class, 'heartbeat'])->name('sale.heartbeat');
                Route::post('ventas/{sale}/solicitar-cancelacion', [CajaWorkbenchController::class, 'requestCancel'])->name('request-cancel');
                Route::get('turno', [CajaTurnoController::class, 'index'])->name('turno');
                Route::post('turno/abrir', [CajaTurnoController::class, 'open'])->name('turno.open');
                Route::post('turno/cerrar', [CajaTurnoController::class, 'close'])->name('turno.close');
                Route::get('historial', [CajaHistorialController::class, 'index'])->name('historial');
            });
    });

require __DIR__.'/auth.php';
