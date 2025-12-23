<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Define all permissions by module
        $permissions = [
            'apps' => [
                ['name' => 'apps.view', 'display_name' => 'View Applications'],
                ['name' => 'apps.create', 'display_name' => 'Create Applications'],
                ['name' => 'apps.update', 'display_name' => 'Update Applications'],
                ['name' => 'apps.delete', 'display_name' => 'Delete Applications'],
            ],
            'payments' => [
                ['name' => 'payments.view', 'display_name' => 'View Payments'],
                ['name' => 'payments.create', 'display_name' => 'Create Payments'],
                ['name' => 'payments.refund', 'display_name' => 'Refund Payments'],
                ['name' => 'payments.export', 'display_name' => 'Export Payments'],
            ],
            'customers' => [
                ['name' => 'customers.view', 'display_name' => 'View Customers'],
                ['name' => 'customers.create', 'display_name' => 'Create Customers'],
                ['name' => 'customers.update', 'display_name' => 'Update Customers'],
                ['name' => 'customers.delete', 'display_name' => 'Delete Customers'],
            ],
            'payouts' => [
                ['name' => 'payouts.view', 'display_name' => 'View Payouts'],
                ['name' => 'payouts.create', 'display_name' => 'Create Payouts'],
                ['name' => 'payouts.approve', 'display_name' => 'Approve Payouts'],
            ],
            'reports' => [
                ['name' => 'reports.view', 'display_name' => 'View Reports'],
                ['name' => 'reports.export', 'display_name' => 'Export Reports'],
                ['name' => 'reports.create', 'display_name' => 'Create Reports'],
            ],
            'settings' => [
                ['name' => 'settings.view', 'display_name' => 'View Settings'],
                ['name' => 'settings.update', 'display_name' => 'Update Settings'],
                ['name' => 'settings.billing', 'display_name' => 'Manage Billing'],
            ],
            'developers' => [
                ['name' => 'developers.view', 'display_name' => 'View Developer Tools'],
                ['name' => 'developers.api_keys', 'display_name' => 'Manage API Keys'],
                ['name' => 'developers.webhooks', 'display_name' => 'Manage Webhooks'],
                ['name' => 'developers.logs', 'display_name' => 'View Logs'],
            ],
            'team' => [
                ['name' => 'team.view', 'display_name' => 'View Team Members'],
                ['name' => 'team.invite', 'display_name' => 'Invite Team Members'],
                ['name' => 'team.manage_roles', 'display_name' => 'Manage Roles'],
                ['name' => 'team.remove', 'display_name' => 'Remove Team Members'],
            ],
        ];

        // Create all permissions
        $permissionModels = [];
        foreach ($permissions as $module => $modulePermissions) {
            foreach ($modulePermissions as $perm) {
                $permissionModels[$perm['name']] = Permission::updateOrCreate(
                    ['name' => $perm['name']],
                    [
                        'id' => Str::uuid()->toString(),
                        'display_name' => $perm['display_name'],
                        'module' => $module,
                    ]
                );
            }
        }

        // Define roles with their permissions
        $roles = [
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Full access to all features and settings',
                'is_system' => true,
                'is_default' => false,
                'permissions' => array_keys($permissionModels), // All permissions
            ],
            [
                'name' => 'developer',
                'display_name' => 'Developer',
                'description' => 'Access to developer tools, API keys, and webhooks',
                'is_system' => true,
                'is_default' => false,
                'permissions' => [
                    'apps.view', 'apps.create', 'apps.update',
                    'payments.view',
                    'developers.view', 'developers.api_keys', 'developers.webhooks', 'developers.logs',
                ],
            ],
            [
                'name' => 'finance',
                'display_name' => 'Finance Manager',
                'description' => 'Access to payments, payouts, and reports',
                'is_system' => true,
                'is_default' => false,
                'permissions' => [
                    'payments.view', 'payments.refund', 'payments.export',
                    'payouts.view', 'payouts.create', 'payouts.approve',
                    'reports.view', 'reports.export', 'reports.create',
                    'settings.billing',
                ],
            ],
            [
                'name' => 'support',
                'display_name' => 'Support Agent',
                'description' => 'Access to customers and view payments',
                'is_system' => true,
                'is_default' => false,
                'permissions' => [
                    'customers.view', 'customers.update',
                    'payments.view',
                    'reports.view',
                ],
            ],
            [
                'name' => 'viewer',
                'display_name' => 'Viewer',
                'description' => 'Read-only access to view data',
                'is_system' => true,
                'is_default' => true, // Default role for new users
                'permissions' => [
                    'apps.view',
                    'payments.view',
                    'customers.view',
                    'payouts.view',
                    'reports.view',
                ],
            ],
        ];

        // Create roles and attach permissions
        foreach ($roles as $roleData) {
            $permissionNames = $roleData['permissions'];
            unset($roleData['permissions']);

            $role = Role::updateOrCreate(
                ['name' => $roleData['name']],
                array_merge(['id' => Str::uuid()->toString()], $roleData)
            );

            // Sync permissions with IDs for pivot table
            $permissionData = collect($permissionNames)
                ->mapWithKeys(function ($name) use ($permissionModels) {
                    $permissionId = $permissionModels[$name]->id;
                    // Generate a UUID for the pivot table record
                    $pivotId = Str::uuid()->toString();
                    
                    return [
                        $permissionId => [
                            'id' => $pivotId,
                            // You can add other pivot table fields here if needed
                            // 'created_at' => now(),
                            // 'updated_at' => now(),
                        ]
                    ];
                })
                ->toArray();

            $role->permissions()->sync($permissionData);
        }

        $this->command->info('Roles and permissions seeded successfully!');
    }
}