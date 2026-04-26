<?php

namespace App\Http\Controllers\Public;

use App\Enums\SaleStatus;
use App\Events\NewExternalSale;
use App\Exceptions\Public\ClosedBranchException;
use App\Exceptions\Public\OutOfRangeException;
use App\Exceptions\Public\QuoteUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Services\DeliveryFeeService;
use App\Services\PhoneNormalizer;
use App\Services\WhatsappMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function store(
        Request $request,
        int $branch,
        DeliveryFeeService $deliveryService,
        WhatsappMessageService $whatsapp,
    ): JsonResponse {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.presentation_id' => 'nullable|integer',
            'items.*.notes' => 'nullable|string|max:500',
            'delivery_type' => ['required', Rule::in(['pickup', 'delivery'])],
            'delivery_address' => 'nullable|string|max:500',
            'delivery_lat' => 'nullable|numeric|between:-90,90',
            'delivery_lng' => 'nullable|numeric|between:-180,180',
            'contact_name' => 'required|string|max:255',
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-\(\)]{10,20}$/'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'transfer'])],
            'cart_note' => 'nullable|string|max:500',
            'honeypot' => 'nullable|string',
        ]);

        // Honeypot: respond as if success. Silently drop.
        if (! empty($validated['honeypot'])) {
            return response()->json([
                'sale_id' => 0,
                'folio' => 'S-'.str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT),
                'whatsapp_url' => '',
                'total' => 0,
            ], 201);
        }

        $tenant = app('tenant');
        $contactPhone = PhoneNormalizer::normalize($validated['contact_phone']);

        $branchModel = Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->findOrFail($branch);

        if (! $branchModel->online_ordering_enabled) {
            return response()->json(['error' => 'ordering_disabled'], 422);
        }

        if ($validated['delivery_type'] === 'delivery' && ! $branchModel->delivery_enabled) {
            return response()->json(['error' => 'delivery_not_available'], 422);
        }

        if ($validated['delivery_type'] === 'pickup' && ! $branchModel->pickup_enabled) {
            return response()->json(['error' => 'pickup_not_available'], 422);
        }

        if (! $this->isOpenNow($branchModel->hours)) {
            return response()->json(['error' => 'closed'], 422);
        }

        // Soft-block: 3+ cancelled web sales from same phone in 24h
        $recentCancels = Sale::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branchModel->id)
            ->where('contact_phone', $contactPhone)
            ->where('origin', 'web')
            ->where('status', SaleStatus::Cancelled->value)
            ->where('cancelled_at', '>=', now()->subHours(24))
            ->count();

        if ($recentCancels >= 3) {
            return response()->json([
                'error' => 'please_contact_branch',
                'message' => 'Por favor contacta directamente a la sucursal.',
            ], 429);
        }

        // Load products
        $productIds = collect($validated['items'])->pluck('product_id')->unique()->values();

        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branchModel->id)
            ->whereIn('id', $productIds)
            ->where('status', 'active')
            ->where('visible_online', true)
            ->whereNull('deleted_at')
            ->with(['presentations' => fn ($q) => $q->where('status', 'active')])
            ->get()
            ->keyBy('id');

        $missing = $productIds->diff($products->keys());

        if ($missing->isNotEmpty()) {
            return response()->json([
                'error' => 'invalid_products',
                'product_ids' => $missing->values(),
            ], 422);
        }

        // Recalculate subtotals server-side
        $itemsData = [];
        $subtotalSum = 0.0;

        foreach ($validated['items'] as $item) {
            $product = $products[$item['product_id']];
            $qty = (float) $item['quantity'];
            $presentationId = $item['presentation_id'] ?? null;
            $presentation = null;

            if ($presentationId && in_array($product->sale_mode, ['presentation', 'both'], true)) {
                $presentation = $product->presentations->find($presentationId);
                if (! $presentation) {
                    return response()->json([
                        'error' => 'invalid_presentation',
                        'product_id' => $product->id,
                    ], 422);
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

            $lineSubtotal = round($qty * $unitPrice, 2);
            $subtotalSum += $lineSubtotal;

            $itemsData[] = [
                'product_id' => $product->id,
                'presentation_id' => $presentation?->id,
                'product_name' => $productName,
                'unit_type' => $unitTypeToPersist,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'original_unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'notes' => $item['notes'] ?? null,
                'presentation_snapshot' => $presentationSnapshot,
                'sale_mode_at_sale' => $saleModeAtSale,
                'quantity_unit' => $quantityUnit,
            ];
        }

        // Delivery fee
        $deliveryFee = 0.0;
        $deliveryDistanceKm = null;

        if ($validated['delivery_type'] === 'delivery') {
            if (empty($validated['delivery_lat']) || empty($validated['delivery_lng']) || empty($validated['delivery_address'])) {
                return response()->json(['error' => 'missing_delivery_location'], 422);
            }

            try {
                $quote = $deliveryService->quote(
                    $branchModel,
                    (float) $validated['delivery_lat'],
                    (float) $validated['delivery_lng'],
                );
            } catch (OutOfRangeException $e) {
                return response()->json(['error' => 'out_of_range', 'distance_km' => $e->distanceKm], 422);
            } catch (QuoteUnavailableException $e) {
                return response()->json(['error' => 'quote_unavailable'], 503);
            }

            $deliveryFee = $quote['fee'];
            $deliveryDistanceKm = $quote['distance_km'];
        }

        // Min order
        if ($branchModel->min_order_amount !== null && $subtotalSum < (float) $branchModel->min_order_amount) {
            return response()->json([
                'error' => 'below_minimum',
                'min_order_amount' => (float) $branchModel->min_order_amount,
            ], 422);
        }

        $total = round($subtotalSum + $deliveryFee, 2);

        // Customer lookup/create + Sale persistence (atomic)
        $sale = DB::transaction(function () use (
            $tenant, $branchModel, $validated, $contactPhone, $itemsData,
            $subtotalSum, $deliveryFee, $deliveryDistanceKm, $total,
        ) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$branchModel->id]);

            $customer = Customer::firstOrCreate(
                ['branch_id' => $branchModel->id, 'phone' => $contactPhone],
                ['name' => $validated['contact_name']]
            );

            $saleCount = Sale::withoutGlobalScopes()
                ->where('branch_id', $branchModel->id)
                ->count();

            $folio = 'S-'.str_pad((string) ($saleCount + 1), 5, '0', STR_PAD_LEFT);

            $sale = Sale::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branchModel->id,
                'customer_id' => $customer->id,
                'folio' => $folio,
                'origin' => 'web',
                'origin_name' => 'Pedido web',
                'status' => SaleStatus::Pending,
                'payment_method' => $validated['payment_method'],
                'total' => $total,
                'amount_paid' => 0,
                'amount_pending' => $total,
                'contact_name' => $validated['contact_name'],
                'contact_phone' => $contactPhone,
                'delivery_type' => $validated['delivery_type'],
                'delivery_address' => $validated['delivery_address'] ?? null,
                'delivery_lat' => $validated['delivery_lat'] ?? null,
                'delivery_lng' => $validated['delivery_lng'] ?? null,
                'delivery_distance_km' => $deliveryDistanceKm,
                'delivery_fee' => $deliveryFee,
                'cart_note' => $validated['cart_note'] ?? null,
            ]);

            foreach ($itemsData as $data) {
                $sale->items()->create($data);
            }

            return $sale;
        });

        $sale->load('items', 'branch', 'tenant');

        NewExternalSale::dispatch($sale);

        $whatsappUrl = null;
        if (! empty($branchModel->public_phone)) {
            $text = $whatsapp->buildOrderText($sale);
            $whatsappUrl = $whatsapp->buildUrl($branchModel->public_phone, $text);
        }

        return response()->json([
            'sale_id' => $sale->id,
            'folio' => $sale->folio,
            'whatsapp_url' => $whatsappUrl,
            'total' => (float) $sale->total,
        ], 201);
    }

    private function isOpenNow(?array $hours): bool
    {
        if ($hours === null) {
            return true;
        }

        $dayKey = strtolower(now()->format('D'));
        $day = $hours[$dayKey] ?? null;

        if ($day === null) {
            return false;
        }

        $open = $day['open'] ?? null;
        $close = $day['close'] ?? null;

        if (! $open || ! $close) {
            return false;
        }

        $now = now()->format('H:i');

        return $now >= $open && $now <= $close;
    }
}
