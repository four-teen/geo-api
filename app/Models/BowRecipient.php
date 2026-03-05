<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowRecipient extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_recipients';
    protected $primaryKey = 'recipient_id';

    protected $fillable = [
        'precinct_no',
        'voters_id_number',
        'first_name',
        'middle_name',
        'last_name',
        'extension',
        'birthdate',
        'occupation',
        'barangay',
        'purok',
        'marital_status',
        'phone_number',
        'religion',
        'sex',
        'profile_picture',
        'status',
    ];

    protected $casts = [
        'birthdate' => 'date:Y-m-d',
    ];
}
