<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categorías de productos de compra: catálogo tenant-wide administrable
     * (reemplaza al enum fijo). Se siembran las 5 categorías estándar por
     * tenant existente para preservar las asignaciones previas.
     */
    public function up(): void
    {
        Schema::create('purchase_product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 12)->default('active'); // active|inactive
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'purchase_product_categories_tenant_name_unique');
            $table->index(['tenant_id', 'status']);
        });

        $defaults = ['Res', 'Cerdo', 'Pollo', 'Insumos', 'Otro'];
        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $rows = array_map(fn (string $name) => [
                'tenant_id' => $tenantId,
                'name' => $name,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ], $defaults);

            DB::table('purchase_product_categories')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_product_categories');
    }
};
