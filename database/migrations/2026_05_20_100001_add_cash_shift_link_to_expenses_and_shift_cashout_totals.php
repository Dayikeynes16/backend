<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Gasto capturado desde caja queda ligado al turno abierto.
            // nullable: los gastos de admin (empresa/sucursal) no llevan turno.
            $table->foreignId('cash_register_shift_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('cash_register_shifts')
                ->nullOnDelete();
            $table->index('cash_register_shift_id');
        });

        Schema::table('cash_register_shifts', function (Blueprint $table) {
            // Desglose de salidas en efectivo persistido al cerrar (igual que total_cash).
            // total_cash_provider_payments se llena en la Fase 2; aquí queda en 0.
            $table->decimal('total_cash_expenses', 12, 2)->default(0)->after('total_transfer');
            $table->decimal('total_cash_provider_payments', 12, 2)->default(0)->after('total_cash_expenses');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_shift_id');
        });

        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn(['total_cash_expenses', 'total_cash_provider_payments']);
        });
    }
};
