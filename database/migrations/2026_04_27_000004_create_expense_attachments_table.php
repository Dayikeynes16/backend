<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            // tenant_id denormalizado: validación de acceso a la descarga
            // sin tener que hacer JOIN al gasto en cada request.
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('original_name', 255);
            $table->string('path', 255);
            $table->string('mime_type', 80);
            $table->unsignedInteger('size_bytes');

            $table->timestamps();

            $table->index('expense_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_attachments');
    }
};
