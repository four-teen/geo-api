<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – PATIENTS TABLE MIGRATION
 * ----------------------------------------------------------------------------
 * Table : bow_tbl_patients
 * Notes :
 * - Matches existing DB structure (INT PK/FK, DATETIME timestamps, enums)
 * - Foreign keys reference:
 *   - bow_tbl_barangays(barangay_id)
 *   - bow_tbl_puroks(purok_id)
 * ============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bow_tbl_patients', function (Blueprint $table) {

            // Primary Key (matches INT(11) AUTO_INCREMENT)
            $table->increments('patient_id');

            // Name fields
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();

            // Personal info
            $table->date('birthdate');
            $table->enum('sex', ['M', 'F']);

            // Marital / spouse
            $table->enum('marital_status', ['SINGLE', 'MARRIED', 'WIDOWED', 'SEPARATED'])->nullable();
            $table->string('spouse_name', 150)->nullable();

            // Classification
            $table->boolean('is_pwd')->default(false);

            // Contact
            $table->string('contact_number', 30)->nullable();

            // Location FK (matches INT(11))
            $table->integer('barangay_id');
            $table->integer('purok_id');

            // Status
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');

            // DATETIME audit columns (match your existing tables)
            $table->dateTime('created_at')->useCurrent()->nullable();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate()->nullable();

            // Indexes (recommended for filtering)
            $table->index('barangay_id', 'idx_patient_barangay');
            $table->index('purok_id', 'idx_patient_purok');

            // Foreign Keys (RESTRICT delete: consistent with barangay/purok safety)
            $table->foreign('barangay_id', 'fk_patient_barangay')
                ->references('barangay_id')->on('bow_tbl_barangays')
                ->onDelete('restrict');

            $table->foreign('purok_id', 'fk_patient_purok')
                ->references('purok_id')->on('bow_tbl_puroks')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bow_tbl_patients');
    }
};
