<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('client_reference', 64)->nullable()->after('amount');
        });

        // Único parcial: solo cuando client_reference no es null. Garantiza
        // idempotencia por (sale_id, client_reference) sin afectar pagos
        // existentes (web Inertia) que lo dejan null.
        DB::statement(
            'CREATE UNIQUE INDEX payments_sale_client_reference_unique '
            .'ON payments (sale_id, client_reference) '
            .'WHERE client_reference IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_sale_client_reference_unique');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('client_reference');
        });
    }
};
