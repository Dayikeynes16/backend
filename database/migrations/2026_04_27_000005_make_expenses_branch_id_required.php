<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make expenses.branch_id required. Backfill any historical NULL rows
     * (gastos "corporativos" del flujo F1) con la primera sucursal disponible
     * del mismo tenant antes de cambiar el constraint.
     */
    public function up(): void
    {
        // Backfill: para cada gasto con branch_id null, asignar la primera
        // sucursal del mismo tenant. Si por alguna razón no hay branches
        // (escenario imposible en producción pero defensivo en testing),
        // dejamos el row para que la siguiente operación falle visiblemente.
        $orphans = DB::table('expenses')->whereNull('branch_id')->get(['id', 'tenant_id']);
        foreach ($orphans as $exp) {
            $branchId = DB::table('branches')
                ->where('tenant_id', $exp->tenant_id)
                ->orderBy('id')
                ->value('id');
            if ($branchId) {
                DB::table('expenses')->where('id', $exp->id)->update(['branch_id' => $branchId]);
            }
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->change();
        });
    }
};
