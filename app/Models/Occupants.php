<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Occupants extends Model
{
    use HasFactory;

    protected $fillable = [
        'stall_no',
        'awardee_name',
        'occupant_name',
        'is_rentee',
        'is_with_business_permit',
        'is_with_water_electricity',
        'section_id',
        'is_active',
        'collector_id',
        'remarks',
    ];
}
