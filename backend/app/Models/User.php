<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    
    public function roles(): BelongsToMany
{
    return $this->belongsToMany(
        Role::class,
        'user_roles',
        'user_id',
        'role_id'
    );
}

    public function hasRole(string $code): bool
{
    return $this->roles()
        ->where('roles.code', $code)
        ->exists();
}

    public function hasPermission(string $code): bool
{
    return $this->roles()
        ->whereHas('permissions', function ($query) use ($code) {
            $query->where('permissions.code', $code);
        })
        ->exists();
}

}
