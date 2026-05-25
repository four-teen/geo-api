<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BowReligion extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_religions';
    protected $primaryKey = 'religion_id';

    protected $fillable = [
        'religion_name',
        'status',
    ];

    public function recipients()
    {
        return $this->hasMany(BowRecipient::class, 'religion', 'religion_name');
    }
}
