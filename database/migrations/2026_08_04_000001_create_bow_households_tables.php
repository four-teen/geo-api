<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bow_tbl_households')) {
            Schema::create('bow_tbl_households', function (Blueprint $table) {
                $table->increments('household_id');
                $table->unsignedInteger('household_head_recipient_id');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index('household_head_recipient_id', 'idx_households_head_recipient');
            });
        }

        if (!Schema::hasTable('bow_tbl_household_members')) {
            Schema::create('bow_tbl_household_members', function (Blueprint $table) {
                $table->increments('household_member_id');
                $table->unsignedInteger('household_id');
                $table->unsignedInteger('recipient_id');
                $table->string('relationship_to_head', 80);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique('recipient_id', 'uq_household_members_recipient');
                $table->index('household_id', 'idx_household_members_household');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bow_tbl_household_members');
        Schema::dropIfExists('bow_tbl_households');
    }
};
