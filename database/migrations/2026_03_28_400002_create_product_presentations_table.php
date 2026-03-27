<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // "500g", "1kg", "1 pieza", "1/2 litro"
            $table->decimal('content', 10, 3); // 500, 1000, 1
            $table->string('unit'); // g, kg, ml, l, pieza
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_presentations');
    }
};
