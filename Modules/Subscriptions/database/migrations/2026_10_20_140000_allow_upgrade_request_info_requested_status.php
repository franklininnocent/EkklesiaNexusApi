<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE subscription_upgrade_requests DROP CONSTRAINT IF EXISTS subscription_upgrade_requests_status_chk');
        DB::statement("ALTER TABLE subscription_upgrade_requests ADD CONSTRAINT subscription_upgrade_requests_status_chk CHECK (status IN ('PENDING','INFO_REQUESTED','APPROVED','REJECTED','CANCELLED'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE subscription_upgrade_requests DROP CONSTRAINT IF EXISTS subscription_upgrade_requests_status_chk');
        DB::statement("ALTER TABLE subscription_upgrade_requests ADD CONSTRAINT subscription_upgrade_requests_status_chk CHECK (status IN ('PENDING','APPROVED','REJECTED','CANCELLED'))");
    }
};
