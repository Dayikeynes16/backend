<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('customer_payment_id')
                ->nullable()
                ->after('sale_id')
                ->constrained('customer_payments')
                ->nullOnDelete();
            $table->index('customer_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['customer_payment_id']);
            $table->dropIndex(['customer_payment_id']);
            $table->dropColumn('customer_payment_id');
        });
    }
};
