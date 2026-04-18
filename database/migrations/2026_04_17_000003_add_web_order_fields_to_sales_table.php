<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('delivery_type', 10)->nullable()->after('origin_name');
            $table->text('delivery_address')->nullable()->after('delivery_type');
            $table->decimal('delivery_lat', 10, 7)->nullable()->after('delivery_address');
            $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
            $table->decimal('delivery_distance_km', 6, 3)->nullable()->after('delivery_lng');
            $table->decimal('delivery_fee', 10, 2)->nullable()->after('delivery_distance_km');
            $table->string('contact_name', 255)->nullable()->after('delivery_fee');
            $table->string('contact_phone', 20)->nullable()->after('contact_name');
            $table->text('cart_note')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_type',
                'delivery_address',
                'delivery_lat',
                'delivery_lng',
                'delivery_distance_km',
                'delivery_fee',
                'contact_name',
                'contact_phone',
                'cart_note',
            ]);
        });
    }
};
