<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class terminal_puv extends Model
{
    use HasFactory;

    protected $fillable = [
        'cooperative_id',
        'plate_number',
        'owner',
        'contact_no',
        'make',
    ];
}
