<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca los clientes creados automáticamente desde una venta, que aún no
 * tienen nombre real (su `name` es un placeholder derivado del teléfono).
 *
 * `name` se mantiene NOT NULL a propósito: hay ~10 puntos en el frontend que
 * hacen `c.name.toLowerCase()` y reventarían con null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('name_pending')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('name_pending');
        });
    }
};
