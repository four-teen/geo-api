<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bow_tbl_tribes')) {
            Schema::create('bow_tbl_tribes', function (Blueprint $table) {
                $table->increments('tribe_id');
                $table->string('tribe_name', 150)->unique('uq_tribe_name');
                $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        $now = now();
        $rows = array_map(fn (string $tribeName) => [
            'tribe_name' => $tribeName,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->defaultTribes());

        DB::table('bow_tbl_tribes')->insertOrIgnore($rows);

        if (Schema::hasTable('bow_tbl_recipients') && !Schema::hasColumn('bow_tbl_recipients', 'tribe_id')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $table->unsignedInteger('tribe_id')->nullable()->after('religion');
                $table->index('tribe_id', 'idx_recipients_tribe_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bow_tbl_recipients') && Schema::hasColumn('bow_tbl_recipients', 'tribe_id')) {
            Schema::table('bow_tbl_recipients', function (Blueprint $table) {
                $table->dropIndex('idx_recipients_tribe_id');
                $table->dropColumn('tribe_id');
            });
        }

        Schema::dropIfExists('bow_tbl_tribes');
    }

    private function defaultTribes(): array
    {
        return [
            'Manobo',
            'Maguindanao',
            'Maranao',
            'Tausug',
            'Yakan',
            'Sama-Bajau',
            'Iranun',
            "T'boli",
            'Blaan',
            'Mandaya',
        ];
    }
};
