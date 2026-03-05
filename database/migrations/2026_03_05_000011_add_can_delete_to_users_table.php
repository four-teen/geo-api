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
            if (!Schema::hasColumn('users', 'can_delete')) {
                $table->boolean('can_delete')->default(false)->after('is_active');
            }
        });

        if (Schema::hasColumn('users', 'role') && Schema::hasColumn('users', 'can_delete')) {
            DB::table('users')
                ->where('role', 'administrator')
                ->update(['can_delete' => 1]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'can_delete')) {
                $table->dropColumn('can_delete');
            }
        });
    }
};
