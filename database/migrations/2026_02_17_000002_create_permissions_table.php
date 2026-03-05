<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('code', 100)->unique();
                $table->string('label', 150);
            });
        }

        $defaultPermissions = [
            ['code' => 'bow.manage_geo', 'label' => 'Manage Barangay, Purok, Precinct, and Voters'],
            ['code' => 'bow.view_geo', 'label' => 'View Barangay, Purok, Precinct, and Voters'],
        ];

        DB::table('permissions')->upsert($defaultPermissions, ['code'], ['label']);
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->whereIn('code', [
                    'bow.manage_geo',
                    'bow.view_geo',
                ])
                ->delete();
        }
    }
};
