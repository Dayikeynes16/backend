<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La llave deja de ser «de la sucursal» y pasa a poder ser **de un equipo**.
 *
 * Hasta ahora todas las básculas de una sucursal compartían llave, así que
 * perder una tablet obligaba a revocar la de todas y a reconfigurarlas una por
 * una. Con la llave ligada a su equipo se revoca la de esa y las demás siguen
 * vendiendo.
 *
 * Es **nullable** a propósito: las llaves que ya existen no pertenecen a ningún
 * equipo y tienen que seguir funcionando exactamente igual. Ninguna báscula en
 * producción se entera de este cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('device_id')->nullable()->after('branch_id');

            // Una llave activa por equipo y sucursal. Postgres admite varios
            // NULL en un índice único, así que las llaves sueltas de siempre
            // conviven sin estorbarse.
            $table->unique(['branch_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'device_id']);
            $table->dropColumn('device_id');
        });
    }
};
