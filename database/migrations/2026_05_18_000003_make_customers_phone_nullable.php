<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns customers.phone with the partial unique index introduced in
 * 2026_04_17_000005_replace_customers_phone_unique_index (WHERE phone IS NOT NULL),
 * which already assumes the column may be null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
