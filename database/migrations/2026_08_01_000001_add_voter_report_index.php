<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bow_tbl_recipients', function (Blueprint $table) {
            $table->index(['barangay', 'status'], 'idx_recipients_report_status');
        });
    }

    public function down(): void
    {
        Schema::table('bow_tbl_recipients', function (Blueprint $table) {
            $table->dropIndex('idx_recipients_report_status');
        });
    }
};
