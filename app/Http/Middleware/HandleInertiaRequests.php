<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenant = $user?->tenant;
        $branch = $user?->branch;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'role' => $user?->getRoleNames()->first(),
                'tenant_slug' => $tenant?->slug,
                'tenant' => $tenant ? [
                    'name' => $tenant->name,
                    'logo_url' => $tenant->logo_url,
                ] : null,
                'branch' => $branch ? [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'cashier_expenses_enabled' => (bool) $branch->cashier_expenses_enabled,
                    'cashier_purchases_enabled' => (bool) $branch->cashier_purchases_enabled,
                    'ticket_width' => data_get($branch->ticket_config, 'width', '80mm'),
                ] : null,
            ],
            'features' => [
                'webOrders' => (bool) config('features.web_orders'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
