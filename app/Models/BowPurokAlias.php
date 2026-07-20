<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BowPurokAlias extends Model
{
    protected $table = 'bow_tbl_purok_aliases';
    protected $primaryKey = 'alias_id';

    protected $guarded = [];
}
