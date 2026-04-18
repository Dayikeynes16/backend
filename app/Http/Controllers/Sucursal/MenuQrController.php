<?php

namespace App\Http\Controllers\Sucursal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class MenuQrController extends Controller
{
    public function show(): Response
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail(Auth::user()->branch_id);
        $tenant = app('tenant');

        $menuPath = "/menu/{$tenant->slug}/s/{$branch->id}";
        $menuUrl = url($menuPath);

        return Inertia::render('Sucursal/MenuQr', [
            'tenant' => $tenant,
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'online_ordering_enabled' => (bool) $branch->online_ordering_enabled,
                'delivery_enabled' => (bool) $branch->delivery_enabled,
                'pickup_enabled' => (bool) $branch->pickup_enabled,
            ],
            'menu_url' => $menuUrl,
            'menu_path' => $menuPath,
        ]);
    }
}
