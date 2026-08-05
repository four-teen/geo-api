<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class BowRecipient extends Model
{
    use HasFactory;

    protected $table = 'bow_tbl_recipients';
    protected $primaryKey = 'recipient_id';

    protected $fillable = [
        'precinct_no',
        'precinct_id',
        'voters_id_number',
        'first_name',
        'middle_name',
        'last_name',
        'extension',
        'source_full_name',
        'source_address',
        'source_record_no',
        'import_id',
        'row_fingerprint',
        'birthdate',
        'occupation',
        'barangay',
        'purok',
        'marital_status',
        'phone_number',
        'religion',
        'tribe_id',
        'sex',
        'profile_picture',
        'house_picture',
        'latitude',
        'longitude',
        'location_accuracy_meters',
        'location_captured_at',
        'status',
    ];

    protected $casts = [
        'birthdate' => 'date:Y-m-d',
        'barangay' => 'integer',
        'purok' => 'integer',
        'tribe_id' => 'integer',
        'precinct_id' => 'integer',
        'source_record_no' => 'integer',
        'import_id' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'location_accuracy_meters' => 'float',
        'location_captured_at' => 'datetime',
    ];

    public function barangayRecord(): BelongsTo
    {
        return $this->belongsTo(BowBarangay::class, 'barangay', 'barangay_id');
    }

    public function purokRecord(): BelongsTo
    {
        return $this->belongsTo(BowPurok::class, 'purok', 'purok_id');
    }

    public function tribeRecord(): BelongsTo
    {
        return $this->belongsTo(BowTribe::class, 'tribe_id', 'tribe_id');
    }

    public function precinctRecord(): BelongsTo
    {
        return $this->belongsTo(BowPrecinct::class, 'precinct_id', 'precinct_id');
    }

    public function voterImport(): BelongsTo
    {
        return $this->belongsTo(BowVoterImport::class, 'import_id', 'import_id');
    }
}
