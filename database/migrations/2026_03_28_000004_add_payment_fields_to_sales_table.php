<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('origin_name')->nullable()->after('origin');
            $table->decimal('amount_paid', 12, 2)->default(0)->after('total');
            $table->decimal('amount_pending', 12, 2)->default(0)->after('amount_paid');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('payment_method')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['origin_name', 'amount_paid', 'amount_pending', 'cancelled_at']);
            $table->dropConstrainedForeignId('cancelled_by');
        });
    }
};
