<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bow_tbl_recipients')) {
            return;
        }

        Schema::create('bow_tbl_recipients', function (Blueprint $table) {
            $table->bigIncrements('recipient_id');
            $table->string('precinct_no', 100)->nullable();
            $table->string('voters_id_number', 120)->nullable();
            $table->string('first_name', 150)->nullable();
            $table->string('middle_name', 150)->nullable();
            $table->string('last_name', 150)->nullable();
            $table->string('extension', 50)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('occupation', 200)->nullable();
            $table->string('barangay', 200)->nullable();
            $table->string('purok', 200)->nullable();
            $table->string('marital_status', 50)->nullable();
            $table->string('phone_number', 50)->nullable();
            $table->string('religion', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->string('profile_picture', 500)->nullable();
            $table->string('status', 50)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['barangay', 'purok'], 'idx_recipients_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bow_tbl_recipients');
    }
};
