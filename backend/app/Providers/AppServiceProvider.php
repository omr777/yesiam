<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

use App\Models\Department;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Resource;

use App\Policies\DepartmentPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\UserPolicy;
use App\Policies\ResourcePolicy;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Política de departamentos.
        Gate::policy(
            Department::class,
            DepartmentPolicy::class
        );

        // Política de auditoría.
        Gate::policy(
            AuditLog::class,
            AuditLogPolicy::class
        );

        // Política de identidades.
        Gate::policy(
            User::class,
            UserPolicy::class
        );

        // Política de recursos.
        Gate::policy(
            Resource::class,
            ResourcePolicy::class
        );
    }
}
