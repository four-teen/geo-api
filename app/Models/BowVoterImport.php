<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BowVoterImport extends Model
{
    protected $table = 'bow_tbl_voter_imports';
    protected $primaryKey = 'import_id';

    protected $guarded = [];

    protected $casts = [
        'barangay_id' => 'integer',
        'declared_total' => 'integer',
        'parsed_rows' => 'integer',
        'ready_rows' => 'integer',
        'warning_rows' => 'integer',
        'error_rows' => 'integer',
        'unresolved_rows' => 'integer',
        'inserted_rows' => 'integer',
        'skipped_rows' => 'integer',
        'replaced_rows' => 'integer',
        'diagnostics' => 'array',
        'committed_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(BowVoterImportRow::class, 'import_id', 'import_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(BowBarangay::class, 'barangay_id', 'barangay_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
