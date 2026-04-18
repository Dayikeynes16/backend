<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('online_ordering_enabled')->default(false)->after('status');
            $table->boolean('delivery_enabled')->default(false)->after('online_ordering_enabled');
            $table->boolean('pickup_enabled')->default(true)->after('delivery_enabled');
            $table->jsonb('delivery_tiers')->nullable()->after('pickup_enabled');
            $table->decimal('max_delivery_km', 6, 3)->nullable()->after('delivery_tiers');
            $table->decimal('min_order_amount', 10, 2)->nullable()->after('max_delivery_km');
            $table->string('public_phone', 20)->nullable()->after('min_order_amount');
            $table->jsonb('hours')->nullable()->after('public_phone');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'online_ordering_enabled',
                'delivery_enabled',
                'pickup_enabled',
                'delivery_tiers',
                'max_delivery_km',
                'min_order_amount',
                'public_phone',
                'hours',
            ]);
        });
    }
};
