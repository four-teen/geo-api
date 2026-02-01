<?php

/**
 * ============================================================
 * MODEL: SlaughterPublicTransaction
 * ------------------------------------------------------------
 * File    : app/Models/SlaughterPublicTransaction.php
 * Table   : tbl_slaughter_public_transactions
 *
 * Purpose :
 * - Central Eloquent model for PUBLIC slaughter transactions
 * - Shared by:
 *     • Public Cashier App (App 1)
 *     • Public Slaughter App (App 2)
 *
 * Design Rules :
 * - ONE model for ONE table
 * - Explicit fillable fields (NO guessing)
 * - Status-driven workflow
 * - Safe for partial updates
 * ============================================================
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlaughterPublicTransaction extends Model
{
    use HasFactory;

    /**
     * ============================================================
     * TABLE NAME
     * ------------------------------------------------------------
     * Explicitly defined to avoid Laravel guessing.
     * ============================================================
     */
    protected $table = 'tbl_slaughter_public_transactions';

    /**
     * ============================================================
     * MASS ASSIGNMENT
     * ------------------------------------------------------------
     * Only fields listed here may be updated via create/update().
     * This protects App 1 from overwriting App 2 data and vice versa.
     * ============================================================
     */
protected $fillable = [

    /* -------------------------------
       CASHIER (APP 1)
    -------------------------------- */
    'or_number',
    'agency',
    'payor',
    'cashier_user_id',
    'cashier_encoded_at',

    /* -------------------------------
       SLAUGHTER (APP 2)
    -------------------------------- */
    'small_heads',
    'small_kilos',        // ✅ NEW
    'goat_heads',
    'hog_heads',

    'large_heads',
    'large_kilos',        // ✅ NEW
    'cow_heads',
    'carabao_heads',

    'pmf_amount',
    'slaughter_user_id',
    'slaughter_encoded_at',

    /* -------------------------------
       WORKFLOW
    -------------------------------- */
    'status',
    'remarks',
];


    /**
     * ============================================================
     * STATUS CONSTANTS
     * ------------------------------------------------------------
     * Used across controllers and services.
     * NO magic strings.
     * ============================================================
     */
    public const STATUS_DRAFT          = 'draft';
    public const STATUS_CASHIER_ONLY   = 'cashier_only';
    public const STATUS_SLAUGHTER_ONLY = 'slaughter_only';
    public const STATUS_COMPLETED      = 'completed';

    /**
     * ============================================================
     * STATUS HELPERS
     * ------------------------------------------------------------
     * Improves readability and prevents logic duplication.
     * ============================================================
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCashierOnly(): bool
    {
        return $this->status === self::STATUS_CASHIER_ONLY;
    }

    public function isSlaughterOnly(): bool
    {
        return $this->status === self::STATUS_SLAUGHTER_ONLY;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
