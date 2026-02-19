<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bow_tbl_prescriptions')) {
            return;
        }

        $hasReleaseStatus = Schema::hasColumn('bow_tbl_prescriptions', 'release_status');
        $hasReleasedAt = Schema::hasColumn('bow_tbl_prescriptions', 'released_at');

        Schema::table('bow_tbl_prescriptions', function (Blueprint $table) use ($hasReleaseStatus, $hasReleasedAt) {
            if (!$hasReleaseStatus) {
                $table->enum('release_status', ['PENDING', 'RELEASED'])
                    ->default('PENDING')
                    ->after('remarks');
            }

            if (!$hasReleasedAt) {
                $table->dateTime('released_at')
                    ->nullable()
                    ->after('date_released');
            }
        });

        DB::table('bow_tbl_prescriptions')
            ->where('release_status', 'PENDING')
            ->whereNotNull('date_released')
            ->update([
                'release_status' => 'RELEASED',
                'released_at' => DB::raw('date_released'),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('bow_tbl_prescriptions')) {
            return;
        }

        $hasReleaseStatus = Schema::hasColumn('bow_tbl_prescriptions', 'release_status');
        $hasReleasedAt = Schema::hasColumn('bow_tbl_prescriptions', 'released_at');

        Schema::table('bow_tbl_prescriptions', function (Blueprint $table) use ($hasReleaseStatus, $hasReleasedAt) {
            if ($hasReleasedAt) {
                $table->dropColumn('released_at');
            }

            if ($hasReleaseStatus) {
                $table->dropColumn('release_status');
            }
        });
    }
};

