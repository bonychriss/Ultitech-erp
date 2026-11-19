<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'acc_users';

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function companyAccess(): HasMany
    {
        return $this->hasMany(UserCompanyAccess::class, 'user_id');
    }

    public function hasPermissionForCompany(int $companyId, string $permissionCode): bool
    {
        $access = $this->companyAccess()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with('role.permissions')
            ->first();

        if (! $access) {
            return false;
        }

        return $access->role->permissions->contains('code', $permissionCode);
    }
}
