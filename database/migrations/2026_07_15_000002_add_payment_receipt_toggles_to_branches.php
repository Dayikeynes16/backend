<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('payment_receipts_enabled')->default(false)->after('branch_admin_expense_categories_enabled');
            $table->boolean('payment_receipts_required')->default(false)->after('payment_receipts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['payment_receipts_enabled', 'payment_receipts_required']);
        });
    }
};
