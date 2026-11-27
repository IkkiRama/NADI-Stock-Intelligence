<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('NADI_ADMIN_EMAIL');
        $password = env('NADI_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            if (! app()->environment('local', 'testing')) {
                $this->command?->warn('Skipping AdminUserSeeder: set NADI_ADMIN_EMAIL and NADI_ADMIN_PASSWORD in non-local environments.');

                return;
            }

            $email = $email ?: 'admin@nadi.test';
            $password = $password ?: 'password';
            $this->command?->warn("Using local fallback admin credentials ({$email} / password). Change in production.");
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'NADI Administrator',
                'password' => Hash::make($password),
                'email_verified_at' => now('UTC'),
                'is_active' => true,
            ],
        );

        $adminRole = Role::query()->where('slug', 'admin')->first();
        if ($adminRole) {
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        }
    }
}
