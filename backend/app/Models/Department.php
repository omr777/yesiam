<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    /*
    |--------------------------------------------------------------------------
    | CAMPOS PERMITIDOS
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'code',
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
    | USUARIOS DEL DEPARTAMENTO
    |--------------------------------------------------------------------------
    |
    | Relación uno a muchos:
    |
    | Un departamento puede tener muchos usuarios.
    | Cada usuario pertenece a un departamento.
    |
    */

    public function users(): HasMany
    {
        return $this->hasMany(
            User::class,
            'department_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECURSOS ELEGIBLES DEL DEPARTAMENTO
    |--------------------------------------------------------------------------
    |
    | Relación muchos a muchos:
    |
    | Un departamento puede estar autorizado
    | para utilizar varios recursos.
    |
    | Un recurso puede estar autorizado
    | para varios departamentos.
    |
    | Tabla intermedia:
    | resource_department_eligibilities
    |
    | Campos adicionales:
    | - assigned_by_user_id
    | - created_at
    | - updated_at
    |
    */

    public function eligibleResources(): BelongsToMany
    {
        return $this->belongsToMany(
            Resource::class,
            'resource_department_eligibilities',
            'department_id',
            'resource_id'
        )
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }
}
