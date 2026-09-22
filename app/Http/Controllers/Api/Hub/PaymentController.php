<?php

namespace App\Http\Controllers\Api\Hub;

use App\Enums\SaleStatus;
use App\Events\SaleUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Hub\HubSaleResource;
use App\Models\Branch;
use App\Models\CashRegisterShift;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DailySummaryService;
use App\Services\Metrics\DateRange;
use App\Services\SalePaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(private SalePaymentService $payments) {}

    /**
     * Listado de pagos del día (pantalla de Pagos). Espeja la web:
     * admin-sucursal ve TODOS los cobros de la sucursal y puede filtrar por
     * cajero (`user_id`); el cajero solo ve los suyos (Caja\PagosController).
     */
    public function index(Request $request, DailySummaryService $summary): JsonResponse
    {
        $request->validate([
            'method' => 'nullable|string',
            'date' => 'nullable|date',
            'user_id' => 'nullable|integer',
            // `DateRange::fromRequest` se traga cualquier problema y devuelve
            // `today` con un 200: datos que nadie pidió, y nadie se entera.
            // `required_with` es lo que de verdad cierra el «sólo mandé from».
            'preset' => 'nullable|in:'.implode(',', DateRange::PRESETS),
            'from' => 'nullable|date|required_with:to',
            'to' => 'nullable|date|required_with:from|after_or_equal:from',
            // 'with' = pagos de ventas con cliente; 'without' = de mostrador
            // (misma semántica que Sucursal\PagosController).
            'customer' => 'nullable|in:with,without',
        ]);

        $user = $request->user();
        app()->instance('tenant', $user->tenant);

        $branchId = $user->branch_id;
        $isAdmin = $user->hasRole('admin-sucursal');
        // `date` se sigue admitiendo y equivale al rango de ese día: hay
        // tablets con la 1.4.0 instalada que sólo saben mandar eso.
        $legacyDate = $request->date;
        $range = DateRange::fromRequest(
            $request->input('preset'),
            $request->input('from') ?: $legacyDate,
            $request->input('to') ?: $legacyDate,
        );
        $branch = Branch::withoutGlobalScopes()->find($branchId);
        $methods = $branch?->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        $baseQuery = Payment::whereHas('sale', function ($q) use ($branchId, $request) {
            $q->where('branch_id', $branchId);
            if ($request->customer === 'with') {
                $q->whereNotNull('customer_id');
            } elseif ($request->customer === 'without') {
                $q->whereNull('customer_id');
            }
        })
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->whereBetween('payments.created_at', [$range->start, $range->end]);

        if ($isAdmin) {
            $baseQuery->when($request->user_id, fn ($q, $id) => $q->where('payments.user_id', $id));
        } else {
            $baseQuery->where('payments.user_id', $user->id);
        }

        // El resumen responde al rango y al cajero; **nunca al método ni al
        // cliente**. Las pastillas de método enseñan el importe de cada uno, y
        // filtrarlas por el método elegido las pondría en cero: ya no habría
        // con qué comparar. Es la semántica de la web, a propósito.
        //
        // Y «al cajero» es distinto según quién pregunte: el admin filtra por
        // quien haya pedido; un cajero **siempre por sí mismo, lo pida o no**.
        // La consulta de la lista ya se lo fuerza; el servicio no sabe de roles.
        $resumenUserId = $isAdmin ? $request->user_id : $user->id;
        $c = $summary->collectionsForRange($range, $branchId, $user->tenant_id, $methods, $resumenUserId);

        // Un cobro global (FIFO) reparte UN pago grande en varios pagos hijos
        // (mismo customer_payment_id). En la lista lo colapsamos a un solo
        // renglón dejando un representante; los KPIs de arriba ya sumaron todo.
        $payments = $baseQuery
            ->where(function ($q) {
                $q->whereNull('payments.customer_payment_id')
                    ->orWhereIn('payments.id', function ($sub) {
                        $sub->from('payments')->selectRaw('MIN(id)')
                            ->whereNotNull('customer_payment_id')
                            ->groupBy('customer_payment_id');
                    });
            })
            ->with([
                'sale:id,folio,total,status,branch_id,amount_paid,amount_pending,created_at,customer_id',
                'sale.customer:id,name',
                // Los demás cobros de esa venta: sin ellos el panel no puede
                // decir si el que se está mirando fue el único. La web ya los
                // cargaba; aquí faltaban.
                'sale.payments' => fn ($q) => $q->with(['user:id,name', 'updatedByUser:id,name']),
                'user:id,name',
                'updatedByUser:id,name',
                'customerPayment:id,folio,customer_id,amount_applied,method,user_id,created_at',
                'customerPayment.customer:id,name',
                'customerPayment.user:id,name',
            ])
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->paginate(20);

        $data = $payments->getCollection()->map(function (Payment $p) {
            // Renglón de cobro global reconstruido a partir del padre.
            if ($p->customer_payment_id && $p->customerPayment) {
                $cp = $p->customerPayment;

                return [
                    'id' => 'cg-'.$cp->id,
                    'type' => 'global',
                    'folio' => $cp->folio,
                    'amount' => (float) $cp->amount_applied,
                    'method' => $cp->method instanceof \BackedEnum ? $cp->method->value : $cp->method,
                    'created_at' => $cp->created_at?->toIso8601String(),
                    'user' => $cp->user ? ['id' => $cp->user->id, 'name' => $cp->user->name] : null,
                    'customer' => $cp->customer ? ['id' => $cp->customer->id, 'name' => $cp->customer->name] : null,
                    'sale' => null,
                ];
            }

            return [
                'id' => 'p-'.$p->id,
                'type' => 'sale',
                'folio' => null,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'created_at' => $p->created_at?->toIso8601String(),
                'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name] : null,
                // Badge "Editado" (correcciones de admin), como la web.
                'updated_by_user' => $p->updatedByUser ? ['id' => $p->updatedByUser->id, 'name' => $p->updatedByUser->name] : null,
                'customer' => null,
                'sale' => $p->sale ? [
                    'id' => $p->sale->id,
                    'folio' => $p->sale->folio,
                    'total' => (float) $p->sale->total,
                    'status' => $p->sale->status instanceof \BackedEnum ? $p->sale->status->value : $p->sale->status,
                    'amount_paid' => (float) $p->sale->amount_paid,
                    'amount_pending' => (float) $p->sale->amount_pending,
                    // Para el chip "Venta de ayer/del DD-mmm" en pagos retroactivos.
                    'created_at' => $p->sale->created_at?->toIso8601String(),
                    'customer' => $p->sale->customer ? ['id' => $p->sale->customer->id, 'name' => $p->sale->customer->name] : null,
                    // Misma forma que en HubSaleResource, incluido
                    // customer_payment_id: es lo que decide si el botón de
                    // corregir aparece.
                    'payments' => $p->sale->payments->map(fn (Payment $sp) => [
                        'id' => $sp->id,
                        'method' => $sp->method,
                        'amount' => (float) $sp->amount,
                        'created_at' => $sp->created_at?->toIso8601String(),
                        'customer_payment_id' => $sp->customer_payment_id,
                        'user' => $sp->relationLoaded('user') && $sp->user
                            ? ['id' => $sp->user->id, 'name' => $sp->user->name] : null,
                        'updated_by_user' => $sp->relationLoaded('updatedByUser') && $sp->updatedByUser
                            ? ['id' => $sp->updatedByUser->id, 'name' => $sp->updatedByUser->name] : null,
                    ])->values(),
                ] : null,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'total' => $payments->total(),
            ],
            'summary' => [
                // `date` conserva su forma —una cadena `yyyy-MM-dd`, el primer
                // día del rango— porque las tablets con la 1.4.0 ya la leen
                // así. `from`/`to` se añaden al lado.
                'date' => $range->start->toDateString(),
                'from' => $range->start->toDateString(),
                'to' => $range->end->toDateString(),
                'total' => (float) $c['total'],
                // El servicio devuelve una LISTA de objetos; aquí se emite el
                // mapa de siempre. Enchufarlo directo dejaría las pastillas de
                // método en $0 en toda tablet ya instalada.
                'by_method' => collect($c['by_method'])
                    ->mapWithKeys(fn (array $m) => [$m['method'] => (float) $m['total']])
                    ->all(),
                'from_today' => (float) $c['from_today'],
                'from_previous' => (float) $c['from_previous'],
                // Filas de la lista, con el cobro a cuenta ya colapsado: el
                // servicio cuenta cada pago hijo y la cabecera diría de más.
                'payment_count' => $payments->total(),
            ],
            'users' => $isAdmin
                ? User::where('branch_id', $branchId)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()
                : [],
            'payment_methods' => $branch?->payment_methods_enabled ?? ['cash', 'card', 'transfer'],
            'is_admin' => $isAdmin,
        ]);
    }

    public function store(Request $request, int $sale): JsonResponse
    {
        $user = $request->user();

        // Resolución manual con filtro de sucursal (sin TenantScope): 404 si no
        // es de la sucursal del usuario del token.
        $sale = Sale::withoutGlobalScopes()
            ->where('branch_id', $user->branch_id)
            ->findOrFail($sale);

        if (in_array($sale->status, [SaleStatus::Completed, SaleStatus::Cancelled], true)) {
            return response()->json(['message' => 'No se pueden registrar pagos en esta venta.'], 422);
        }

        $hasOpenShift = CashRegisterShift::where('user_id', $user->id)->whereNull('closed_at')->exists();
        if (! $hasOpenShift) {
            return response()->json(['message' => 'Abre un turno antes de cobrar.'], 409);
        }

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $allowed = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        $validated = $request->validate([
            'method' => 'required|in:'.implode(',', $allowed),
            'amount' => 'required|numeric|gt:0',
            'client_reference' => 'nullable|string|max:64',
        ]);

        $clientReference = $validated['client_reference'] ?? null;

        // Idempotencia: si ya existe un pago con este (sale_id, client_reference),
        // devolverlo sin crear otro (reintento del hub).
        if ($clientReference !== null && $existing = $this->findByClientReference($sale, $clientReference)) {
            return $this->replayResponse($sale, $existing);
        }

        $change = 0.0;

        try {
            $payment = DB::transaction(function () use ($sale, $user, $validated, $clientReference, &$change) {
                $actualPayment = min((float) $validated['amount'], (float) $sale->amount_pending);

                $payment = Payment::create([
                    'sale_id' => $sale->id,
                    'user_id' => $user->id,
                    'method' => $validated['method'],
                    'amount' => round($actualPayment, 2),
                    'client_reference' => $clientReference,
                ]);

                $this->payments->recalculate($sale, $user);
                $change = round((float) $validated['amount'] - $actualPayment, 2);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dos reintentos del hub a la vez: la consulta de arriba no vio nada
            // y el índice único (sale_id, client_reference) frenó al segundo. El
            // dinero no se duplicó, así que esto NO es un error — es el mismo
            // cobro llegando dos veces. Sin esto salía un 500 que el hub leía
            // como fallo del servidor y volvía a reintentar un cobro ya hecho.
            $existing = $this->findByClientReference($sale, $clientReference);

            if (! $existing) {
                throw $e;
            }

            return $this->replayResponse($sale, $existing);
        }

        $sale->load(['items', 'payments']);

        return response()->json([
            'payment' => ['id' => $payment->id, 'method' => $payment->method, 'amount' => (float) $payment->amount],
            'change' => $change,
            'sale' => HubSaleResource::make($sale),
        ], 201);
    }

    /** El pago ya registrado para este (venta, referencia del cliente), si lo hay. */
    private function findByClientReference(Sale $sale, ?string $clientReference): ?Payment
    {
        if ($clientReference === null) {
            return null;
        }

        return Payment::where('sale_id', $sale->id)
            ->where('client_reference', $clientReference)
            ->first();
    }

    /**
     * Respuesta de un reintento: el cobro ya estaba hecho. Va con 200 (no 201)
     * y sin vuelto — el cambio se entregó en el intento que sí llegó.
     */
    private function replayResponse(Sale $sale, Payment $existing): JsonResponse
    {
        $sale->load(['items', 'payments']);

        return response()->json([
            'payment' => ['id' => $existing->id, 'method' => $existing->method, 'amount' => (float) $existing->amount],
            'change' => 0.0,
            'sale' => HubSaleResource::make($sale),
        ], 200);
    }

    /**
     * Corrige método/monto de un pago (paridad con Sucursal\PaymentController::update):
     * solo admin, tope = total − otros pagos, protege pagos de cobro global.
     */
    public function update(Request $request, int $sale, int $payment): JsonResponse
    {
        $user = $request->user();
        [$foundSale, $foundPayment] = $this->findPaymentForAdmin($request, $sale, $payment);

        if ($foundSale->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'No se pueden modificar pagos de una venta cancelada.'], 422);
        }

        if ($guard = $this->guardGlobalPaymentChild($foundPayment, 'editarse')) {
            return $guard;
        }

        $branch = Branch::withoutGlobalScopes()->findOrFail($user->branch_id);
        $allowed = $branch->payment_methods_enabled ?? ['cash', 'card', 'transfer'];

        $otherPaymentsTotal = $foundSale->payments()->where('id', '!=', $foundPayment->id)->sum('amount');
        $maxAmount = round((float) $foundSale->total - $otherPaymentsTotal, 2);

        $validated = $request->validate([
            'method' => 'required|in:'.implode(',', $allowed),
            'amount' => "required|numeric|gt:0|max:{$maxAmount}",
        ], [
            'method.in' => 'El método de pago seleccionado no está habilitado para esta sucursal.',
            'amount.max' => "El monto no puede exceder \${$maxAmount} (total de la venta menos otros pagos).",
        ]);

        DB::transaction(function () use ($foundPayment, $foundSale, $user, $validated) {
            // Antes del update: después, el monto anterior no existe en ningún lado.
            $amountBefore = (float) $foundPayment->amount;
            $methodBefore = (string) $foundPayment->method;

            $foundPayment->update(array_merge($validated, ['updated_by' => $user->id]));
            $this->payments->recalculate($foundSale, $user);

            app(AuditLogger::class)->logPaymentUpdated(
                $foundSale,
                $amountBefore,
                (float) $validated['amount'],
                $methodBefore,
                (string) $validated['method'],
                $user->id,
            );
        });

        $this->broadcastSaleUpdate($foundSale);

        return $this->saleResponse($request, $foundSale);
    }

    /**
     * Elimina un pago y recalcula la venta (paridad con la web): solo admin,
     * protege pagos de cobro global.
     */
    public function destroy(Request $request, int $sale, int $payment): JsonResponse
    {
        $user = $request->user();
        [$foundSale, $foundPayment] = $this->findPaymentForAdmin($request, $sale, $payment);

        if ($foundSale->status === SaleStatus::Cancelled) {
            return response()->json(['message' => 'No se pueden modificar pagos de una venta cancelada.'], 422);
        }

        if ($guard = $this->guardGlobalPaymentChild($foundPayment, 'eliminarse')) {
            return $guard;
        }

        DB::transaction(function () use ($foundPayment, $foundSale, $user) {
            $amount = (float) $foundPayment->amount;
            $method = (string) $foundPayment->method;

            $foundPayment->delete();
            $this->payments->recalculate($foundSale, $user);

            app(AuditLogger::class)->logPaymentDeleted($foundSale, $amount, $method, $user->id);
        });

        $this->broadcastSaleUpdate($foundSale);

        return $this->saleResponse($request, $foundSale);
    }

    /**
     * Venta de la sucursal + pago de esa venta, con guard de rol admin
     * (espeja authorizePaymentAction de la web; admin-empresa no entra al hub).
     *
     * @return array{0: Sale, 1: Payment}
     */
    private function findPaymentForAdmin(Request $request, int $sale, int $payment): array
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('admin-sucursal') || $user->hasRole('superadmin'),
            403,
            'No tienes permiso para modificar pagos.'
        );

        $foundSale = Sale::withoutGlobalScopes()
            ->where('branch_id', $user->branch_id)
            ->findOrFail($sale);

        $foundPayment = Payment::where('sale_id', $foundSale->id)->findOrFail($payment);

        return [$foundSale, $foundPayment];
    }

    /** 422 si el pago pertenece a un cobro global (no editable individualmente). */
    private function guardGlobalPaymentChild(Payment $payment, string $verb): ?JsonResponse
    {
        if ($payment->customer_payment_id === null) {
            return null;
        }

        $payment->loadMissing('customerPayment:id,folio');
        $folio = $payment->customerPayment?->folio ?? 'global';

        return response()->json(['message' => "Este pago es parte del cobro {$folio} y no puede {$verb} individualmente."], 422);
    }

    private function saleResponse(Request $request, Sale $sale): JsonResponse
    {
        return response()->json([
            'data' => HubSaleResource::make($sale->refresh()->load(['items', 'payments', 'customer']))->resolve($request),
        ]);
    }

    /** Broadcast tolerante a Reverb caído (nunca rompe la operación). */
    private function broadcastSaleUpdate(Sale $sale): void
    {
        try {
            SaleUpdated::dispatch($sale->fresh());
        } catch (\Throwable $e) {
            Log::warning('SaleUpdated broadcast failed', ['sale_id' => $sale->id, 'error' => $e->getMessage()]);
        }
    }
}
