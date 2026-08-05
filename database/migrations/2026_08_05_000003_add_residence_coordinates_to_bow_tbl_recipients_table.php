<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bow_tbl_recipients')) {
            return;
        }

        if (!Schema::hasColumn('bow_tbl_recipients', 'latitude')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('house_picture');
            });
        }

        if (!Schema::hasColumn('bow_tbl_recipients', 'longitude')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }

        if (!Schema::hasColumn('bow_tbl_recipients', 'location_accuracy_meters')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $table->decimal('location_accuracy_meters', 10, 2)->nullable()->after('longitude');
            });
        }

        if (!Schema::hasColumn('bow_tbl_recipients', 'location_captured_at')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $table->dateTime('location_captured_at')->nullable()->after('location_accuracy_meters');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('bow_tbl_recipients')) {
            return;
        }

        foreach (['location_captured_at', 'location_accuracy_meters', 'longitude', 'latitude'] as $column) {
            if (Schema::hasColumn('bow_tbl_recipients', $column)) {
                Schema::table('bow_tbl_recipients', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
