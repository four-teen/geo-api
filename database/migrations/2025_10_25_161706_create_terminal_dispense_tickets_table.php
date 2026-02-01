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
        Schema::create('terminal_dispense_tickets', function (Blueprint $table) {
            $table->id();
            $table->integer('terminal_id');
            $table->integer('puv_id');
            $table->integer('collector_id');
            $table->double('amount')->default(0);
            $table->integer('is_first_trip')->default(0);
            $table->integer('is_void')->default(0);
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
        Schema::dropIfExists('terminal_dispense_tickets');
    }
};
