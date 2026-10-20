<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

use App\Models\Department;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Resource;
use App\Models\AccessRequest;

use App\Policies\DepartmentPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\UserPolicy;
use App\Policies\ResourcePolicy;
use App\Policies\AccessRequestPolicy;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(
            Department::class,
            DepartmentPolicy::class
        );

        Gate::policy(
            AuditLog::class,
            AuditLogPolicy::class
        );

        Gate::policy(
            User::class,
            UserPolicy::class
        );

        Gate::policy(
            Resource::class,
            ResourcePolicy::class
        );

        Gate::policy(
            AccessRequest::class,
            AccessRequestPolicy::class
        );
    }
}
