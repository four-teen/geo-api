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
        Schema::create('terminal_routes', function (Blueprint $table) {
            $table->id();
            $table->string('terminal')->nullable();
            $table->string('route')->nullable();
            $table->double('first_trip_tiket_fare')->default(0);
            $table->double('base_trip_tiket_fare')->default(0);
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
        Schema::dropIfExists('terminal_routes');
    }
};
