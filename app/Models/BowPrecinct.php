<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowPrecinct extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_precincts';
    protected $primaryKey = 'precinct_id';

    protected $fillable = [
        'purok_id',
        'precinct_name',
        'status',
    ];

    public function purok()
    {
        return $this->belongsTo(BowPurok::class, 'purok_id', 'purok_id');
    }
}
