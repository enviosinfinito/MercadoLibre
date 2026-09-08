<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AnalyticsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'analytics.view',
            'analytics.edit',
            'analytics.manage_templates',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name);
        }

        $owner = Role::findOrCreate('owner');
        $admin = Role::findOrCreate('admin');
        $member = Role::findOrCreate('member');
        $viewer = Role::findOrCreate('viewer');

        $owner->givePermissionTo($permissions);
        $admin->givePermissionTo(['analytics.view', 'analytics.edit']);
        $member->givePermissionTo(['analytics.view', 'analytics.edit']);
        $viewer->givePermissionTo(['analytics.view']);
    }
}
