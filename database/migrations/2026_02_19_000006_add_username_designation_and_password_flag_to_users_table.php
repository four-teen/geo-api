<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->after('name');
            }

            if (!Schema::hasColumn('users', 'designation')) {
                $table->string('designation')->nullable()->after('username');
            }

            if (!Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('is_active');
            }
        });

        if (Schema::hasColumn('users', 'username') && Schema::hasColumn('users', 'email')) {
            DB::table('users')
                ->whereNull('username')
                ->update(['username' => DB::raw('email')]);
        }

        if ($this->shouldCreateUsernameUniqueIndex()) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('username', 'users_username_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && $this->usernameUniqueIndexExists()) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_username_unique');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }

            if (Schema::hasColumn('users', 'designation')) {
                $table->dropColumn('designation');
            }

            if (Schema::hasColumn('users', 'username')) {
                $table->dropColumn('username');
            }
        });
    }

    private function shouldCreateUsernameUniqueIndex(): bool
    {
        return Schema::hasColumn('users', 'username') && !$this->usernameUniqueIndexExists();
    }

    private function usernameUniqueIndexExists(): bool
    {
        $rows = DB::select(
            "SHOW INDEX FROM users WHERE Key_name = 'users_username_unique'"
        );

        return !empty($rows);
    }
};

