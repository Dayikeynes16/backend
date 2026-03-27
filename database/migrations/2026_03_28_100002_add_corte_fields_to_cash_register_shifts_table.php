<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->decimal('opening_amount', 12, 2)->default(0)->after('opened_at');
            $table->decimal('declared_amount', 12, 2)->nullable()->after('sale_count');
            $table->decimal('expected_amount', 12, 2)->default(0)->after('declared_amount');
            $table->decimal('difference', 12, 2)->default(0)->after('expected_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn(['opening_amount', 'declared_amount', 'expected_amount', 'difference']);
        });
    }
};
