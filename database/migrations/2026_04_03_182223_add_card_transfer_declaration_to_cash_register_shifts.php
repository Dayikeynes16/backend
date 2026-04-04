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
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->decimal('declared_card', 12, 2)->nullable()->after('declared_amount');
            $table->decimal('declared_transfer', 12, 2)->nullable()->after('declared_card');
            $table->decimal('difference_card', 12, 2)->default(0)->after('difference');
            $table->decimal('difference_transfer', 12, 2)->default(0)->after('difference_card');
            $table->text('notes')->nullable()->after('difference_transfer');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn(['declared_card', 'declared_transfer', 'difference_card', 'difference_transfer', 'notes']);
        });
    }
};
