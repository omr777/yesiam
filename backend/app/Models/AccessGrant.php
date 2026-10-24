<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessGrant extends Model
{
    /*
    |--------------------------------------------------------------------------
    | CAMPOS PERMITIDOS EN ASIGNACIÓN MASIVA
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'access_request_id',
        'user_id',
        'resource_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | CONVERSIÓN DE TIPOS
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SOLICITUD QUE ORIGINÓ LA CONCESIÓN
    |--------------------------------------------------------------------------
    */

    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(
            AccessRequest::class,
            'access_request_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | USUARIO QUE RECIBE EL ACCESO
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECURSO AL QUE SE CONCEDE ACCESO
    |--------------------------------------------------------------------------
    */

    public function resource(): BelongsTo
    {
        return $this->belongsTo(
            Resource::class,
            'resource_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ADMINISTRADOR QUE CONCEDIÓ EL ACCESO
    |--------------------------------------------------------------------------
    */

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'granted_by_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | USUARIO QUE REVOCÓ EL ACCESO
    |--------------------------------------------------------------------------
    */

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'revoked_by_user_id'
        );
    }
}
