<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_releases', function (Blueprint $table) {
            $table->string('kind', 32)->primary();
            $table->string('version', 32);
            // Desde cuándo se conoce ESTA versión: solo cambia cuando cambia `version`.
            // Precisión de microsegundos: si no, una comparación exacta contra el
            // Carbon original en memoria (p. ej. en tests) nunca es igual tras el
            // roundtrip a la base (Laravel trunca a segundos por defecto).
            $table->timestamp('known_since', precision: 6);
            // Última consulta al feed, informativa.
            $table->timestamp('checked_at', precision: 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_releases');
    }
};
