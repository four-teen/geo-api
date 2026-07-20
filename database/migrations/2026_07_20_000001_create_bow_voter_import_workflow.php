<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureLegacyPrimaryKeyAutoIncrements('bow_tbl_barangays', 'barangay_id');
        $this->ensureLegacyPrimaryKeyAutoIncrements('bow_tbl_puroks', 'purok_id');
        $this->ensureLegacyPrimaryKeyAutoIncrements('bow_tbl_precincts', 'precinct_id');

        if (!Schema::hasTable('bow_tbl_voter_imports')) {
            Schema::create('bow_tbl_voter_imports', function (Blueprint $table) {
                $table->bigIncrements('import_id');
                $table->integer('barangay_id')->nullable()->index('idx_voter_import_barangay');
                $table->string('barangay_name', 150);
                $table->string('province_name', 150)->nullable();
                $table->string('municipality_name', 150)->nullable();
                $table->string('original_filename', 255);
                $table->string('stored_path', 500)->nullable();
                $table->char('file_hash', 64)->index('idx_voter_import_hash');
                $table->string('status', 30)->default('DRAFT')->index('idx_voter_import_status');
                $table->string('mode', 30)->nullable();
                $table->integer('declared_total')->nullable();
                $table->unsignedInteger('parsed_rows')->default(0);
                $table->unsignedInteger('ready_rows')->default(0);
                $table->unsignedInteger('warning_rows')->default(0);
                $table->unsignedInteger('error_rows')->default(0);
                $table->unsignedInteger('unresolved_rows')->default(0);
                $table->unsignedInteger('inserted_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->unsignedInteger('replaced_rows')->default(0);
                $table->json('diagnostics')->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable()->index('idx_voter_import_uploader');
                $table->unsignedBigInteger('committed_by')->nullable();
                $table->dateTime('committed_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('bow_tbl_purok_aliases')) {
            Schema::create('bow_tbl_purok_aliases', function (Blueprint $table) {
                $table->bigIncrements('alias_id');
                $table->integer('barangay_id')->index('idx_purok_alias_barangay');
                $table->integer('purok_id')->index('idx_purok_alias_purok');
                $table->string('alias_text', 255);
                $table->string('alias_normalized', 255);
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamps();
                $table->unique(['barangay_id', 'alias_normalized'], 'uq_purok_alias_per_barangay');
            });
        }

        if (!Schema::hasTable('bow_tbl_voter_import_rows')) {
            Schema::create('bow_tbl_voter_import_rows', function (Blueprint $table) {
                $table->bigIncrements('import_row_id');
                $table->unsignedBigInteger('import_id');
                $table->unsignedInteger('source_record_no');
                $table->string('raw_name', 300);
                $table->string('raw_address', 500)->nullable();
                $table->string('raw_birthdate', 40)->nullable();
                $table->string('raw_sex', 20)->nullable();
                $table->string('raw_precinct', 100)->nullable();
                $table->string('normalized_address', 255);
                $table->string('first_name', 150)->nullable();
                $table->string('middle_name', 150)->nullable();
                $table->string('last_name', 150)->nullable();
                $table->string('extension', 50)->nullable();
                $table->date('birthdate')->nullable();
                $table->string('sex', 20)->nullable();
                $table->string('precinct_no', 100)->nullable();
                $table->integer('barangay_id')->nullable();
                $table->integer('purok_id')->nullable();
                $table->integer('precinct_id')->nullable();
                $table->string('proposed_purok_name', 150)->nullable();
                $table->boolean('remember_alias')->default(false);
                $table->string('location_resolution', 30)->default('UNRESOLVED');
                $table->string('status', 30)->default('REVIEW_REQUIRED');
                $table->char('row_fingerprint', 64);
                $table->json('parse_issues')->nullable();
                $table->json('issues')->nullable();
                $table->timestamps();

                $table->unique(['import_id', 'source_record_no'], 'uq_voter_import_source_no');
                $table->index(['import_id', 'normalized_address'], 'idx_voter_import_address');
                $table->index(['import_id', 'status'], 'idx_voter_import_row_status');
                $table->foreign('import_id', 'fk_voter_import_row_import')
                    ->references('import_id')
                    ->on('bow_tbl_voter_imports')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('bow_tbl_recipients')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                if (!Schema::hasColumn('bow_tbl_recipients', 'precinct_id')) {
                    $table->integer('precinct_id')->nullable()->after('precinct_no');
                    $table->index('precinct_id', 'idx_recipients_precinct_id');
                }
                if (!Schema::hasColumn('bow_tbl_recipients', 'source_full_name')) {
                    $table->string('source_full_name', 300)->nullable()->after('extension');
                }
                if (!Schema::hasColumn('bow_tbl_recipients', 'source_address')) {
                    $table->string('source_address', 500)->nullable()->after('source_full_name');
                }
                if (!Schema::hasColumn('bow_tbl_recipients', 'source_record_no')) {
                    $table->unsignedInteger('source_record_no')->nullable()->after('source_address');
                }
                if (!Schema::hasColumn('bow_tbl_recipients', 'import_id')) {
                    $table->unsignedBigInteger('import_id')->nullable()->after('source_record_no');
                    $table->index('import_id', 'idx_recipients_import_id');
                }
                if (!Schema::hasColumn('bow_tbl_recipients', 'row_fingerprint')) {
                    $table->char('row_fingerprint', 64)->nullable()->after('import_id');
                    $table->index(['barangay', 'row_fingerprint'], 'idx_recipients_barangay_fingerprint');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bow_tbl_recipients')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $columns = [
                    'precinct_id',
                    'source_full_name',
                    'source_address',
                    'source_record_no',
                    'import_id',
                    'row_fingerprint',
                ];

                foreach ($columns as $column) {
                    if (Schema::hasColumn('bow_tbl_recipients', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('bow_tbl_voter_import_rows');
        Schema::dropIfExists('bow_tbl_purok_aliases');
        Schema::dropIfExists('bow_tbl_voter_imports');
    }

    private function ensureLegacyPrimaryKeyAutoIncrements(string $table, string $column): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql' || !Schema::hasTable($table)) {
            return;
        }

        $definition = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->first(['COLUMN_TYPE', 'EXTRA']);

        if (!$definition || str_contains(strtolower((string) $definition->EXTRA), 'auto_increment')) {
            return;
        }

        $columnType = (string) $definition->COLUMN_TYPE;
        if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/i', $columnType)) {
            return;
        }

        $references = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join) {
                $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')
                    ->on('r.TABLE_NAME', '=', 'k.TABLE_NAME')
                    ->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
            })
            ->where('k.CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('k.REFERENCED_TABLE_NAME', $table)
            ->where('k.REFERENCED_COLUMN_NAME', $column)
            ->get([
                'k.TABLE_NAME as child_table',
                'k.COLUMN_NAME as child_column',
                'k.CONSTRAINT_NAME as constraint_name',
                'r.UPDATE_RULE as update_rule',
                'r.DELETE_RULE as delete_rule',
            ]);

        $dropped = [];
        try {
            foreach ($references as $reference) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP FOREIGN KEY %s',
                    $this->quoteIdentifier((string) $reference->child_table),
                    $this->quoteIdentifier((string) $reference->constraint_name)
                ));
                $dropped[] = $reference;
            }

            DB::statement(sprintf(
                'ALTER TABLE %s MODIFY %s %s NOT NULL AUTO_INCREMENT',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($column),
                $columnType
            ));
        } finally {
            foreach ($dropped as $reference) {
                if ($this->foreignKeyExists((string) $reference->child_table, (string) $reference->constraint_name)) {
                    continue;
                }

                DB::statement(sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON UPDATE %s ON DELETE %s',
                    $this->quoteIdentifier((string) $reference->child_table),
                    $this->quoteIdentifier((string) $reference->constraint_name),
                    $this->quoteIdentifier((string) $reference->child_column),
                    $this->quoteIdentifier($table),
                    $this->quoteIdentifier($column),
                    $this->foreignKeyRule((string) $reference->update_rule),
                    $this->foreignKeyRule((string) $reference->delete_rule)
                ));
            }
        }
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function foreignKeyRule(string $rule): string
    {
        $rule = strtoupper(trim($rule));
        return in_array($rule, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)
            ? $rule
            : 'RESTRICT';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
};
