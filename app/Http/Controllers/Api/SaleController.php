<?php

namespace App\Http\Controllers\Api;

use App\Enums\SaleStatus;
use App\Events\NewExternalSale;
use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Product;
use App\Models\Sale;
use App\Support\SafeBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.presentation_id' => 'nullable|integer',
            'payment_method' => 'required|in:cash,card,transfer',
            'origin_name' => 'nullable|string|max:100',
            'client_reference' => 'nullable|string|max:64',
            'contact_name' => 'nullable|string|max:255',
        ]);

        $branchId = $request->branch_id;
        $tenantId = $request->tenant_id;

        /*
         * Idempotencia: el hub reintenta cualquier fallo de red o 5xx, así que una
         * venta cuya respuesta se perdió por el camino volvería a llegar. Sin esto
         * se creaba una segunda venta idéntica —el mismo dinero contado dos veces—
         * y nadie se enteraba hasta cuadrar el turno.
         *
         * Se devuelve la venta existente en lugar de un error: para quien reintenta,
         * el resultado debe ser indistinguible de un envío que salió bien a la primera.
         */
        $clientReference = $request->input('client_reference');
        if ($clientReference !== null) {
            $existing = Sale::withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('client_reference', $clientReference)
                ->with('items')
                ->first();

            if ($existing) {
                /*
                 * Se vuelve a emitir el aviso. El reintento llega justamente
                 * cuando el primer envío no terminó bien, y el broadcast es lo
                 * primero que se pierde en ese escenario: si no se repite, la
                 * venta queda guardada pero invisible en la mesa de trabajo
                 * hasta que alguien recargue a mano.
                 *
                 * Repetirlo es seguro: el cliente deduplica por `id` y ya tiene
                 * la venta en pantalla si el primer aviso sí llegó.
                 */
                SafeBroadcast::dispatch(
                    fn () => NewExternalSale::dispatch($existing),
                    'NewExternalSale',
                    ['sale_id' => $existing->id, 'retry' => true],
                );

                // Misma forma exacta que la respuesta normal (más abajo): quien
                // reintenta no debe tener que distinguir un caso del otro.
                return response()->json(SaleResource::make($existing), 201);
            }
        }

        // Validate all products exist and are active in this branch
        $productIds = collect($request->items)->pluck('product_id')->unique();

        $products = Product::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereIn('id', $productIds)
            ->with(['presentations' => fn ($q) => $q->where('status', 'active')])
            ->get()
            ->keyBy('id');

        $missing = $productIds->diff($products->keys());

        if ($missing->isNotEmpty()) {
            return response()->json([
                'message' => 'Productos no válidos.',
                'errors' => [
                    'items' => $missing->map(fn ($id) => "Producto {$id} no existe o está inactivo.")->values(),
                ],
            ], 422);
        }

        $sale = DB::transaction(function () use ($request, $branchId, $tenantId, $products, $clientReference) {
            // Advisory lock per branch to prevent duplicate folios under concurrency
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$branchId]);

            $count = Sale::withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->count();

            $folioNumber = $count + 1;
            $folio = 'S-'.str_pad($folioNumber, 5, '0', STR_PAD_LEFT);

            // Calculate total
            $total = 0;
            $itemsData = [];

            foreach ($request->items as $item) {
                $product = $products[$item['product_id']];
                $quantity = (float) $item['quantity'];
                $presentationId = $item['presentation_id'] ?? null;
                $presentation = null;

                if ($presentationId && in_array($product->sale_mode, ['presentation', 'both'], true)) {
                    $presentation = $product->presentations->find($presentationId);
                    if (! $presentation) {
                        throw new \InvalidArgumentException("Presentación {$presentationId} no válida para {$product->name}.");
                    }
                }

                if ($presentation) {
                    // Presentation line — see WorkbenchController::store for contract details.
                    $unitPrice = (float) $presentation->price;
                    $productName = $product->name.' - '.$presentation->name;
                    $unitTypeToPersist = 'unit';
                    $quantityUnit = 'unit';
                    $saleModeAtSale = 'presentation';
                    $presentationSnapshot = [
                        'id' => $presentation->id,
                        'name' => $presentation->name,
                        'content' => (float) $presentation->content,
                        'unit' => $presentation->unit,
                        'price' => (float) $presentation->price,
                    ];
                } else {
                    $unitPrice = (float) $product->price;
                    $productName = $product->name;
                    $unitTypeToPersist = $product->unit_type;
                    $quantityUnit = $product->unit_type;
                    $saleModeAtSale = ($product->sale_mode === 'weight' || $product->sale_mode === 'both')
                        ? 'weight'
                        : 'piece';
                    $presentationSnapshot = null;
                }

                $subtotal = round($quantity * $unitPrice, 2);
                $total += $subtotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'presentation_id' => $presentation?->id,
                    'product_name' => $productName,
                    'unit_type' => $unitTypeToPersist,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'original_unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'presentation_snapshot' => $presentationSnapshot,
                    'sale_mode_at_sale' => $saleModeAtSale,
                    'quantity_unit' => $quantityUnit,
                ];
            }

            $sale = Sale::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'folio' => $folio,
                'payment_method' => $request->payment_method,
                'total' => round($total, 2),
                'origin' => 'api',
                'origin_name' => $request->input('origin_name', 'Bascula'),
                'client_reference' => $clientReference,
                // Nombre libre puesto desde la báscula para identificar la venta
                // en la cola. NO es un cliente: `customer_id` sigue en null.
                'contact_name' => trim((string) $request->input('contact_name')) ?: null,
                'amount_paid' => 0,
                'amount_pending' => round($total, 2),
                'status' => SaleStatus::Active,
            ]);

            foreach ($itemsData as $data) {
                $sale->items()->create($data);
            }

            return $sale;
        });

        $sale->load('items');

        SafeBroadcast::dispatch(
            fn () => NewExternalSale::dispatch($sale),
            'NewExternalSale',
            ['sale_id' => $sale->id],
        );

        return response()->json(
            SaleResource::make($sale),
            201
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $sales = Sale::withoutGlobalScopes()
            ->where('branch_id', $request->branch_id)
            ->with('items')
            ->when($request->date, fn ($q, $d) => $q->whereDate('created_at', $d))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20);

        return SaleResource::collection($sales);
    }

    public function show(Request $request, int $sale): SaleResource
    {
        $sale = Sale::withoutGlobalScopes()
            ->where('branch_id', $request->branch_id)
            ->with('items')
            ->findOrFail($sale);

        return SaleResource::make($sale);
    }
}
