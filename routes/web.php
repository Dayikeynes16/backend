<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmpresaController;
use App\Http\Controllers\Admin\PasswordResetController as AdminPasswordResetController;
use App\Http\Controllers\Ai\CategoryDraftController as AiCategoryDraftController;
use App\Http\Controllers\Ai\ExpenseDraftController as AiExpenseDraftController;
use App\Http\Controllers\Ai\PurchaseDraftController as AiPurchaseDraftController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Caja\HistorialController as CajaHistorialController;
use App\Http\Controllers\Caja\PagosController as CajaPagosController;
use App\Http\Controllers\Caja\GastoController as CajaGastoController;
use App\Http\Controllers\Caja\TurnoController as CajaTurnoController;
use App\Http\Controllers\Caja\WorkbenchController as CajaWorkbenchController;
use App\Http\Controllers\Empresa\AsistenteController as EmpresaAsistenteController;
use App\Http\Controllers\Empresa\ConfiguracionController;
use App\Http\Controllers\Empresa\DashboardController as EmpresaDashboardController;
use App\Http\Controllers\Empresa\ExpenseCategoryController as EmpresaExpenseCategoryController;
use App\Http\Controllers\Empresa\ExpenseSubcategoryController as EmpresaExpenseSubcategoryController;
use App\Http\Controllers\Empresa\GastoController as EmpresaGastoController;
use App\Http\Controllers\Empresa\Metrics\CancellationMetricsController as EmpresaCancellationMetricsController;
use App\Http\Controllers\Empresa\Metrics\CashierMetricsController as EmpresaCashierMetricsController;
use App\Http\Controllers\Empresa\Metrics\CollectionMetricsController as EmpresaCollectionMetricsController;
use App\Http\Controllers\Empresa\Metrics\CustomerMetricsController as EmpresaCustomerMetricsController;
use App\Http\Controllers\Empresa\Metrics\MarginMetricsController as EmpresaMarginMetricsController;
use App\Http\Controllers\Empresa\Metrics\MetricsIndexController as EmpresaMetricsIndexController;
use App\Http\Controllers\Empresa\Metrics\ProductMetricsController as EmpresaProductMetricsController;
use App\Http\Controllers\Empresa\Metrics\SalesMetricsController as EmpresaSalesMetricsController;
use App\Http\Controllers\Empresa\Metrics\ShiftMetricsController as EmpresaShiftMetricsController;
use App\Http\Controllers\Empresa\PasswordResetController as EmpresaPasswordResetController;
use App\Http\Controllers\Empresa\PersonalizacionController;
use App\Http\Controllers\Empresa\ProviderController as EmpresaProviderController;
use App\Http\Controllers\Empresa\PurchaseProductController as EmpresaPurchaseProductController;
use App\Http\Controllers\Empresa\ProviderPaymentController as EmpresaProviderPaymentController;
use App\Http\Controllers\Empresa\PurchaseController as EmpresaPurchaseController;
use App\Http\Controllers\Empresa\SucursalController;
use App\Http\Controllers\Empresa\TicketConfigController;
use App\Http\Controllers\Empresa\UsuarioController as EmpresaUsuarioController;
use App\Http\Controllers\ExpenseAttachmentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseAttachmentController;
use App\Http\Controllers\Sucursal\ApiKeyController;
use App\Http\Controllers\Sucursal\AsistenteController as SucursalAsistenteController;
use App\Http\Controllers\Sucursal\CancelRequestController;
use App\Http\Controllers\Sucursal\CashShiftController;
use App\Http\Controllers\Sucursal\CategoryController;
use App\Http\Controllers\Sucursal\ConfiguracionController as SucursalConfiguracionController;
use App\Http\Controllers\Sucursal\CustomerController;
use App\Http\Controllers\Sucursal\CustomerPaymentController;
use App\Http\Controllers\Sucursal\CustomerPriceController;
use App\Http\Controllers\Sucursal\CustomerStatsController;
use App\Http\Controllers\Sucursal\DashboardController as SucursalDashboardController;
use App\Http\Controllers\Sucursal\GastoController as SucursalGastoController;
use App\Http\Controllers\Sucursal\MenuQrController;
use App\Http\Controllers\Sucursal\Metrics\CancellationMetricsController as SucursalCancellationMetricsController;
use App\Http\Controllers\Sucursal\Metrics\CashierMetricsController as SucursalCashierMetricsController;
use App\Http\Controllers\Sucursal\Metrics\CollectionMetricsController as SucursalCollectionMetricsController;
use App\Http\Controllers\Sucursal\Metrics\CustomerMetricsController as SucursalCustomerMetricsController;
use App\Http\Controllers\Sucursal\Metrics\MarginMetricsController as SucursalMarginMetricsController;
use App\Http\Controllers\Sucursal\Metrics\MetricsIndexController as SucursalMetricsIndexController;
use App\Http\Controllers\Sucursal\Metrics\ProductMetricsController as SucursalProductMetricsController;
use App\Http\Controllers\Sucursal\Metrics\SalesMetricsController as SucursalSalesMetricsController;
use App\Http\Controllers\Sucursal\Metrics\ShiftMetricsController as SucursalShiftMetricsController;
use App\Http\Controllers\Sucursal\PagosController;
use App\Http\Controllers\Sucursal\PaymentController;
use App\Http\Controllers\Sucursal\ProductoController;
use App\Http\Controllers\Sucursal\ProviderController as SucursalProviderController;
use App\Http\Controllers\Sucursal\ProviderPaymentController as SucursalProviderPaymentController;
use App\Http\Controllers\Sucursal\PurchaseController as SucursalPurchaseController;
use App\Http\Controllers\Sucursal\SaleHistoryController;
use App\Http\Controllers\Sucursal\SaleItemController;
use App\Http\Controllers\Sucursal\SaleLockController;
use App\Http\Controllers\Sucursal\UsuarioController as SucursalUsuarioController;
use App\Http\Controllers\Sucursal\WithdrawalController;
use App\Http\Controllers\Sucursal\WorkbenchController;
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
                    ->parameters(['sucursales' => 'sucursal']);

                Route::resource('usuarios', EmpresaUsuarioController::class)
                    ->except('show');

                Route::post('usuarios/{usuario}/send-reset', [EmpresaPasswordResetController::class, 'sendResetLink'])->name('usuarios.send-reset');
                Route::post('usuarios/{usuario}/force-reset', [EmpresaPasswordResetController::class, 'forceReset'])->name('usuarios.force-reset');

                Route::get('configuracion', [ConfiguracionController::class, 'edit'])->name('configuracion');
                Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');

                Route::get('personalizacion', [PersonalizacionController::class, 'edit'])->name('personalizacion');
                Route::post('personalizacion', [PersonalizacionController::class, 'update'])->name('personalizacion.update');
                Route::post('personalizacion/reset', [PersonalizacionController::class, 'reset'])->name('personalizacion.reset');

                // Asistente conversacional (F0 + F1 + F4 voz).
                Route::get('asistente', [EmpresaAsistenteController::class, 'index'])->name('asistente');
                Route::post('asistente/sesiones', [EmpresaAsistenteController::class, 'createSession'])->name('asistente.sesiones.store');
                Route::post('asistente/sesiones/{session}/mensajes', [EmpresaAsistenteController::class, 'sendMessage'])->name('asistente.mensajes.store');
                Route::post('asistente/sesiones/{session}/mensajes/{message}/voz', [EmpresaAsistenteController::class, 'speak'])->name('asistente.mensajes.voz');
                Route::post('asistente/transcribir', [EmpresaAsistenteController::class, 'transcribe'])->name('asistente.transcribir');

                // Proveedores (F1 de Compras).
                Route::resource('proveedores', EmpresaProviderController::class)
                    ->parameters(['proveedores' => 'provider'])
                    ->except('create', 'edit');
                // Pago a cuenta del proveedor (F3) — FIFO sobre sus compras pendientes.
                Route::post('proveedores/{provider}/pagos', [EmpresaProviderPaymentController::class, 'storeForProvider'])
                    ->whereNumber('provider')->name('proveedores.pagos.store');

                // Catálogo de productos de compra (tenant-wide).
                Route::get('productos-compra', [EmpresaPurchaseProductController::class, 'index'])->name('productos-compra.index');
                Route::post('productos-compra', [EmpresaPurchaseProductController::class, 'store'])->name('productos-compra.store');
                Route::put('productos-compra/{producto_compra}', [EmpresaPurchaseProductController::class, 'update'])->name('productos-compra.update');
                Route::delete('productos-compra/{producto_compra}', [EmpresaPurchaseProductController::class, 'destroy'])->name('productos-compra.destroy');

                // Compras (F2).
                Route::prefix('compras')->name('compras.')->group(function () {
                    Route::get('/', [EmpresaPurchaseController::class, 'index'])->name('index');
                    Route::post('/', [EmpresaPurchaseController::class, 'store'])->name('store');
                    Route::put('{compra}', [EmpresaPurchaseController::class, 'update'])->whereNumber('compra')->name('update');
                    Route::patch('{compra}/cancelar', [EmpresaPurchaseController::class, 'cancel'])->whereNumber('compra')->name('cancel');

                    // Captura con IA (F4): texto + imágenes + audio → propuesta editable.
                    Route::post('ia/borrador', [AiPurchaseDraftController::class, 'store'])->name('ia.store');

                    // Pagos a proveedor sobre la compra (F3).
                    Route::post('{compra}/pagos', [EmpresaProviderPaymentController::class, 'storeForPurchase'])->whereNumber('compra')->name('pagos.store');
                    Route::delete('{compra}/pagos/{pago}', [EmpresaProviderPaymentController::class, 'destroyPayment'])->whereNumber('compra')->whereNumber('pago')->name('pagos.destroy');

                    // Adjuntos
                    Route::get('{compra}/adjuntos/{attachment}', [PurchaseAttachmentController::class, 'download'])->name('adjuntos.download');
                    Route::get('{compra}/adjuntos/{attachment}/preview', [PurchaseAttachmentController::class, 'preview'])->name('adjuntos.preview');
                    Route::delete('{compra}/adjuntos/{attachment}', [PurchaseAttachmentController::class, 'destroy'])->name('adjuntos.destroy');
                });

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
                    Route::get('cancelaciones', EmpresaCancellationMetricsController::class)->name('cancelaciones');
                });

                // Gastos
                Route::prefix('gastos')->name('gastos.')->group(function () {
                    // Categorías y subcategorías (van primero — rutas más específicas
                    // antes de las dinámicas {gasto} para evitar conflictos).
                    Route::post('categorias', [EmpresaExpenseCategoryController::class, 'store'])->name('categorias.store');
                    Route::put('categorias/{category}', [EmpresaExpenseCategoryController::class, 'update'])->name('categorias.update');
                    Route::delete('categorias/{category}', [EmpresaExpenseCategoryController::class, 'destroy'])->name('categorias.destroy');

                    Route::post('subcategorias', [EmpresaExpenseSubcategoryController::class, 'store'])->name('subcategorias.store');
                    Route::put('subcategorias/{subcategory}', [EmpresaExpenseSubcategoryController::class, 'update'])->name('subcategorias.update');
                    Route::delete('subcategorias/{subcategory}', [EmpresaExpenseSubcategoryController::class, 'destroy'])->name('subcategorias.destroy');

                    // Crear categoría con IA (Fase 3): draft + bulk apply transaccional.
                    Route::post('categorias/ia/borrador', [AiCategoryDraftController::class, 'store'])->name('categorias.ia.store');
                    Route::post('categorias/ia/aplicar', [EmpresaExpenseCategoryController::class, 'storeFromAiDraft'])->name('categorias.ia.apply');

                    // Gastos
                    Route::get('/', [EmpresaGastoController::class, 'index'])->name('index');
                    Route::post('/', [EmpresaGastoController::class, 'store'])->name('store');
                    Route::put('{gasto}', [EmpresaGastoController::class, 'update'])->name('update');
                    Route::delete('{gasto}', [EmpresaGastoController::class, 'destroy'])->name('destroy');

                    // Draft IA (Fase 1: texto + imagen → propuesta prellenada)
                    Route::post('ia/borrador', [AiExpenseDraftController::class, 'store'])->name('ia.store');

                    // Adjuntos
                    Route::get('{gasto}/adjuntos/{attachment}', [ExpenseAttachmentController::class, 'download'])->name('adjuntos.download');
                    Route::get('{gasto}/adjuntos/{attachment}/preview', [ExpenseAttachmentController::class, 'preview'])->name('adjuntos.preview');
                    Route::delete('{gasto}/adjuntos/{attachment}', [ExpenseAttachmentController::class, 'destroy'])->name('adjuntos.destroy');
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
                Route::get('productos/{producto}/price-breakdown', [ProductoController::class, 'priceBreakdown'])->name('productos.price-breakdown');

                Route::resource('usuarios', SucursalUsuarioController::class)
                    ->except('show');

                Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
                Route::delete('api-keys/{api_key}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
                Route::delete('api-keys/{api_key}/permanent', [ApiKeyController::class, 'forceDelete'])->name('api-keys.force-delete');

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
                Route::get('mesa-de-trabajo/pedidos-pendientes', [WorkbenchController::class, 'pendingWebOrders'])->name('workbench.pending-web-orders');
                Route::get('mesa-de-trabajo/ventas-vinculables', [WorkbenchController::class, 'linkableSales'])->name('workbench.linkable-sales');
                Route::post('mesa-de-trabajo/ventas', [WorkbenchController::class, 'store'])->name('workbench.store');
                Route::patch('mesa-de-trabajo/ventas/{sale}/cancelar', [WorkbenchController::class, 'cancel'])->name('workbench.cancel');
                Route::patch('mesa-de-trabajo/ventas/{sale}/reabrir', [WorkbenchController::class, 'reopen'])->name('workbench.reopen');
                Route::patch('mesa-de-trabajo/ventas/{sale}/estado', [WorkbenchController::class, 'updateStatus'])->name('workbench.update-status');
                Route::post('mesa-de-trabajo/ventas/{sale}/solicitar-cancelacion', [WorkbenchController::class, 'requestCancel'])->name('workbench.request-cancel');
                Route::post('mesa-de-trabajo/ventas/{sale}/vincular-pedido', [WorkbenchController::class, 'linkOrder'])->name('workbench.link-order');
                Route::delete('mesa-de-trabajo/ventas/{sale}/vincular-pedido', [WorkbenchController::class, 'unlinkOrder'])->name('workbench.unlink-order');

                // Sale locking
                Route::post('ventas/{sale}/lock', [SaleLockController::class, 'lock'])->name('sale.lock');
                Route::post('ventas/{sale}/unlock', [SaleLockController::class, 'unlock'])->name('sale.unlock');
                Route::post('ventas/{sale}/heartbeat', [SaleLockController::class, 'heartbeat'])->name('sale.heartbeat');

                // Payments
                Route::post('mesa-de-trabajo/ventas/{sale}/pagos', [PaymentController::class, 'store'])->name('workbench.payment');
                Route::put('mesa-de-trabajo/ventas/{sale}/pagos/{payment}', [PaymentController::class, 'update'])->name('workbench.payment.update');
                Route::delete('mesa-de-trabajo/ventas/{sale}/pagos/{payment}', [PaymentController::class, 'destroy'])->name('workbench.payment.destroy');

                // Items de una venta — solo admin-sucursal+ (gated por el grupo).
                // No se expone en Caja: el cajero no toca items.
                Route::post('mesa-de-trabajo/ventas/{sale}/items', [SaleItemController::class, 'store'])->name('workbench.items.store');
                Route::patch('mesa-de-trabajo/ventas/{sale}/items/{item}', [SaleItemController::class, 'update'])->name('workbench.items.update');
                Route::delete('mesa-de-trabajo/ventas/{sale}/items/{item}', [SaleItemController::class, 'destroy'])->name('workbench.items.destroy');
                Route::get('mesa-de-trabajo/ventas/{sale}/items/historial', [SaleItemController::class, 'history'])->name('workbench.items.history');

                // Cancelation requests
                Route::get('cancelaciones', [CancelRequestController::class, 'index'])->name('cancelaciones.index');
                Route::patch('cancelaciones/{sale}/aprobar', [CancelRequestController::class, 'approve'])->name('cancelaciones.approve');
                Route::patch('cancelaciones/{sale}/rechazar', [CancelRequestController::class, 'reject'])->name('cancelaciones.reject');

                // Customers
                Route::get('clientes', [CustomerController::class, 'index'])->name('clientes.index');
                Route::get('clientes/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('clientes.show');
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
                Route::post('mesa-de-trabajo/ventas/{sale}/whatsapp-phone', [WorkbenchController::class, 'storeWhatsappPhone'])->name('workbench.whatsapp-phone');
                Route::delete('mesa-de-trabajo/ventas/{sale}/whatsapp-phone', [WorkbenchController::class, 'destroyWhatsappPhone'])->name('workbench.whatsapp-phone.destroy');

                // Config
                Route::get('configuracion', [SucursalConfiguracionController::class, 'edit'])->name('configuracion');
                Route::put('configuracion', [SucursalConfiguracionController::class, 'update'])->name('configuracion.update');

                // Asistente conversacional (F2 + F4 voz).
                Route::get('asistente', [SucursalAsistenteController::class, 'index'])->name('asistente');
                Route::post('asistente/sesiones', [SucursalAsistenteController::class, 'createSession'])->name('asistente.sesiones.store');
                Route::post('asistente/sesiones/{session}/mensajes', [SucursalAsistenteController::class, 'sendMessage'])->name('asistente.mensajes.store');
                Route::post('asistente/sesiones/{session}/mensajes/{message}/voz', [SucursalAsistenteController::class, 'speak'])->name('asistente.mensajes.voz');
                Route::post('asistente/transcribir', [SucursalAsistenteController::class, 'transcribe'])->name('asistente.transcribir');

                // Proveedores (solo lectura — el CRUD vive en empresa).
                Route::get('proveedores', [SucursalProviderController::class, 'index'])->name('proveedores.index');
                // Pago a cuenta (F3): admin-sucursal solo puede saldar sus compras (FIFO scoped).
                Route::post('proveedores/{provider}/pagos', [SucursalProviderPaymentController::class, 'storeForProvider'])
                    ->whereNumber('provider')->name('proveedores.pagos.store');

                // Compras (F2). admin-sucursal sólo opera sobre su sucursal.
                Route::prefix('compras')->name('compras.')->group(function () {
                    Route::get('/', [SucursalPurchaseController::class, 'index'])->name('index');
                    Route::post('/', [SucursalPurchaseController::class, 'store'])->name('store');
                    Route::put('{compra}', [SucursalPurchaseController::class, 'update'])->whereNumber('compra')->name('update');
                    Route::patch('{compra}/cancelar', [SucursalPurchaseController::class, 'cancel'])->whereNumber('compra')->name('cancel');

                    // Captura con IA (F4): mismo controller que empresa.
                    Route::post('ia/borrador', [AiPurchaseDraftController::class, 'store'])->name('ia.store');

                    // Pagos a proveedor sobre la compra (F3).
                    Route::post('{compra}/pagos', [SucursalProviderPaymentController::class, 'storeForPurchase'])->whereNumber('compra')->name('pagos.store');
                    Route::delete('{compra}/pagos/{pago}', [SucursalProviderPaymentController::class, 'destroyPayment'])->whereNumber('compra')->whereNumber('pago')->name('pagos.destroy');

                    Route::get('{compra}/adjuntos/{attachment}', [PurchaseAttachmentController::class, 'download'])->name('adjuntos.download');
                    Route::get('{compra}/adjuntos/{attachment}/preview', [PurchaseAttachmentController::class, 'preview'])->name('adjuntos.preview');
                    Route::delete('{compra}/adjuntos/{attachment}', [PurchaseAttachmentController::class, 'destroy'])->name('adjuntos.destroy');
                });

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
                    Route::get('cancelaciones', SucursalCancellationMetricsController::class)->name('cancelaciones');
                });

                // Gastos
                Route::prefix('gastos')->name('gastos.')->group(function () {
                    Route::get('/', [SucursalGastoController::class, 'index'])->name('index');
                    Route::post('/', [SucursalGastoController::class, 'store'])->name('store');
                    Route::put('{gasto}', [SucursalGastoController::class, 'update'])->name('update');
                    Route::delete('{gasto}', [SucursalGastoController::class, 'destroy'])->name('destroy');

                    // Draft IA (mismo controller que empresa — la lógica es idéntica)
                    Route::post('ia/borrador', [AiExpenseDraftController::class, 'store'])->name('ia.store');

                    Route::get('{gasto}/adjuntos/{attachment}', [ExpenseAttachmentController::class, 'download'])->name('adjuntos.download');
                    Route::get('{gasto}/adjuntos/{attachment}/preview', [ExpenseAttachmentController::class, 'preview'])->name('adjuntos.preview');
                    Route::delete('{gasto}/adjuntos/{attachment}', [ExpenseAttachmentController::class, 'destroy'])->name('adjuntos.destroy');
                });
            });

        // Cajero routes
        Route::middleware('role:cajero|superadmin')
            ->prefix('caja')
            ->name('caja.')
            ->group(function () {
                Route::get('/', [CajaWorkbenchController::class, 'index'])->name('workbench');
                Route::get('pedidos-pendientes', [CajaWorkbenchController::class, 'pendingWebOrders'])->name('pending-web-orders');
                Route::get('ventas-vinculables', [CajaWorkbenchController::class, 'linkableSales'])->name('linkable-sales');
                Route::post('ventas/{sale}/pagos', [PaymentController::class, 'store'])->name('payment.store');
                Route::post('ventas/{sale}/lock', [SaleLockController::class, 'lock'])->name('sale.lock');
                Route::post('ventas/{sale}/unlock', [SaleLockController::class, 'unlock'])->name('sale.unlock');
                Route::post('ventas/{sale}/heartbeat', [SaleLockController::class, 'heartbeat'])->name('sale.heartbeat');
                Route::patch('ventas/{sale}/estado', [CajaWorkbenchController::class, 'updateStatus'])->name('update-status');
                Route::post('ventas/{sale}/solicitar-cancelacion', [CajaWorkbenchController::class, 'requestCancel'])->name('request-cancel');
                Route::post('ventas/{sale}/vincular-pedido', [CajaWorkbenchController::class, 'linkOrder'])->name('link-order');
                Route::delete('ventas/{sale}/vincular-pedido', [CajaWorkbenchController::class, 'unlinkOrder'])->name('unlink-order');
                Route::patch('ventas/{sale}/cliente', [CajaWorkbenchController::class, 'assignCustomer'])->name('assign-customer');
                Route::get('ventas/{sale}/whatsapp-link', [CajaWorkbenchController::class, 'whatsappLink'])->name('whatsapp-link');
                Route::post('ventas/{sale}/whatsapp-phone', [CajaWorkbenchController::class, 'storeWhatsappPhone'])->name('whatsapp-phone');
                Route::delete('ventas/{sale}/whatsapp-phone', [CajaWorkbenchController::class, 'destroyWhatsappPhone'])->name('whatsapp-phone.destroy');
                Route::get('turno', [CajaTurnoController::class, 'index'])->name('turno');
                Route::post('turno/abrir', [CajaTurnoController::class, 'open'])->name('turno.open');
                Route::post('turno/cerrar', [CajaTurnoController::class, 'close'])->name('turno.close');
                Route::get('turno/corte/{shift}', [CajaTurnoController::class, 'showCorte'])->name('turno.corte');
                Route::post('gastos', [CajaGastoController::class, 'store'])->name('gastos.store');
                Route::get('historial', [CajaHistorialController::class, 'index'])->name('historial');
                Route::get('pagos', [CajaPagosController::class, 'index'])->name('pagos');
            });
    });

require __DIR__.'/auth.php';
