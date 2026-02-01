<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class terminal_routes extends Model
{
    use HasFactory;
    protected $fillable = [
        'terminal',
        'route',
        'first_trip_tiket_fare',
        'base_trip_tiket_fare',
    ];
}
