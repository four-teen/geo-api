<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('occupants', function (Blueprint $table) {
            $table->id();
            $table->string('stall_no')->unique();
            $table->string('awardee_name')->nullable();
            $table->string('occupant_name')->nullable();
            $table->integer('is_rentee')->default(0);
            $table->integer('is_with_business_permit')->default(0);
            $table->integer('is_with_water_electricity')->default(0);
            $table->integer('section_id')->default(0);
            $table->integer('is_active')->default(1);
            $table->integer('collector_id')->default(0);
            $table->string('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('occupants');
    }
};
