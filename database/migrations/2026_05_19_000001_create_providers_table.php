<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('contact_name', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('rfc', 20)->nullable();
            $table->string('address', 500)->nullable();

            // type: ganadero | mayorista_carne | insumos | servicios | otro
            $table->string('type', 30);

            // Crédito default que da el proveedor (informativo).
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            $table->text('notes')->nullable();
            $table->string('status', 12)->default('active'); // active|inactive

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Nombre único por tenant (ignora soft-deleted).
            $table->unique(['tenant_id', 'name'], 'providers_tenant_name_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
