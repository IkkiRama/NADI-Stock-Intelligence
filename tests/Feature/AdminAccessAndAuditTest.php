<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, DataSourceSeeder::class, FeatureFlagSeeder::class]);
    }

    public function test_non_admin_receives_403_on_admin_routes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->patch("/admin/users/{$other->id}", [
            'is_active' => false,
            'roles' => ['user'],
        ])->assertForbidden();
    }

    public function test_admin_can_update_user_and_writes_audit_log_and_cannot_self_demote(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $target = User::factory()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->actingAs($admin)
            ->patch("/admin/users/{$target->id}", [
                'is_active' => true,
                'roles' => ['user', 'editor'],
            ])
            ->assertRedirect();

        $this->assertTrue($target->fresh()->hasRole('editor'));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'user.updated',
            'auditable_id' => $target->id,
        ]);

        // Self-demotion and self-deactivation must fail validation
        $this->actingAs($admin)
            ->patch("/admin/users/{$admin->id}", [
                'is_active' => true,
                'roles' => ['user'],
            ])
            ->assertSessionHasErrors('roles');

        $this->actingAs($admin)
            ->patch("/admin/users/{$admin->id}", [
                'is_active' => false,
                'roles' => ['admin'],
            ])
            ->assertSessionHasErrors('is_active');
    }

    public function test_inactive_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
