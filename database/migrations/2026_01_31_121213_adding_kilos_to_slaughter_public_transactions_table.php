<?php

/**
 * ============================================================
 * MIGRATION: Add kilos columns to public slaughter transactions
 * ------------------------------------------------------------
 * Table  : tbl_slaughter_public_transactions
 * Purpose:
 * - Store kilos separately for small and large animals
 * - Align database with App 2 (Public_Slaughter UI)
 *
 * Rules:
 * - NO guessing
 * - Explicit columns
 * - Non-destructive migration
 * ============================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tbl_slaughter_public_transactions', function (Blueprint $table) {

            // 🔹 Small animals kilos
            $table->decimal('small_kilos', 10, 2)
                  ->default(0)
                  ->after('hog_heads');

            // 🔹 Large animals kilos
            $table->decimal('large_kilos', 10, 2)
                  ->default(0)
                  ->after('carabao_heads');

        });
    }

    public function down(): void
    {
        Schema::table('tbl_slaughter_public_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'small_kilos',
                'large_kilos',
            ]);
        });
    }
};
