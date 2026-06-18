<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite al admin-empresa delegar al admin-sucursal la gestión del
     * catálogo tenant-wide de productos de compra. Default false: la empresa
     * concede el permiso de forma explícita. El catálogo sigue siendo
     * compartido; el toggle abre lectura+escritura al admin-sucursal.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('branch_admin_purchase_products_enabled')->default(false)->after('branch_admin_expense_categories_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('branch_admin_purchase_products_enabled');
        });
    }
};
