<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('cancel_reason')->nullable()->after('cancelled_by');
            $table->timestamp('cancel_requested_at')->nullable()->after('cancel_reason');
            $table->foreignId('cancel_requested_by')->nullable()->after('cancel_requested_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_request_reason')->nullable()->after('cancel_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['cancel_reason', 'cancel_requested_at', 'cancel_request_reason']);
            $table->dropConstrainedForeignId('cancel_requested_by');
        });
    }
};
