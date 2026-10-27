<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | DEFINICIÓN DE PERMISOS
        |--------------------------------------------------------------------------
        */

        $permissions = [

            // Perfil
            'profile.view' => 'Consultar perfil propio',

            // Solicitudes y aprobaciones
            'access.request' => 'Solicitar acceso',
            'access.approve' => 'Aprobar o rechazar solicitudes asignadas',

            // Provisionamiento
            'access.grant' => 'Ejecutar concesiones de acceso',
            'access.revoke' => 'Revocar concesiones de acceso',

            // Visibilidad de accesos
            'access.view_all' => 'Consultar los accesos de otras identidades',

            // Departamentos
            'departments.view' => 'Consultar departamentos',
            'departments.create' => 'Crear departamentos',
            'departments.update' => 'Modificar departamentos',
            'departments.deactivate' => 'Desactivar departamentos',

            // Identidades
            'users.view' => 'Consultar identidades',
            'users.manage' => 'Administrar identidades',

            // Auditoría
            'audit.view' => 'Consultar auditoría',

            // Catálogo de recursos
            'resources.view' => 'Consultar recursos',
            'resources.manage' => 'Administrar recursos',
        ];

        /*
        |--------------------------------------------------------------------------
        | CREAR O ACTUALIZAR PERMISOS
        |--------------------------------------------------------------------------
        */

        foreach ($permissions as $code => $name) {
            Permission::updateOrCreate(
                ['code' => $code],
                ['name' => $name]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | DEFINICIÓN DE ROLES
        |--------------------------------------------------------------------------
        */

        $roles = [

            /*
            |--------------------------------------------------------------------------
            | EMPLOYEE
            |--------------------------------------------------------------------------
            |
            | Puede consultar su perfil, solicitar acceso
            | y conocer los recursos disponibles.
            |
            */

            'employee' => [
                'name' => 'Empleado',

                'permissions' => [
                    'profile.view',
                    'access.request',
                    'resources.view',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | RESOURCE MANAGER
            |--------------------------------------------------------------------------
            |
            | Puede solicitar acceso y aprobar o rechazar
            | solicitudes de los recursos asignados.
            |
            | No puede ejecutar concesiones ni revocaciones.
            |
            */

            'resource_manager' => [
                'name' => 'Responsable de recurso',

                'permissions' => [
                    'profile.view',
                    'access.request',
                    'access.approve',
                    'resources.view',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | IAM ADMIN
            |--------------------------------------------------------------------------
            |
            | Administra identidades, departamentos,
            | recursos, concesiones y revocaciones.
            |
            | También puede consultar los inventarios
            | de acceso de otras identidades.
            |
            */

            'iam_admin' => [
                'name' => 'Administrador IAM',

                'permissions' => [
                    // Perfil y solicitudes
                    'profile.view',
                    'access.request',

                    // Provisionamiento
                    'access.grant',
                    'access.revoke',

                    // Visibilidad de accesos
                    'access.view_all',

                    // Departamentos
                    'departments.view',
                    'departments.create',
                    'departments.update',
                    'departments.deactivate',

                    // Identidades
                    'users.view',
                    'users.manage',

                    // Auditoría
                    'audit.view',

                    // Recursos
                    'resources.view',
                    'resources.manage',
                ],
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | CREAR ROLES Y SINCRONIZAR PERMISOS
        |--------------------------------------------------------------------------
        */

        foreach ($roles as $code => $data) {

            $role = Role::updateOrCreate(
                ['code' => $code],
                ['name' => $data['name']]
            );

            $permissionIds = Permission::query()
                ->whereIn(
                    'code',
                    $data['permissions']
                )
                ->pluck('id');

            /*
             * sync() deja exactamente los permisos
             * definidos para este rol.
             *
             * Esto también elimina permisos que
             * ya no corresponden al rol.
             */
            $role->permissions()->sync($permissionIds);
        }
    }
}
