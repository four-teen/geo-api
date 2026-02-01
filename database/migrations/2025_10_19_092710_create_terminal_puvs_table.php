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
        Schema::create('terminal_puvs', function (Blueprint $table) {
            $table->id();
            $table->integer('cooperative_id');
            $table->string('plate_number')->nullable();
            $table->string('owner')->nullable();
            $table->string('contact_no')->nullable();
            $table->string('make')->nullable();
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
        Schema::dropIfExists('terminal_puvs');
    }
};
