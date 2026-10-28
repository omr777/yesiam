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
    |
    | department_restricted no se incluye
    | en fillable intencionalmente.
    |
    | Su modificación requerirá una operación
    | administrativa explícita y auditada.
    |
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
            'department_restricted' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSABLES DE APROBACIÓN
    |--------------------------------------------------------------------------
    |
    | Un recurso puede tener varios responsables.
    |
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
    | CONCESIONES DE ACCESO
    |--------------------------------------------------------------------------
    |
    | Un recurso puede tener varias concesiones,
    | correspondientes a distintos usuarios
    | o momentos de su ciclo de vida.
    |
    */

    public function accessGrants(): HasMany
    {
        return $this->hasMany(
            AccessGrant::class,
            'resource_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DEPARTAMENTOS ELEGIBLES
    |--------------------------------------------------------------------------
    |
    | Relación muchos a muchos:
    |
    | Resource <-> Department
    |
    | La tabla intermedia contiene:
    |
    | - resource_id
    | - department_id
    | - assigned_by_user_id
    | - created_at
    | - updated_at
    |
    */

    public function eligibleDepartments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'resource_department_eligibilities',
            'resource_id',
            'department_id'
        )
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR ELEGIBILIDAD DEPARTAMENTAL
    |--------------------------------------------------------------------------
    |
    | Reglas:
    |
    | 1. Recurso sin restricción:
    |    No se exige pertenecer a una lista
    |    de departamentos elegibles.
    |
    | 2. Recurso restringido:
    |    El departamento debe estar incluido
    |    explícitamente en la lista.
    |
    | 3. Recurso restringido sin departamentos:
    |    Nadie resulta elegible.
    |
    | 4. Departamento desactivado:
    |    No puede cumplir la elegibilidad
    |    de un recurso restringido.
    |
    | Este método solamente evalúa la
    | restricción departamental.
    |
    | No sustituye:
    | - La autenticación.
    | - Los permisos RBAC.
    | - La aprobación de solicitudes.
    | - La verificación del recurso activo.
    |
    */

    public function isEligibleForDepartment(
        ?int $departmentId
    ): bool {
        /*
         * Sin restricción departamental,
         * el recurso conserva su comportamiento
         * anterior.
         */
        if (! $this->department_restricted) {
            return true;
        }

        /*
         * Un recurso restringido no puede
         * autorizar una identidad sin
         * departamento asignado.
         */
        if ($departmentId === null) {
            return false;
        }

        /*
         * Buscar una relación explícita
         * con un departamento activo.
         */
        return $this->eligibleDepartments()
            ->where(
                'departments.id',
                $departmentId
            )
            ->where(
                'departments.is_active',
                true
            )
            ->exists();
    }
}
