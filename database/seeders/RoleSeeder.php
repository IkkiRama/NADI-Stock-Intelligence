<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['slug' => 'admin', 'name' => 'Administrator', 'description' => 'Full platform administration access'],
            ['slug' => 'editor', 'name' => 'Editor', 'description' => 'Editorial newsroom and research curator'],
            ['slug' => 'user', 'name' => 'Investor', 'description' => 'Standard NADI platform user'],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
