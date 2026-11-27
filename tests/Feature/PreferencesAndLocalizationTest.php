<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PreferencesAndLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_preferences_are_rejected(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/preferences', [
                'locale' => 'fr',
                'default_currency' => 'ZZZ',
            ])
            ->assertSessionHasErrors(['locale', 'default_currency']);
    }

    public function test_preferences_persist_and_switch_shared_translations(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/preferences', [
                'locale' => 'en',
                'appearance' => 'dark',
                'density' => 'compact',
                'analysis_mode' => 'pro',
                'default_currency' => 'USD',
                'default_market' => 'NASDAQ',
                'default_timeframe' => '6M',
                'timezone' => 'America/New_York',
            ])
            ->assertRedirect()
            ->assertCookie('appearance', 'dark');

        $settings = $user->fresh()->settings;
        $this->assertSame('en', $settings->locale);
        $this->assertSame('USD', $settings->default_currency);
        $this->assertSame('compact', $settings->density);

        $this->actingAs($user->fresh())
            ->get('/settings/preferences')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'en')
                ->where('translations', fn ($tr) => ($tr['nav.overview'] ?? null) === 'Overview')
            );
    }

    public function test_translation_files_have_identical_key_sets(): void
    {
        $idKeys = array_keys(json_decode((string) file_get_contents(lang_path('id.json')), true));
        $enKeys = array_keys(json_decode((string) file_get_contents(lang_path('en.json')), true));

        sort($idKeys);
        sort($enKeys);

        $this->assertSame($idKeys, $enKeys);
        $this->assertNotEmpty($idKeys);
    }
}
