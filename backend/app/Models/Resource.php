<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Resource extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSABLES DEL RECURSO
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
}
