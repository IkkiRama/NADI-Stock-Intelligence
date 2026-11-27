<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\MarketUniverseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->seed([
            RoleSeeder::class,
            ExchangeSeeder::class,
            MarketUniverseSeeder::class,
            DataSourceSeeder::class,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('demoMode', true)
                ->has('indexCards', 8)
                ->has('dashboardLayout')
            );
    }

    public function test_user_can_update_dashboard_layout(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/dashboard/layout', [
                'widgets' => [
                    ['id' => 'market_clocks', 'visible' => true],
                    ['id' => 'market_indices', 'visible' => false],
                ],
            ])
            ->assertRedirect();

        $layout = $user->fresh()->settings->dashboard_layout;
        $this->assertSame('market_clocks', $layout[0]['id']);
        $this->assertFalse($layout[1]['visible']);
    }
}
