<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the old customers_phone_branch_id_unique index with a tenant-scoped
 * partial unique index. Assumes DedupCustomers command has been run to remove
 * any existing duplicates — otherwise this migration will fail.
 *
 * Run first: php artisan customers:dedup --dry-run=false
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_phone_branch_id_unique');
        DB::statement('CREATE UNIQUE INDEX customers_tenant_branch_phone_uniq ON customers (tenant_id, branch_id, phone) WHERE phone IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customers_tenant_branch_phone_uniq');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_phone_branch_id_unique UNIQUE (phone, branch_id)');
    }
};
