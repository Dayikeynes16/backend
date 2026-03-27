<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('max_users')->default(5)->after('max_branches');
            $table->unsignedInteger('max_sales_per_branch_month')->default(500)->after('max_users');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['max_users', 'max_sales_per_branch_month']);
        });
    }
};
