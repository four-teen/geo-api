<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – PHYSICIAN MODEL
 * ----------------------------------------------------------------------------
 * Table : bow_tbl_physicians
 * Notes :
 * - No status column (as agreed)
 * - License/mobile not required, not unique (as agreed)
 * ============================================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowPhysician extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_physicians';
    protected $primaryKey = 'physician_id';

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'license_number',
        'mobile_number',
        'address',
    ];
}
