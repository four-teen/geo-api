<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bow_tbl_barangays')) {
            Schema::create('bow_tbl_barangays', function (Blueprint $table) {
                $table->increments('barangay_id');
                $table->string('barangay_name', 150)->unique('uq_barangay_name');
                $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('bow_tbl_puroks')) {
            Schema::create('bow_tbl_puroks', function (Blueprint $table) {
                $table->increments('purok_id');
                $table->unsignedInteger('barangay_id');
                $table->string('purok_name', 150);
                $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['barangay_id', 'purok_name'], 'uq_barangay_purok');
                $table->foreign('barangay_id')
                    ->references('barangay_id')
                    ->on('bow_tbl_barangays')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }

        if (!Schema::hasTable('bow_tbl_precincts')) {
            Schema::create('bow_tbl_precincts', function (Blueprint $table) {
                $table->increments('precinct_id');
                $table->unsignedInteger('purok_id');
                $table->string('precinct_name', 150);
                $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->unique(['purok_id', 'precinct_name'], 'uq_purok_precinct');
                $table->foreign('purok_id')
                    ->references('purok_id')
                    ->on('bow_tbl_puroks')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bow_tbl_precincts');
        Schema::dropIfExists('bow_tbl_puroks');
        Schema::dropIfExists('bow_tbl_barangays');
    }
};
