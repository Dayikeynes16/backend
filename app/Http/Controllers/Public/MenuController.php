<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function show(Request $request, int $branch): JsonResponse
    {
        $tenant = app('tenant');

        $branchModel = Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('online_ordering_enabled', true)
            ->findOrFail($branch);

        $categories = Category::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branchModel->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branchModel->id)
            ->where('status', 'active')
            ->where('visible_online', true)
            ->whereNull('deleted_at')
            ->with(['presentations' => fn ($q) => $q->where('status', 'active')->orderBy('sort_order')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'branch' => [
                'id' => $branchModel->id,
                'name' => $branchModel->name,
                'address' => $branchModel->address,
                'schedule' => $branchModel->schedule,
                'pickup_enabled' => (bool) $branchModel->pickup_enabled,
                'delivery_enabled' => (bool) $branchModel->delivery_enabled,
                'min_order_amount' => $branchModel->min_order_amount !== null ? (float) $branchModel->min_order_amount : null,
                'payment_methods' => $branchModel->payment_methods_enabled ?? ['cash', 'card', 'transfer'],
                'is_open' => $this->isOpenNow($branchModel->hours),
            ],
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
            ]),
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'category_id' => $p->category_id,
                'name' => $p->name,
                'description' => $p->description,
                'image_url' => $p->image_url,
                'price' => (float) $p->price,
                'unit_type' => $p->unit_type,
                'sale_mode' => $p->sale_mode,
                'presentations' => $p->presentations->map(fn ($pr) => [
                    'id' => $pr->id,
                    'name' => $pr->name,
                    'price' => (float) $pr->price,
                    'weight' => $pr->weight !== null ? (float) $pr->weight : null,
                ])->values(),
            ])->values(),
        ]);
    }

    private function isOpenNow(?array $hours): bool
    {
        if ($hours === null) {
            return true;
        }

        $dayKey = strtolower(now()->format('D')); // mon, tue, wed...
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
