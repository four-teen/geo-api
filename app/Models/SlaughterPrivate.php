<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlaughterPrivate extends Model
{
    use HasFactory;

    /**
     * Mass assignable fields for slaughter_privates table.
     *
     * NOTE:
     * - small_heads and large_heads remain the official basis for computation
     * - animal breakdown and kilos are IDENTIFICATION ONLY
     * - pmf is a flat fee per transaction
     */
    protected $fillable = [
        // Basic transaction info
        'date',
        'or_no',
        'agency',
        'owner',

        // Official computation basis
        'small_heads',
        'large_heads',

        // Identification ONLY (manual input)
        'small_kilos',
        'goat_heads',
        'hog_heads',

        'large_kilos',
        'cow_heads',
        'carabao_heads',

        // Flat Post Mortem Fee
        'pmf',
    ];
}
