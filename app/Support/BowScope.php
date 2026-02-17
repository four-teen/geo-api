<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BowScope
{
    public static function allowedBarangayIds(?User $user): ?array
    {
        if (!$user || $user->role === 'administrator' || $user->barangay_scope === 'ALL') {
            return null;
        }

        return $user->barangays()
            ->pluck('bow_tbl_barangays.barangay_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public static function hasSpecificScope(?User $user): bool
    {
        return $user && $user->role !== 'administrator' && $user->barangay_scope === 'SPECIFIC';
    }

    public static function canAccessBarangay(?User $user, int $barangayId): bool
    {
        $allowedIds = self::allowedBarangayIds($user);

        if ($allowedIds === null) {
            return true;
        }

        return in_array($barangayId, $allowedIds, true);
    }

    public static function ensureBarangayAccess(?User $user, int $barangayId): void
    {
        if (!self::canAccessBarangay($user, $barangayId)) {
            throw new HttpException(403, 'You are not allowed to access this barangay.');
        }
    }

    /**
     * @param EloquentBuilder|QueryBuilder $query
     * @return EloquentBuilder|QueryBuilder
     */
    public static function applyBarangayFilter($query, ?User $user, string $column = 'barangay_id')
    {
        $allowedIds = self::allowedBarangayIds($user);

        if ($allowedIds === null) {
            return $query;
        }

        if (count($allowedIds) === 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $allowedIds);
    }
}

