<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Llave de idempotencia del turno, y la invariante que faltaba en la base.
 *
 * El hub necesita poder reintentar la apertura de un turno sin abrir dos: si la
 * respuesta se pierde por un corte de red, el reintento debe devolver el mismo
 * turno. Esa llave la genera el equipo, igual que en las ventas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('opening_amount');
        });

        // "Un turno abierto por usuario" vivía solo en PHP, en tres sitios y sin
        // transacción: dos peticiones simultáneas del hub podían crear dos, y
        // entonces ShiftService::current() —que no ordena— devolvía uno indefinido.
        DB::statement(
            'CREATE UNIQUE INDEX shifts_user_open_unique '
            .'ON cash_register_shifts (user_id) '
            .'WHERE closed_at IS NULL'
        );

        // Idempotencia del hub: un reintento de apertura devuelve el mismo turno
        // en vez de crear otro. Parcial, porque la web abre turnos sin referencia.
        DB::statement(
            'CREATE UNIQUE INDEX shifts_user_client_reference_unique '
            .'ON cash_register_shifts (user_id, client_reference) '
            .'WHERE client_reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shifts_user_open_unique');
        DB::statement('DROP INDEX IF EXISTS shifts_user_client_reference_unique');

        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn('client_reference');
        });
    }
};
