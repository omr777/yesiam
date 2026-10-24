<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /*
    |--------------------------------------------------------------------------
    | CONVERSIÓN DE ATRIBUTOS
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ROLES DEL USUARIO
    |--------------------------------------------------------------------------
    */

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_roles',
            'user_id',
            'role_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COMPROBAR SI EL USUARIO TIENE UN ROL
    |--------------------------------------------------------------------------
    */

    public function hasRole(string $code): bool
    {
        return $this->roles()
            ->where('roles.code', $code)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | COMPROBAR PERMISOS
    |--------------------------------------------------------------------------
    */

    public function hasPermission(string $code): bool
    {
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($code) {
                $query->where('permissions.code', $code);
            })
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | DEPARTAMENTO DEL USUARIO
    |--------------------------------------------------------------------------
    */

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /*
    |--------------------------------------------------------------------------
    | RECURSOS QUE ADMINISTRA EL USUARIO
    |--------------------------------------------------------------------------
    */

    public function managedResources(): BelongsToMany
    {
        return $this->belongsToMany(
            Resource::class,
            'resource_approvers',
            'user_id',
            'resource_id'
        )
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | CONCESIONES DE ACCESO DEL USUARIO
    |--------------------------------------------------------------------------
    */

    public function accessGrants(): HasMany
    {
        return $this->hasMany(
            AccessGrant::class,
            'user_id'
        );
    }
}
