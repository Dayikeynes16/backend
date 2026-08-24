<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permiso del admin-sucursal para ver Movimientos.
 *
 * `default false`, a diferencia del resto de flags de sucursal: Movimientos es un
 * registro de vigilancia sobre quien opera la caja, y la cuenta de admin-sucursal
 * es una de las que puede quedar bajo sospecha. Se enciende a propósito, nunca por
 * omisión. El admin-empresa siempre lo ve, con flag o sin él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('branch_admin_movements_enabled')->default(false)
                ->after('branch_admin_expense_categories_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('branch_admin_movements_enabled');
        });
    }
};
