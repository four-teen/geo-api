<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – BARANGAY MODEL
 * ----------------------------------------------------------------------------
 * Table : bow_tbl_barangays
 * ============================================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowBarangay extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_barangays';
    protected $primaryKey = 'barangay_id';

    protected $fillable = [
        'barangay_name',
        'status',
    ];

    public function puroks()
    {
        return $this->hasMany(BowPurok::class, 'barangay_id', 'barangay_id');
    }
}
