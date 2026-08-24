<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contexto del cambio, para la sección Movimientos.
 *
 * `audit_logs` sabía qué se cambió y quién, pero no desde dónde ni cuánto dinero
 * movió. Sin lo primero, un dueño no puede reconocer si un cambio hecho con su
 * cuenta fue suyo; sin lo segundo, la pantalla no puede ordenar por impacto ni
 * sumar el neto del periodo sin traerse todo a memoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // Nullable: los auditables de empresa (gastos sin sucursal) no tienen.
            $table->foreignId('branch_id')->nullable()->after('tenant_id')
                ->constrained()->nullOnDelete();

            // 45 caracteres cubre IPv6 completo.
            $table->string('ip_address', 45)->nullable()->after('user_id');
            $table->string('user_agent', 255)->nullable()->after('ip_address');

            // Negativo = reduce lo que hay que entregar. NULL = evento no
            // monetario (asignar cliente, reabrir): queda fuera del neto a
            // propósito, contarlo daría una pérdida que no ocurrió.
            $table->decimal('amount_effect', 12, 2)->nullable()->after('changes');

            $table->index(['branch_id', 'created_at']);
            $table->index(['auditable_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'created_at']);
            $table->dropIndex(['auditable_type', 'created_at']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['ip_address', 'user_agent', 'amount_effect']);
        });
    }
};
