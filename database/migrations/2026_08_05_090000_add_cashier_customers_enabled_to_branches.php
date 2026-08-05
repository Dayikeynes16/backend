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
            // Habilita el módulo Clientes del cajero: cartera, alta/edición y
            // cobro global FIFO. NO incluye precios preferenciales, que siguen
            // siendo exclusivos del admin-sucursal.
            //
            // Default true por dos razones: consistencia con los otros toggles
            // del cajero, y porque el asistente IA ya autorizaba al cajero a
            // registrar cobros globales — nacer en false se la quitaría.
            $table->boolean('cashier_customers_enabled')->default(true)->after('cashier_purchases_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('cashier_customers_enabled');
        });
    }
};
