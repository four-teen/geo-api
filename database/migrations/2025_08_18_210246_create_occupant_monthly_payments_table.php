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
        Schema::create('occupant_monthly_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('stall_no')->nullable();
            $table->string('or_number')->nullable();
            $table->string('paid_date');
            $table->integer('is_void')->default(0);
            $table->integer('status')->default(0);



            $table->unique(['stall_no','or_number','paid_date']); // Composite unique index
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
        Schema::dropIfExists('occupant_monthly_payments');
    }
};
