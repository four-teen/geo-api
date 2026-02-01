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
        Schema::create('livestock_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('livestock_id'); // foreign key to livestocks table
            $table->decimal('cf', 10, 2);   // Corral Fee
            $table->decimal('sf', 10, 2);   // Slaughter Fee
            $table->decimal('spf', 10, 2);  // Slaughter Permit Fee
            $table->decimal('pmf', 10, 2);  // Post Mortem Fee
            $table->timestamps();

            $table->foreign('livestock_id')->references('id')->on('livestocks')->onDelete('cascade');
        });
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('livestock_charges');
    }
};
