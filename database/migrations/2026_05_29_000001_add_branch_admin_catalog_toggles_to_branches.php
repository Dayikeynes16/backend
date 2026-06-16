<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permiten al admin-empresa delegar al admin-sucursal la gestión de los
     * catálogos tenant-wide (proveedores y categorías/subcategorías de gastos).
     * Default false: la empresa concede el permiso de forma explícita. El
     * catálogo sigue siendo compartido; el toggle solo abre escritura.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('branch_admin_providers_enabled')->default(false)->after('cashier_purchases_enabled');
            $table->boolean('branch_admin_expense_categories_enabled')->default(false)->after('branch_admin_providers_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['branch_admin_providers_enabled', 'branch_admin_expense_categories_enabled']);
        });
    }
};
