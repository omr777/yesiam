<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccessRequest extends Model
{
    /*
    |--------------------------------------------------------------------------
    | CAMPOS PERMITIDOS
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'requester_user_id',
        'resource_id',
        'justification',
    ];

    /*
    |--------------------------------------------------------------------------
    | CONVERSIÓN DE TIPOS
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | USUARIO QUE REALIZÓ LA SOLICITUD
    |--------------------------------------------------------------------------
    */

    public function requester(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requester_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECURSO SOLICITADO
    |--------------------------------------------------------------------------
    */

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /*
    |--------------------------------------------------------------------------
    | USUARIO QUE TOMÓ LA DECISIÓN
    |--------------------------------------------------------------------------
    */

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'decided_by_user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CONCESIÓN ORIGINADA POR ESTA SOLICITUD
    |--------------------------------------------------------------------------
    */

    public function accessGrant(): HasOne
    {
        return $this->hasOne(
            AccessGrant::class,
            'access_request_id'
        );
    }
}
