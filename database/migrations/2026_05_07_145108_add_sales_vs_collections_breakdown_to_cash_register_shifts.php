<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa "ventas generadas" de "cobranza recibida" en el cierre de turno.
 *
 * Antes: la columna `total_sales` mezclaba pagos de ventas del día con abonos a
 * deudas viejas — etiquetada erróneamente como "vendido". Cuando un cliente
 * abonaba $30k a una cuenta vieja, el cierre lo reportaba como $30k vendidos.
 *
 * Ahora:
 *   - sales_generated_amount/_count   → ventas creadas durante el turno (real)
 *   - collections_from_today_amount   → payments del turno cobrando ventas del turno
 *   - collections_from_previous_amount→ payments del turno cobrando ventas anteriores
 *
 * `total_sales` se mantiene como columna legacy: equivale a la suma de los dos
 * collections (cobranza total del turno = lo que entró al cajón). Se conserva
 * para no romper código histórico que la consume; en la UI se renombra a
 * "cobrado en turno".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->decimal('sales_generated_amount', 10, 2)->nullable()->after('total_sales');
            $table->unsignedInteger('sales_generated_count')->nullable()->after('sales_generated_amount');
            $table->decimal('collections_from_today_amount', 10, 2)->nullable()->after('sales_generated_count');
            $table->decimal('collections_from_previous_amount', 10, 2)->nullable()->after('collections_from_today_amount');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'sales_generated_amount',
                'sales_generated_count',
                'collections_from_today_amount',
                'collections_from_previous_amount',
            ]);
        });
    }
};
