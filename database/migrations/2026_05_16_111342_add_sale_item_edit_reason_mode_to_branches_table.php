<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configura por sucursal si el admin debe capturar un motivo al
     * agregar/editar items de una venta desde Mesa de Trabajo:
     *   - disabled: no se pide ni se muestra el campo
     *   - optional (default): se muestra pero puede quedar vacío
     *   - required: motivo no vacío obligatorio
     * Eliminar un item exige motivo siempre, sin importar esta config.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('sale_item_edit_reason_mode', 20)
                ->default('optional')
                ->after('payment_methods_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('sale_item_edit_reason_mode');
        });
    }
};
