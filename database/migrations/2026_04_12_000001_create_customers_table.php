<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20);
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['phone', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
