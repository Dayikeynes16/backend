<?php

namespace App\Observers;

use App\Models\Branch;

class BranchObserver
{
    public function saving(Branch $branch): void
    {
        $tiers = $branch->delivery_tiers;

        if (is_array($tiers) && count($tiers) > 0) {
            $maxKm = collect($tiers)
                ->pluck('max_km')
                ->filter(fn ($v) => is_numeric($v))
                ->max();

            $branch->max_delivery_km = $maxKm !== null ? (float) $maxKm : null;
        } elseif (empty($tiers)) {
            $branch->max_delivery_km = null;
        }
    }
}
