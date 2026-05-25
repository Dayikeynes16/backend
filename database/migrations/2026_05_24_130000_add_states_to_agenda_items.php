<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_items', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');
            $table->timestamp('reminder_seen_at')->nullable()->after('remind_at');
            $table->index(['tenant_id', 'completed_at']);
            $table->index(['tenant_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agenda_items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'completed_at']);
            $table->dropIndex(['tenant_id', 'cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancel_reason', 'reminder_seen_at']);
        });
    }
};
