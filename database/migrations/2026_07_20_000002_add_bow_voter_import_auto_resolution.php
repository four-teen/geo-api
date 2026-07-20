<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bow_tbl_voter_import_rows')) {
            Schema::table('bow_tbl_voter_import_rows', function (Blueprint $table) {
                if (!Schema::hasColumn('bow_tbl_voter_import_rows', 'location_key')) {
                    $table->string('location_key', 255)->nullable()->after('normalized_address');
                }
                if (!Schema::hasColumn('bow_tbl_voter_import_rows', 'match_strategy')) {
                    $table->string('match_strategy', 30)->nullable()->after('location_resolution');
                }
                if (!Schema::hasColumn('bow_tbl_voter_import_rows', 'match_score')) {
                    $table->decimal('match_score', 5, 2)->nullable()->after('match_strategy');
                }
            });
        }

        if (Schema::hasTable('bow_tbl_puroks')) {
            Schema::table('bow_tbl_puroks', function (Blueprint $table) {
                if (!Schema::hasColumn('bow_tbl_puroks', 'created_from_import_id')) {
                    $table->unsignedBigInteger('created_from_import_id')->nullable();
                    $table->index('created_from_import_id', 'idx_puroks_created_from_import');
                }
            });
        }

        if (
            Schema::hasTable('bow_tbl_recipients')
            && Schema::hasColumn('bow_tbl_recipients', 'import_id')
            && Schema::hasColumn('bow_tbl_recipients', 'source_record_no')
        ) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $table->unique(['import_id', 'source_record_no'], 'uq_recipients_import_source_no');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bow_tbl_recipients')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $table->dropUnique('uq_recipients_import_source_no');
            });
        }

        if (Schema::hasTable('bow_tbl_puroks') && Schema::hasColumn('bow_tbl_puroks', 'created_from_import_id')) {
            Schema::table('bow_tbl_puroks', function (Blueprint $table) {
                $table->dropIndex('idx_puroks_created_from_import');
                $table->dropColumn('created_from_import_id');
            });
        }

        if (Schema::hasTable('bow_tbl_voter_import_rows')) {
            Schema::table('bow_tbl_voter_import_rows', function (Blueprint $table) {
                foreach (['location_key', 'match_strategy', 'match_score'] as $column) {
                    if (Schema::hasColumn('bow_tbl_voter_import_rows', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
