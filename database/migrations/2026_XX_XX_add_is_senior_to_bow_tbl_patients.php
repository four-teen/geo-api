<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – ADD SENIOR CITIZEN FLAG TO PATIENTS
 * ----------------------------------------------------------------------------
 * Table  : bow_tbl_patients
 * Column : is_senior (manual flag, NOT computed)
 *
 * Notes:
 * - Mirrors is_pwd behavior
 * - Default = 0 (NO)
 * - Backward compatible
 * ============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('bow_tbl_patients', function (Blueprint $table) {
            $table
                ->tinyInteger('is_senior')
                ->default(0)
                ->after('is_pwd');
        });
    }

    public function down()
    {
        Schema::table('bow_tbl_patients', function (Blueprint $table) {
            $table->dropColumn('is_senior');
        });
    }
};
