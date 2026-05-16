<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega trazabilidad por item de venta:
     *  - created_by / updated_by / deleted_by (FK users)
     *  - deleted_at (soft delete) — items eliminados quedan en BD para
     *    conservar referencia desde sale_item_changes.
     *
     * Backfill: created_by se rellena con sales.user_id del padre para los
     * items existentes — refleja la realidad de que el cajero/admin que
     * creó la venta fue quien metió esos items.
     */
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('notes')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->after('updated_by')
                ->constrained('users')->nullOnDelete();
            $table->softDeletes()->after('deleted_by');
        });

        // Backfill: items previos heredan el autor de la venta.
        DB::statement('
            UPDATE sale_items si
            SET created_by = s.user_id
            FROM sales s
            WHERE si.sale_id = s.id AND si.created_by IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['created_by', 'updated_by', 'deleted_by']);
            $table->dropSoftDeletes();
        });
    }
};
