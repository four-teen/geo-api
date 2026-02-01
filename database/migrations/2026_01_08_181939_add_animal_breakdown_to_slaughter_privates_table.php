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
    Schema::table('slaughter_privates', function (Blueprint $table) {

        /**
         * SMALL ANIMALS (Goat / Hog)
         * These fields are IDENTIFICATION ONLY.
         * They DO NOT affect computation.
         */

        $table->integer('small_kilos')
              ->nullable()
              ->after('small_heads');

        $table->integer('goat_heads')
              ->nullable()
              ->after('small_kilos');

        $table->integer('hog_heads')
              ->nullable()
              ->after('goat_heads');


        /**
         * LARGE ANIMALS (Cow / Carabao)
         * These fields are IDENTIFICATION ONLY.
         * They DO NOT affect computation.
         */

        $table->integer('large_kilos')
              ->nullable()
              ->after('large_heads');

        $table->integer('cow_heads')
              ->nullable()
              ->after('large_kilos');

        $table->integer('carabao_heads')
              ->nullable()
              ->after('cow_heads');
    });
}


    /**
     * Reverse the migrations.
     *
     * @return void
     */
public function down()
{
    Schema::table('slaughter_privates', function (Blueprint $table) {

        // Rollback SMALL animal fields
        $table->dropColumn([
            'small_kilos',
            'goat_heads',
            'hog_heads',
        ]);

        // Rollback LARGE animal fields
        $table->dropColumn([
            'large_kilos',
            'cow_heads',
            'carabao_heads',
        ]);
    });
}

};
