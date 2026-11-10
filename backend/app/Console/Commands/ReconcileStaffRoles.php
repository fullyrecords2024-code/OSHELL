<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\StaffRoleDefinitions;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class ReconcileStaffRoles extends Command
{
    protected $signature = 'oshel:reconcile-staff-roles
        {--apply : Apply the reconciliation (default is a dry run)}
        {--force : Required to apply when the application is in production}';

    protected $description = 'Reconcile the legacy role catalogue into the nine approved staff roles';

    /** @var array<int, array{action: string, detail: string}> */
    private array $changes = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to change access control in production without --force.');

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            foreach (AccessControlSeeder::PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $this->renameOrMergeLegacyRoles();
            $this->createMissingStaffRoles();
            $this->syncActiveRolePermissions();
            $this->retireRemainingRoles();

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            if ($apply) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Action', 'Detail'],
            array_map(static fn (array $change): array => [$change['action'], $change['detail']], $this->changes),
        );

        $this->info($apply
            ? 'Staff role reconciliation applied.'
            : 'Dry run only. Re-run with --apply to write these changes.');

        return self::SUCCESS;
    }

    private function renameOrMergeLegacyRoles(): void
    {
        foreach (StaffRoleDefinitions::LEGACY_ROLE_MAP as $legacyName => $targetName) {
            $legacy = Role::query()->where('name', $legacyName)->where('guard_name', 'web')->first();
            if ($legacy === null) {
                continue;
            }

            $target = Role::query()->where('name', $targetName)->where('guard_name', 'web')->first();

            if ($target === null) {
                $legacy->update(['name' => $targetName]);
                $this->changes[] = ['action' => 'rename', 'detail' => "{$legacyName} -> {$targetName}"];

                continue;
            }

            $moved = 0;
            foreach (User::role($legacyName)->get() as $user) {
                if (! $user->hasRole($targetName)) {
                    $user->assignRole($targetName);
                }
                $user->removeRole($legacyName);
                $moved++;
            }

            if ($moved > 0) {
                $this->changes[] = ['action' => 'merge', 'detail' => "{$legacyName} -> {$targetName} ({$moved} user(s))"];
            }
        }
    }

    private function createMissingStaffRoles(): void
    {
        foreach (StaffRoleDefinitions::staffRoleNames() as $name) {
            if (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                continue;
            }

            Role::findOrCreate($name, 'web');
            $this->changes[] = ['action' => 'create', 'detail' => $name];
        }

        foreach ([StaffRoleDefinitions::SUPER_ADMIN, StaffRoleDefinitions::CUSTOMER] as $name) {
            if (! Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                Role::findOrCreate($name, 'web');
                $this->changes[] = ['action' => 'create', 'detail' => $name];
            }
        }
    }

    private function syncActiveRolePermissions(): void
    {
        $allPermissions = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

        Role::findByName(StaffRoleDefinitions::SUPER_ADMIN, 'web')->syncPermissions($allPermissions);
        $this->changes[] = ['action' => 'sync', 'detail' => 'Super Admin (all permissions)'];

        foreach (StaffRoleDefinitions::staffRoleNames() as $roleName) {
            $permissions = StaffRoleDefinitions::permissionsForRole($roleName, $allPermissions);
            Role::findByName($roleName, 'web')->syncPermissions($permissions);
            $this->changes[] = ['action' => 'sync', 'detail' => "{$roleName} (".count($permissions).' permissions)'];
        }

        Role::findByName(StaffRoleDefinitions::CUSTOMER, 'web')
            ->syncPermissions(StaffRoleDefinitions::customerPermissions());
        $this->changes[] = ['action' => 'sync', 'detail' => 'Customer / Patient (self-service)'];
    }

    private function retireRemainingRoles(): void
    {
        $active = [...StaffRoleDefinitions::staffRoleNames(), StaffRoleDefinitions::SUPER_ADMIN, StaffRoleDefinitions::CUSTOMER];

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', $active)
            ->orderBy('name')
            ->get();

        foreach ($roles as $role) {
            if (StaffRoleDefinitions::isDeprecated($role->name)) {
                $role->syncPermissions([]);
                continue;
            }

            $assigned = User::role($role->name)->count();
            if ($assigned > 0) {
                throw new \RuntimeException(
                    "Role {$role->name} still has {$assigned} user(s) and no mapping. Reassign them before retrying."
                );
            }

            $role->syncPermissions([]);
            $deprecatedName = StaffRoleDefinitions::deprecatedName($role->name);

            if (Role::query()->where('name', $deprecatedName)->where('guard_name', 'web')->exists()) {
                $role->delete();
                $this->changes[] = ['action' => 'remove', 'detail' => "{$role->name} (duplicate deprecated role)"];

                continue;
            }

            $originalName = $role->name;
            $role->update(['name' => $deprecatedName]);
            $this->changes[] = ['action' => 'deprecate', 'detail' => "{$originalName} -> {$deprecatedName}"];
        }
    }
}
