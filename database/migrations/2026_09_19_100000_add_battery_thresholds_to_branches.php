<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A qué porcentaje quiere enterarse cada sucursal. 20 y 10 de fábrica: los
 * mismos que estaban escritos en el código, para que nadie note un cambio
 * hasta que decida tocarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedTinyInteger('battery_warn_threshold')->default(20);
            $table->unsignedTinyInteger('battery_critical_threshold')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['battery_warn_threshold', 'battery_critical_threshold']);
        });
    }
};
