<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_payments', function (Blueprint $table) {
            $table->foreignId('cash_register_shift_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('cash_register_shifts')
                ->nullOnDelete();
            $table->index('cash_register_shift_id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('cash_register_shift_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('cash_register_shifts')
                ->nullOnDelete();
            $table->index('cash_register_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('provider_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_shift_id');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_register_shift_id');
        });
    }
};
