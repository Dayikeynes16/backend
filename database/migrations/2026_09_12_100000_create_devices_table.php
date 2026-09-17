<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->string('kind', 32);
            $table->string('name', 100);
            $table->string('display_name', 100)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedTinyInteger('battery_level')->nullable();
            $table->boolean('battery_charging')->nullable();
            $table->string('connection', 16)->nullable();
            $table->string('local_ip', 45)->nullable();
            $table->string('via', 16);
            $table->timestamp('last_seen_at');
            $table->timestamp('first_seen_at');
            $table->timestamp('muted_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedTinyInteger('battery_alert_level')->nullable();
            $table->timestamp('silent_alerted_at')->nullable();
            $table->string('outdated_alert_version', 32)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'device_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
