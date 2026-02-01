<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class dispense_tickets extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_ticket_id',
        'collector_id',
        'is_void',
        'status',
    ];

}
