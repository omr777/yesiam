<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    /*
    |--------------------------------------------------------------------------
    | CAMPOS PERMITIDOS
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    /*
    |--------------------------------------------------------------------------
    | CONVERSIÓN DE TIPOS
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSABLES DE APROBACIÓN
    |--------------------------------------------------------------------------
    */

    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'resource_approvers',
            'resource_id',
            'user_id'
        )
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | CONCESIONES DE ACCESO AL RECURSO
    |--------------------------------------------------------------------------
    */

    public function accessGrants(): HasMany
    {
        return $this->hasMany(
            AccessGrant::class,
            'resource_id'
        );
    }
}
