<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Permiten al admin-empresa habilitar/deshabilitar que el cajero
            // registre gastos y compras en efectivo. Default true para no
            // alterar el comportamiento existente (el cajero ya podía hacerlo).
            $table->boolean('cashier_expenses_enabled')->default(true)->after('pickup_enabled');
            $table->boolean('cashier_purchases_enabled')->default(true)->after('cashier_expenses_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['cashier_expenses_enabled', 'cashier_purchases_enabled']);
        });
    }
};
