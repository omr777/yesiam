<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'profile.view' => 'Consultar perfil propio',

            'access.request' => 'Solicitar acceso',
            'access.approve' => 'Aprobar solicitudes asignadas',
            'access.revoke' => 'Revocar accesos autorizados',

            'departments.view' => 'Consultar departamentos',
            'departments.create' => 'Crear departamentos',
            'departments.update' => 'Modificar departamentos',
            'departments.deactivate' => 'Desactivar departamentos',

            'users.view' => 'Consultar identidades',
            'users.manage' => 'Administrar identidades',

            'audit.view' => 'Consultar auditoría',

            // Nuevos permisos del catálogo de recursos.
            'resources.view' => 'Consultar recursos',
            'resources.manage' => 'Administrar recursos',
        ];

        foreach ($permissions as $code => $name) {
            Permission::updateOrCreate(
                ['code' => $code],
                ['name' => $name]
            );
        }

        $roles = [
            'employee' => [
                'name' => 'Empleado',
                'permissions' => [
                    'profile.view',
                    'access.request',
                    'resources.view',
                ],
            ],

            'resource_manager' => [
                'name' => 'Responsable de recurso',
                'permissions' => [
                    'profile.view',
                    'access.request',
                    'access.approve',
                    'access.revoke',
                    'resources.view',
                ],
            ],

            'iam_admin' => [
                'name' => 'Administrador IAM',
                'permissions' => [
                    'profile.view',
                    'access.request',

                    'departments.view',
                    'departments.create',
                    'departments.update',
                    'departments.deactivate',

                    'users.view',
                    'users.manage',

                    'audit.view',

                    'resources.view',
                    'resources.manage',
                ],
            ],
        ];

        foreach ($roles as $code => $data) {
            $role = Role::updateOrCreate(
                ['code' => $code],
                ['name' => $data['name']]
            );

            $permissionIds = Permission::whereIn(
                'code',
                $data['permissions']
            )->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
