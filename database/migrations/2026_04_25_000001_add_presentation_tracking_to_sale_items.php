<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of presentation/weight semantics fix.
 *
 * Adds tracking columns to sale_items so a line of sale can:
 * - Reference the presentation that was sold (presentation_id, FK).
 * - Preserve a frozen snapshot of the presentation at sale time
 *   (presentation_snapshot, jsonb) — survives later edits/deletes of the
 *   catalog presentation.
 * - State explicitly which sale mode produced the line
 *   (sale_mode_at_sale: 'weight' | 'presentation').
 * - State the unit of `quantity` unambiguously (quantity_unit:
 *   'kg' | 'g' | 'piece' | 'unit'), independent of the legacy unit_type.
 *
 * All columns are nullable. Existing rows keep working with the legacy
 * contract; renders use a fallback helper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('presentation_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_presentations')
                ->nullOnDelete();
            $table->jsonb('presentation_snapshot')->nullable()->after('presentation_id');
            $table->string('sale_mode_at_sale', 20)->nullable()->after('presentation_snapshot');
            $table->string('quantity_unit', 10)->nullable()->after('sale_mode_at_sale');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropForeign(['presentation_id']);
            $table->dropColumn([
                'presentation_id',
                'presentation_snapshot',
                'sale_mode_at_sale',
                'quantity_unit',
            ]);
        });
    }
};
