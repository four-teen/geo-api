<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LivestockCharges extends Model
{
    use HasFactory;
    protected $fillable = ['livestock_id', 'cf', 'sf', 'spf', 'pmf'];

    // public function livestock()
    // {
    //     return $this->belongsTo(Livestock::class);
    // }
}
