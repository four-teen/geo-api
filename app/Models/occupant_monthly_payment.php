<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class occupant_monthly_payment extends Model
{
    use HasFactory;


    protected $fillable = [
        'stall_no',
        'or_number',
        'paid_date',
        'is_void',
        'status',
    ];
}
