<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement(
                    "ALTER TABLE `users` MODIFY `role` "
                    . "ENUM('administrator','staff','voter_editor','municipal_staff','viewer') "
                    . "NOT NULL DEFAULT 'staff'"
                );
            }
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->updateOrInsert(
                ['code' => 'bow.edit_geo'],
                ['label' => 'Edit and Archive Existing Geo and Voter Records']
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            DB::table('users')
                ->where('role', 'voter_editor')
                ->update(['role' => 'viewer']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement(
                    "ALTER TABLE `users` MODIFY `role` "
                    . "ENUM('administrator','staff','municipal_staff','viewer') "
                    . "NOT NULL DEFAULT 'staff'"
                );
            }
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where('code', 'bow.edit_geo')
                ->delete();
        }
    }
};
