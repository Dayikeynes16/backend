<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite al cajero vender desde el hub con la báscula USB conectada.
 *
 * Default `false`, al revés que los otros `cashier_*`: aquéllos nacieron en
 * `true` para no quitarle al cajero capacidades que ya tenía, y ésta es nueva
 * — que cada empresa la encienda donde quiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('cashier_scale_sales_enabled')->default(false)->after('cashier_customers_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('cashier_scale_sales_enabled');
        });
    }
};
