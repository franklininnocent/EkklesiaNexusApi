<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bishops DROP CONSTRAINT IF EXISTS bishops_status_check');
            DB::statement("
                ALTER TABLE bishops
                ADD CONSTRAINT bishops_status_check
                CHECK (status::text = ANY (ARRAY[
                    'active', 'retired', 'emeritus', 'transferred', 'deceased', 'inactive'
                ]::text[]))
            ");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE bishops
                MODIFY status ENUM(
                    'active', 'retired', 'emeritus', 'transferred', 'deceased', 'inactive'
                ) NOT NULL DEFAULT 'active'
            ");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bishops DROP CONSTRAINT IF EXISTS bishops_status_check');
            DB::statement("
                ALTER TABLE bishops
                ADD CONSTRAINT bishops_status_check
                CHECK (status::text = ANY (ARRAY[
                    'active', 'retired', 'emeritus', 'transferred', 'deceased'
                ]::text[]))
            ");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE bishops
                MODIFY status ENUM(
                    'active', 'retired', 'emeritus', 'transferred', 'deceased'
                ) NOT NULL DEFAULT 'active'
            ");
        }
    }
};
