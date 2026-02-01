<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class terminal_dispense_tickets extends Model
{
    use HasFactory;
    protected $fillable = [
        'terminal_id',
        'puv_id',
        'collector_id',
        'amount',
        'is_first_trip',
         'is_void'
    ];

}
