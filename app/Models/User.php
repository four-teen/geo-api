<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'designation',
        'email',
        'password',
        'role',
        'is_active',
        'can_delete',
        'barangay_scope',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'can_delete' => 'boolean',
        'must_change_password' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permissions',
            'user_id',
            'permission_id'
        );
    }

    public function barangays(): BelongsToMany
    {
        return $this->belongsToMany(
            BowBarangay::class,
            'user_barangays',
            'user_id',
            'barangay_id'
        );
    }

    public function isAdministrator(): bool
    {
        return $this->role === 'administrator';
    }

    public function hasPermissionCode(string $code): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        if ($this->relationLoaded('permissions')) {
            return $this->permissions->contains('code', $code);
        }

        return $this->permissions()->where('code', $code)->exists();
    }
}
