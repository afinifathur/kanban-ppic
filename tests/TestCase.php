<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $testClass = static::class;
        // Bypass gates/permissions in tests, except for RBAC/Scope security tests
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) use ($testClass) {
            if (str_contains($testClass, 'RbacScope')) {
                return null;
            }

            return true;
        });

        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            // Clear cached permissions
            $this->app[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            try {
                if (\Schema::hasTable('permissions')) {
                    \Spatie\Permission\Models\Permission::findOrCreate('access_planning');
                    \Spatie\Permission\Models\Permission::findOrCreate('access_execution');
                }
                if (\Schema::hasTable('roles')) {
                    \Spatie\Permission\Models\Role::findOrCreate('admin');
                    \Spatie\Permission\Models\Role::findOrCreate('ppic');
                    \Spatie\Permission\Models\Role::findOrCreate('spv');
                }
            } catch (\Exception $e) {
                // Ignore errors if DB is not ready/migrated yet
            }
        }
    }
}
