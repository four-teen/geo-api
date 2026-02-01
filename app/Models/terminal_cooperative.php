<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class terminal_cooperative extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'terminal_puv_type_id',
    ];
}
