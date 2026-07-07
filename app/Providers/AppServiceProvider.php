<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\User;
use App\Observers\BranchObserver;
use App\Policies\UserPolicy;
use App\Services\Ai\Assistant\Drafts\Confirmers\ExpenseCategoryDraftConfirmer;
use App\Services\Ai\Assistant\Drafts\Confirmers\ExpenseCategoryEditDraftConfirmer;
use App\Services\Ai\Assistant\Drafts\Confirmers\ExpenseDraftConfirmer;
use App\Services\Ai\Assistant\Drafts\Confirmers\PayablePaymentDraftConfirmer;
use App\Services\Ai\Assistant\Drafts\Confirmers\ProviderDraftConfirmer;
use App\Services\Ai\Assistant\Drafts\Confirmers\PurchaseDraftConfirmer;
use App\Services\Ai\Assistant\Drafts\DraftConfirmerRegistry;
use App\Services\Ai\Assistant\ToolRegistry;
use App\Services\Ai\Assistant\Tools\AccountsPayableTool;
use App\Services\Ai\Assistant\Tools\CustomerStatsTool;
use App\Services\Ai\Assistant\Tools\ExpenseCategoriesTool;
use App\Services\Ai\Assistant\Tools\ExpenseSummaryTool;
use App\Services\Ai\Assistant\Tools\PrepareCustomerPaymentDraftTool;
use App\Services\Ai\Assistant\Tools\PrepareExpenseCategoryDraftTool;
use App\Services\Ai\Assistant\Tools\PrepareExpenseCategoryEditDraftTool;
use App\Services\Ai\Assistant\Tools\PrepareExpenseDraftTool;
use App\Services\Ai\Assistant\Tools\PreparePayablePaymentDraftTool;
use App\Services\Ai\Assistant\Tools\PrepareProviderDraftTool;
use App\Services\Ai\Assistant\Tools\PreparePurchaseDraftTool;
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
            $app->make(ExpenseCategoriesTool::class),
            $app->make(TopProductsTool::class),
            $app->make(ShiftStatusTool::class),
            $app->make(CustomerStatsTool::class),
            $app->make(ProductDetailsTool::class),
            $app->make(PurchaseSummaryTool::class),
            $app->make(AccountsPayableTool::class),
            // Tools de escritura (preparan borrador; NUNCA confirman).
            $app->make(PrepareExpenseDraftTool::class),
            $app->make(PrepareProviderDraftTool::class),
            $app->make(PreparePurchaseDraftTool::class),
            $app->make(PreparePayablePaymentDraftTool::class),
            $app->make(PrepareCustomerPaymentDraftTool::class),
            $app->make(PrepareExpenseCategoryDraftTool::class),
            $app->make(PrepareExpenseCategoryEditDraftTool::class),
        ]));

        // Confirmadores de borradores por tipo. Whitelist explícita.
        $this->app->singleton(DraftConfirmerRegistry::class, fn ($app) => new DraftConfirmerRegistry([
            $app->make(ExpenseDraftConfirmer::class),
            $app->make(ProviderDraftConfirmer::class),
            $app->make(PurchaseDraftConfirmer::class),
            $app->make(PayablePaymentDraftConfirmer::class),
            $app->make(ExpenseCategoryDraftConfirmer::class),
            $app->make(ExpenseCategoryEditDraftConfirmer::class),
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
