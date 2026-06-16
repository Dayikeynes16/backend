<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureBranchFeature;
use App\Http\Middleware\EnsureHubRole;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolvePublicTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ForcePasswordChange::class,
        ]);

        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'resolve.public.tenant' => ResolvePublicTenant::class,
            'ensure.tenant' => EnsureUserBelongsToTenant::class,
            'branch.feature' => EnsureBranchFeature::class,
            'auth.apikey' => AuthenticateApiKey::class,
            'hub.role' => EnsureHubRole::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        // Ensure resolve.tenant runs BEFORE SubstituteBindings so that
        // TenantScope is active when route model binding resolves models.
        // NOTE: Do NOT include EnsureUserBelongsToTenant here — it must
        //       stay after 'auth' in the route-level middleware stack.
        $middleware->priority([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            ResolveTenant::class,
            ResolvePublicTenant::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
