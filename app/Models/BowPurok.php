<?php

/**
 * ============================================================================
 * BOTIKA ON WHEELS – PUROK MODEL
 * ----------------------------------------------------------------------------
 * Table : bow_tbl_puroks
 * ============================================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowPurok extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_puroks';
    protected $primaryKey = 'purok_id';

    protected $fillable = [
        'barangay_id',
        'purok_name',
        'status',
    ];

    public function barangay()
    {
        return $this->belongsTo(BowBarangay::class, 'barangay_id', 'barangay_id');
    }
}
