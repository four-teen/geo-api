<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_barangays')) {
            return;
        }

        Schema::create('user_barangays', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->integer('barangay_id');

            $table->primary(['user_id', 'barangay_id']);

            $table->foreign('user_id', 'fk_user_barangays_user')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('barangay_id', 'fk_user_barangays_barangay')
                ->references('barangay_id')
                ->on('bow_tbl_barangays')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_barangays')) {
            return;
        }

        Schema::table('user_barangays', function (Blueprint $table) {
            $table->dropForeign('fk_user_barangays_user');
            $table->dropForeign('fk_user_barangays_barangay');
        });

        Schema::dropIfExists('user_barangays');
    }
};

