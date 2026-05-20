<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\User;
use App\Observers\BranchObserver;
use App\Policies\UserPolicy;
use App\Services\Ai\Assistant\ToolRegistry;
use App\Services\Ai\Assistant\Tools\AccountsPayableTool;
use App\Services\Ai\Assistant\Tools\CustomerStatsTool;
use App\Services\Ai\Assistant\Tools\ExpenseSummaryTool;
use App\Services\Ai\Assistant\Tools\ProductDetailsTool;
use App\Services\Ai\Assistant\Tools\PurchaseSummaryTool;
use App\Services\Ai\Assistant\Tools\SalesSummaryTool;
use App\Services\Ai\Assistant\Tools\ShiftStatusTool;
use App\Services\Ai\Assistant\Tools\TopProductsTool;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class, fn ($app) => new ToolRegistry([
            $app->make(SalesSummaryTool::class),
            $app->make(ExpenseSummaryTool::class),
            $app->make(TopProductsTool::class),
            $app->make(ShiftStatusTool::class),
            $app->make(CustomerStatsTool::class),
            $app->make(ProductDetailsTool::class),
            $app->make(PurchaseSummaryTool::class),
            $app->make(AccountsPayableTool::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::policy(User::class, UserPolicy::class);

        Branch::observe(BranchObserver::class);
    }
}
