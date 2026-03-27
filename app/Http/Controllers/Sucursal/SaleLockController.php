<?php

namespace App\Http\Controllers\Sucursal;

use App\Events\SaleLocked;
use App\Events\SaleUnlocked;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SaleLockController extends Controller
{
    public function lock(Sale $sale): JsonResponse
    {
        $user = Auth::user();

        if ($sale->branch_id !== $user->branch_id) {
            abort(403);
        }

        // Check if locked by someone else (and not expired)
        if ($sale->locked_by && $sale->locked_by !== $user->id) {
            if ($sale->locked_at && $sale->locked_at->diffInMinutes(now()) < 5) {
                return response()->json([
                    'locked' => true,
                    'locked_by_name' => $sale->lockedByUser?->name ?? 'Otro usuario',
                ], 409);
            }
        }

        $sale->updateQuietly([
            'locked_by' => $user->id,
            'locked_at' => now(),
        ]);

        SaleLocked::dispatch($sale->id, $sale->branch_id, $user->id, $user->name);

        return response()->json(['ok' => true]);
    }

    public function unlock(Sale $sale): JsonResponse
    {
        $user = Auth::user();

        if ($sale->locked_by === $user->id) {
            $sale->updateQuietly([
                'locked_by' => null,
                'locked_at' => null,
            ]);

            SaleUnlocked::dispatch($sale->id, $sale->branch_id);
        }

        return response()->json(['ok' => true]);
    }

    public function heartbeat(Sale $sale): JsonResponse
    {
        $user = Auth::user();

        if ($sale->locked_by === $user->id) {
            $sale->updateQuietly(['locked_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
