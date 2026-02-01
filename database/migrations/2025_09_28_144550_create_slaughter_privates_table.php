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
        Schema::create('slaughter_privates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('or_no')->unique();
            $table->string('agency');
            $table->string('owner');

            // instead of "type", we now store heads separately
            $table->integer('small_heads')->default(0);
            $table->integer('large_heads')->default(0);

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
        Schema::dropIfExists('slaughter_privates');
    }
};
