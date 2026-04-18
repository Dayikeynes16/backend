<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }
        if (Schema::hasColumn('payments', 'deleted_at')) {
            return;
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Intencionalmente vacío: no deshacemos soft deletes una vez garantizados.
    }
};
