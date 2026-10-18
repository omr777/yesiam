<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Department;
use App\Policies\DepartmentPolicy;
use Illuminate\Support\Facades\Gate;
use App\Models\AuditLog;
use App\Policies\AuditLogPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(
        \App\Models\User::class,
        \App\Policies\UserPolicy::class
        );
    }
}
