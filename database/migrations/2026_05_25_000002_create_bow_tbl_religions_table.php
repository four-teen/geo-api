<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bow_tbl_religions')) {
            Schema::create('bow_tbl_religions', function (Blueprint $table) {
                $table->increments('religion_id');
                $table->string('religion_name', 150)->unique('uq_religion_name');
                $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        $now = now();
        $rows = array_map(fn (string $religionName) => [
            'religion_name' => $religionName,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->defaultReligions());

        DB::table('bow_tbl_religions')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('bow_tbl_religions');
    }

    private function defaultReligions(): array
    {
        return [
            'Roman Catholic',
            'Islam',
            'Iglesia ni Cristo',
            'Philippine Independent Church',
            'Protestant',
            'Evangelical Christian',
            'Born Again Christian',
            'Seventh-day Adventist',
            "Jehovah's Witnesses",
            'The Church of Jesus Christ of Latter-day Saints',
        ];
    }
};
