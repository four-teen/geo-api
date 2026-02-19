<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – PATIENT MODEL
 * ----------------------------------------------------------------------------
 * Table : bow_tbl_patients
 * PK    : patient_id
 * ============================================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowPatient extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_patients';
    protected $primaryKey = 'patient_id';

    /**
     * ============================================================================
     * MASS ASSIGNABLE FIELDS
     * ----------------------------------------------------------------------------
     * is_senior:
     * - Manual senior citizen flag
     * - NOT auto-computed from age
     * ============================================================================
     */
    protected $fillable = [
        'last_name',
        'first_name',
        'middle_name',
        'birthdate',
        'sex',
        'marital_status',
        'spouse_name',
        'is_pwd',
        'is_senior', 
        'is_hpn',
        'is_dm',
        'is_ekonsulta_member',
        'contact_number',
        'barangay_id',
        'purok_id',
        'status',
    ];

    public function barangay()
    {
        return $this->belongsTo(BowBarangay::class, 'barangay_id', 'barangay_id');
    }

    public function purok()
    {
        return $this->belongsTo(BowPurok::class, 'purok_id', 'purok_id');
    }
}
