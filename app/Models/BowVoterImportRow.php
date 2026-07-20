<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BowVoterImportRow extends Model
{
    protected $table = 'bow_tbl_voter_import_rows';
    protected $primaryKey = 'import_row_id';

    protected $guarded = [];

    protected $casts = [
        'source_record_no' => 'integer',
        'birthdate' => 'date:Y-m-d',
        'barangay_id' => 'integer',
        'purok_id' => 'integer',
        'precinct_id' => 'integer',
        'remember_alias' => 'boolean',
        'match_score' => 'float',
        'parse_issues' => 'array',
        'issues' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BowVoterImport::class, 'import_id', 'import_id');
    }
}
