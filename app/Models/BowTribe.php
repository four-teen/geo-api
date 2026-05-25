<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowTribe extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_tribes';
    protected $primaryKey = 'tribe_id';

    protected $fillable = [
        'tribe_name',
        'status',
    ];

    public function recipients()
    {
        return $this->hasMany(BowRecipient::class, 'tribe_id', 'tribe_id');
    }
}
