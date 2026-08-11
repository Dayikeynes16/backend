<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Llave de idempotencia de las ventas creadas por la API de básculas.
 *
 * El hub ya la envía en cada venta y el backend la ignoraba, así que un reintento
 * tras una respuesta perdida creaba una segunda venta idéntica. Es nullable porque
 * las básculas que no la mandan tienen que seguir vendiendo igual.
 *
 * El índice es único **por sucursal**: la llave la genera cada equipo, y dos
 * sucursales distintas no comparten espacio de nombres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('origin_name');
            $table->unique(['branch_id', 'client_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'client_reference']);
            $table->dropColumn('client_reference');
        });
    }
};
