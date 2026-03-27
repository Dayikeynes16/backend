<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->decimal('cost_price', 10, 2)->nullable()->after('price');
            $table->string('visibility')->default('public')->after('status'); // public, restricted
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['cost_price', 'visibility']);
        });
    }
};
