<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F3: "qué incluye" y "qué NO incluye" cada categoría/subcategoría.
        // Mejora la clasificación automática de gastos (F1 de captura): el
        // clasificador ve explícitamente "esta categoría NO incluye sueldos".
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->json('includes')->nullable()->after('aliases');
            $table->json('excludes')->nullable()->after('includes');
        });

        Schema::table('expense_subcategories', function (Blueprint $table) {
            $table->json('includes')->nullable()->after('aliases');
            $table->json('excludes')->nullable()->after('includes');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn(['includes', 'excludes']);
        });

        Schema::table('expense_subcategories', function (Blueprint $table) {
            $table->dropColumn(['includes', 'excludes']);
        });
    }
};
