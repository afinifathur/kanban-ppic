<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class SpvGudangJadiKanbanVisibilitySeeder extends Seeder
{
    /**
     * Run the database seeds idempotently.
     * Provisions dedicated 'access_all_sand_casting_kanban' permission to SPV Gudang Jadi.
     */
    public function run(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'access_all_sand_casting_kanban',
            'guard_name' => 'web',
        ]);

        $user = User::where('email', 'spvgdfl@peroniks.com')->first();

        if ($user && ! $user->hasPermissionTo('access_all_sand_casting_kanban')) {
            $user->givePermissionTo($permission);
        }
    }
}
