<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_permissions')) {
            return;
        }

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');

            $table->primary(['user_id', 'permission_id']);

            $table->foreign('user_id', 'fk_user_permissions_user')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('permission_id', 'fk_user_permissions_permission')
                ->references('id')
                ->on('permissions')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('user_permissions')) {
            return;
        }

        Schema::table('user_permissions', function (Blueprint $table) {
            $table->dropForeign('fk_user_permissions_user');
            $table->dropForeign('fk_user_permissions_permission');
        });

        Schema::dropIfExists('user_permissions');
    }
};

