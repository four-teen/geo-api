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
            ['code' => 'bow.manage_barangay_purok', 'label' => 'Manage Barangays & Puroks'],
            ['code' => 'bow.manage_medicine', 'label' => 'Manage Medicines'],
            ['code' => 'bow.manage_patients', 'label' => 'Manage Patients'],
            ['code' => 'bow.manage_physicians', 'label' => 'Manage Physicians'],
            ['code' => 'bow.add_prescription', 'label' => 'Add Prescriptions'],
            ['code' => 'bow.monitoring', 'label' => 'Monitoring (Read Only)'],
        ];

        DB::table('permissions')->upsert($defaultPermissions, ['code'], ['label']);
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->whereIn('code', [
                    'bow.manage_barangay_purok',
                    'bow.manage_medicine',
                    'bow.manage_patients',
                    'bow.manage_physicians',
                    'bow.add_prescription',
                    'bow.monitoring',
                ])
                ->delete();
        }
    }
};
