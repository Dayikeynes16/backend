<?php

use App\Services\Agenda\AgendaReminderNotifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // `agenda:dispatch-reminders` corre cada minuto. Índice parcial sobre lo
        // único que busca —recordatorios vivos aún sin avisar—, así la consulta
        // sigue costando nada aunque la agenda acumule años de tareas hechas.
        DB::statement(<<<'SQL'
            CREATE INDEX agenda_items_pending_reminders_idx ON agenda_items (remind_at)
            WHERE reminder_notified_at IS NULL AND reminder_seen_at IS NULL
              AND completed_at IS NULL AND cancelled_at IS NULL AND deleted_at IS NULL
              AND remind_at IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS agenda_items_pending_reminders_idx');
        Schema::table('agenda_items', fn (Blueprint $table) => $table->dropColumn('reminder_notified_at'));
    }
};
