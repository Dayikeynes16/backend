<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_subcategory_id')->constrained('expense_subcategories')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('concept', 160);
            $table->decimal('amount', 12, 2);
            $table->timestamp('expense_at');
            $table->text('description')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'expense_at']);
            $table->index(['branch_id', 'expense_at']);
            $table->index('expense_subcategory_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
