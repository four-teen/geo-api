<?php

/**
 * ============================================================
 * MIGRATION: PUBLIC SLAUGHTER TRANSACTIONS
 * ------------------------------------------------------------
 * Table   : tbl_slaughter_public_transactions
 * Purpose :
 * - Central transaction table shared by:
 *     • Public Cashier App (App 1)
 *     • Public Slaughter App (App 2)
 *
 * Design Rules :
 * - ONE table only
 * - ONE row = ONE transaction
 * - App 1 and App 2 update different columns
 * - Status field controls workflow
 * - NO guessing, ID-based only
 *
 * Status Flow :
 * - draft
 * - cashier_only
 * - slaughter_only
 * - completed
 * ============================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_slaughter_public_transactions', function (Blueprint $table) {

            // ====================================================
            // PRIMARY KEY
            // ====================================================
            $table->id();

            // ====================================================
            // CASHIER (APP 1) FIELDS
            // ====================================================
            $table->string('or_number', 50)->nullable();
            $table->string('agency', 150)->nullable();
            $table->string('payor', 150)->nullable();

            $table->unsignedBigInteger('cashier_user_id')->nullable();
            $table->dateTime('cashier_encoded_at')->nullable();

            // ====================================================
            // SLAUGHTER (APP 2) FIELDS
            // ====================================================
            $table->integer('small_heads')->default(0);
            $table->integer('goat_heads')->default(0);
            $table->integer('hog_heads')->default(0);
            $table->integer('large_heads')->default(0);
            $table->integer('cow_heads')->default(0);
            $table->integer('carabao_heads')->default(0);

            $table->decimal('total_kilos', 10, 2)->default(0.00);
            $table->decimal('pmf_amount', 10, 2)->default(0.00);

            $table->unsignedBigInteger('slaughter_user_id')->nullable();
            $table->dateTime('slaughter_encoded_at')->nullable();

            // ====================================================
            // WORKFLOW CONTROL
            // ====================================================
            $table->enum('status', [
                'draft',
                'cashier_only',
                'slaughter_only',
                'completed',
            ])->default('draft');

            $table->text('remarks')->nullable();

            // ====================================================
            // TIMESTAMPS
            // ====================================================
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_slaughter_public_transactions');
    }
};
