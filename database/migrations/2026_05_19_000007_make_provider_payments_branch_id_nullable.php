<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * branch_id en provider_payments debe ser nullable: un pago "a cuenta"
     * (sin compra ligada) y sin compras pendientes del proveedor puede no
     * tener sucursal específica. El admin-empresa puede registrar saldo
     * a favor del proveedor sin sucursal.
     */
    public function up(): void
    {
        Schema::table('provider_payments', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('provider_payments', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable(false)->change();
        });
    }
};
