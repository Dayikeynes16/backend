<?php

use App\Services\Agenda\AgendaReminderNotifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se avisó un recordatorio en la isla. Evita avisarlo dos veces;
 * posponer o editar `remind_at` lo limpia para volver a avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_items', function (Blueprint $table) {
            $table->timestamp('reminder_notified_at')->nullable()->after('reminder_seen_at');
        });

        // Al desplegar no se anuncian recordatorios de hace días: sólo los de
        // las últimas 24 h que aún nadie vio (lo que la campana de agenda
        // mostraba). La lógica vive en el servicio para poder probarla.
        AgendaReminderNotifier::backfillOld();
    }

    public function down(): void
    {
        Schema::table('agenda_items', fn (Blueprint $table) => $table->dropColumn('reminder_notified_at'));
    }
};
