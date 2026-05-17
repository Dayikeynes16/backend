<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Categorías: descripción interna + sinónimos para mejorar la
        // clasificación automática por IA (Fase 0 del flujo "Registrar gasto con IA").
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->json('aliases')->nullable()->after('description');
        });

        Schema::table('expense_subcategories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->json('aliases')->nullable()->after('description');
        });

        // Método de pago opcional en cada gasto. Reutiliza App\Enums\PaymentMethod
        // (cash, card, transfer, credit) ya usado en ventas y branches.
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn(['description', 'aliases']);
        });

        Schema::table('expense_subcategories', function (Blueprint $table) {
            $table->dropColumn(['description', 'aliases']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
