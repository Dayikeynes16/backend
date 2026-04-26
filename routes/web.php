<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmpresaController;
use App\Http\Controllers\Admin\PasswordResetController as AdminPasswordResetController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Caja\HistorialController as CajaHistorialController;
use App\Http\Controllers\Caja\PagosController as CajaPagosController;
use App\Http\Controllers\Caja\TurnoController as CajaTurnoController;
use App\Http\Controllers\Caja\WorkbenchController as CajaWorkbenchController;
use App\Http\Controllers\Empresa\ConfiguracionController;
use App\Http\Controllers\Empresa\DashboardController as EmpresaDashboardController;
use App\Http\Controllers\Empresa\TicketConfigController;
use App\Http\Controllers\Empresa\PasswordResetController as EmpresaPasswordResetController;
use App\Http\Controllers\Empresa\SucursalController;
use App\Http\Controllers\Empresa\UsuarioController as EmpresaUsuarioController;
use App\Http\Controllers\Empresa\Metrics\MetricsIndexController as EmpresaMetricsIndexController;
use App\Http\Controllers\Empresa\Metrics\SalesMetricsController as EmpresaSalesMetricsController;
use App\Http\Controllers\Empresa\Metrics\MarginMetricsController as EmpresaMarginMetricsController;
use App\Http\Controllers\Empresa\Metrics\ProductMetricsController as EmpresaProductMetricsController;
use App\Http\Controllers\Empresa\Metrics\CustomerMetricsController as EmpresaCustomerMetricsController;
use App\Http\Controllers\Empresa\Metrics\CashierMetricsController as EmpresaCashierMetricsController;
use App\Http\Controllers\Empresa\Metrics\ShiftMetricsController as EmpresaShiftMetricsController;
use App\Http\Controllers\Empresa\Metrics\CollectionMetricsController as EmpresaCollectionMetricsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Sucursal\ApiKeyController;
use App\Http\Controllers\Sucursal\CancelRequestController;
use App\Http\Controllers\Sucursal\CashShiftController;
use App\Http\Controllers\Sucursal\SaleLockController;
use App\Http\Controllers\Sucursal\CategoryController;
use App\Http\Controllers\Sucursal\ConfiguracionController as SucursalConfiguracionController;
use App\Http\Controllers\Sucursal\CustomerController;
use App\Http\Controllers\Sucursal\CustomerPaymentController;
use App\Http\Controllers\Sucursal\CustomerPriceController;
use App\Http\Controllers\Sucursal\CustomerStatsController;
use App\Http\Controllers\Sucursal\DashboardController as SucursalDashboardController;
use App\Http\Controllers\Sucursal\PagosController;
use App\Http\Controllers\Sucursal\PaymentController;
use App\Http\Controllers\Sucursal\ProductoController;
use App\Http\Controllers\Sucursal\SaleHistoryController;
use App\Http\Controllers\Sucursal\ShiftController as SucursalShiftController;
use App\Http\Controllers\Sucursal\WithdrawalController;
use App\Http\Controllers\Sucursal\UsuarioController as SucursalUsuarioController;
use App\Http\Controllers\Sucursal\MenuQrController;
use App\Http\Controllers\Sucursal\WorkbenchController;
use App\Http\Controllers\Sucursal\Metrics\MetricsIndexController as SucursalMetricsIndexController;
use App\Http\Controllers\Sucursal\Metrics\SalesMetricsController as SucursalSalesMetricsController;
use App\Http\Controllers\Sucursal\Metrics\MarginMetricsController as SucursalMarginMetricsController;
use App\Http\Controllers\Sucursal\Metrics\ProductMetricsController as SucursalProductMetricsController;
use App\Http\Controllers\Sucursal\Metrics\CustomerMetricsController as SucursalCustomerMetricsController;
use App\Http\Controllers\Sucursal\Metrics\CashierMetricsController as SucursalCashierMetricsController;
use App\Http\Controllers\Sucursal\Metrics\ShiftMetricsController as SucursalShiftMetricsController;
use App\Http\Controllers\Sucursal\Metrics\CollectionMetricsController as SucursalCollectionMetricsController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
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

// Public SPA for online ordering. Reserved '/menu' prefix — no collision with tenant routes.
Route::get('/menu/{tenantSlug}/{any?}', fn () => view('public-spa'))
    ->where('tenantSlug', '[a-z0-9-]+')
    ->where('any', '.*')
    ->name('public.menu');

Route::get('/dashboard', function () {
    $user = Auth::user();

    if ($user->hasRole('superadmin')) {
        return redirect()->route('admin.dashboard');
    }

    $slug = $user->tenant?->slug;

    return match (true) {
        $user->hasRole('admin-empresa') => redirect()->route('empresa.dashboard', $slug),
        $user->hasRole('admin-sucursal') => redirect()->route('sucursal.dashboard', $slug),
        $user->hasRole('cajero') => redirect()->route('caja.workbench', $slug),
        default => redirect()->route('login'),
    };
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

        Route::get('empresas/{empresa}', [EmpresaController::class, 'show'])
            ->whereNumber('empresa')
            ->name('empresas.show');

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

                Route::get('tickets', [TicketConfigController::class, 'index'])->name('tickets');
                Route::put('tickets/{branch}', [TicketConfigController::class, 'update'])->name('tickets.update');

                // Métricas (multi-sucursal)
                Route::prefix('metricas')->name('metricas.')->group(function () {
                    Route::get('/', EmpresaMetricsIndexController::class)->name('index');
                    Route::get('ventas', EmpresaSalesMetricsController::class)->name('ventas');
                    Route::get('margen', EmpresaMarginMetricsController::class)->name('margen');
                    Route::get('productos', EmpresaProductMetricsController::class)->name('productos');
                    Route::get('clientes', EmpresaCustomerMetricsController::class)->name('clientes');
                    Route::get('cajeros', EmpresaCashierMetricsController::class)->name('cajeros');
                    Route::get('turnos', EmpresaShiftMetricsController::class)->name('turnos');
                    Route::get('cobranza', EmpresaCollectionMetricsController::class)->name('cobranza');
                });
            });

        // Admin sucursal routes
        Route::middleware('role:admin-sucursal|superadmin')
            ->prefix('sucursal')
            ->name('sucursal.')
            ->group(function () {
                Route::get('/', [SucursalDashboardController::class, 'index'])->name('dashboard');

                Route::resource('productos', ProductoController::class)
                    ->except('show');
                Route::patch('productos/{producto}/quick', [ProductoController::class, 'quickToggle'])->name('productos.quick');
                Route::get('productos/{producto}/snapshot', [ProductoController::class, 'snapshot'])->name('productos.snapshot');

                Route::resource('usuarios', SucursalUsuarioController::class)
                    ->except('show');

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

                // Pagos
                Route::get('pagos', [PagosController::class, 'index'])->name('pagos.index');

                // Cortes (history)
                Route::get('cortes', [CashShiftController::class, 'history'])->name('cortes.index');
                Route::get('cortes/{shift}', [CashShiftController::class, 'show'])->name('cortes.show');
                Route::post('cortes/{shift}/recalcular', [CashShiftController::class, 'recalculate'])->name('cortes.recalculate');
                Route::post('cortes/{shift}/reabrir', [CashShiftController::class, 'reopen'])->name('cortes.reopen');

                // Categories
                Route::get('categorias', [CategoryController::class, 'index'])->name('categorias.index');
                Route::post('categorias', [CategoryController::class, 'store'])->name('categorias.store');
                Route::put('categorias/{category}', [CategoryController::class, 'update'])->name('categorias.update');
                Route::delete('categorias/{category}', [CategoryController::class, 'destroy'])->name('categorias.destroy');

                // Workbench
                Route::get('mesa-de-trabajo', [WorkbenchController::class, 'index'])->name('workbench');
                Route::post('mesa-de-trabajo/ventas', [WorkbenchController::class, 'store'])->name('workbench.store');
                Route::patch('mesa-de-trabajo/ventas/{sale}/cancelar', [WorkbenchController::class, 'cancel'])->name('workbench.cancel');
                Route::patch('mesa-de-trabajo/ventas/{sale}/reabrir', [WorkbenchController::class, 'reopen'])->name('workbench.reopen');
                Route::patch('mesa-de-trabajo/ventas/{sale}/estado', [WorkbenchController::class, 'updateStatus'])->name('workbench.update-status');
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

                // Customers
                Route::get('clientes', [CustomerController::class, 'index'])->name('clientes.index');
                Route::post('clientes', [CustomerController::class, 'store'])->name('clientes.store');
                Route::put('clientes/{customer}', [CustomerController::class, 'update'])->name('clientes.update');
                Route::delete('clientes/{customer}', [CustomerController::class, 'destroy'])->name('clientes.destroy');

                // Customer prices
                Route::post('clientes/{customer}/precios', [CustomerPriceController::class, 'store'])->name('clientes.precios.store');
                Route::put('clientes/{customer}/precios/{price}', [CustomerPriceController::class, 'update'])->name('clientes.precios.update');
                Route::delete('clientes/{customer}/precios/{price}', [CustomerPriceController::class, 'destroy'])->name('clientes.precios.destroy');

                // Customer stats dashboard (JSON endpoints, lazy-loaded per tab)
                Route::get('clientes/{customer}/stats', [CustomerStatsController::class, 'stats'])->name('clientes.stats');
                Route::get('clientes/{customer}/historial', [CustomerStatsController::class, 'history'])->name('clientes.historial');
                Route::get('clientes/{customer}/productos-top', [CustomerStatsController::class, 'topProducts'])->name('clientes.productos-top');
                Route::get('clientes/{customer}/pagos', [CustomerStatsController::class, 'payments'])->name('clientes.pagos');
                Route::get('clientes/{customer}/ventas/{sale}', [CustomerStatsController::class, 'saleDetail'])->name('clientes.venta-detalle');

                // Customer global payments (cobro global)
                Route::post('clientes/{customer}/cobro-global', [CustomerPaymentController::class, 'store'])->name('clientes.cobro-global');
                Route::get('clientes/{customer}/cobros-globales/{customerPayment}', [CustomerPaymentController::class, 'show'])->name('clientes.cobro-global.show');
                Route::delete('clientes/{customer}/cobros-globales/{customerPayment}', [CustomerPaymentController::class, 'destroy'])->name('clientes.cobro-global.cancel');

                // Assign customer to sale
                Route::patch('mesa-de-trabajo/ventas/{sale}/cliente', [WorkbenchController::class, 'assignCustomer'])->name('workbench.assign-customer');

                // WhatsApp link to customer (on-demand, no payload bloat)
                Route::get('mesa-de-trabajo/ventas/{sale}/whatsapp-link', [WorkbenchController::class, 'whatsappLink'])->name('workbench.whatsapp-link');

                // Config
                Route::get('configuracion', [SucursalConfiguracionController::class, 'edit'])->name('configuracion');
                Route::put('configuracion', [SucursalConfiguracionController::class, 'update'])->name('configuracion.update');

                // Menú online (QR + link público)
                Route::get('menu-online', [MenuQrController::class, 'show'])->name('menu-online');

                // Métricas (una sucursal)
                Route::prefix('metricas')->name('metricas.')->group(function () {
                    Route::get('/', SucursalMetricsIndexController::class)->name('index');
                    Route::get('ventas', SucursalSalesMetricsController::class)->name('ventas');
                    Route::get('margen', SucursalMarginMetricsController::class)->name('margen');
                    Route::get('productos', SucursalProductMetricsController::class)->name('productos');
                    Route::get('clientes', SucursalCustomerMetricsController::class)->name('clientes');
                    Route::get('cajeros', SucursalCashierMetricsController::class)->name('cajeros');
                    Route::get('turnos', SucursalShiftMetricsController::class)->name('turnos');
                    Route::get('cobranza', SucursalCollectionMetricsController::class)->name('cobranza');
                });
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
                Route::patch('ventas/{sale}/estado', [CajaWorkbenchController::class, 'updateStatus'])->name('update-status');
                Route::post('ventas/{sale}/solicitar-cancelacion', [CajaWorkbenchController::class, 'requestCancel'])->name('request-cancel');
                Route::get('turno', [CajaTurnoController::class, 'index'])->name('turno');
                Route::post('turno/abrir', [CajaTurnoController::class, 'open'])->name('turno.open');
                Route::post('turno/cerrar', [CajaTurnoController::class, 'close'])->name('turno.close');
                Route::get('turno/corte/{shift}', [CajaTurnoController::class, 'showCorte'])->name('turno.corte');
                Route::get('historial', [CajaHistorialController::class, 'index'])->name('historial');
                Route::get('pagos', [CajaPagosController::class, 'index'])->name('pagos');
            });
    });

require __DIR__.'/auth.php';
