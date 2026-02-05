<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowMedicine extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_medicines';

    protected $primaryKey = 'medicine_id';

    protected $fillable = [
        'medicine_name',
        'quantity',
        'status',
    ];
}
