<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('folio');
            $table->string('payment_method'); // cash, card, transfer
            $table->decimal('total', 12, 2)->default(0);
            $table->string('origin')->default('api');
            $table->string('status')->default('pending'); // pending, completed, cancelled
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'folio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
