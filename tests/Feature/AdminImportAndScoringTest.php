<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ScoringConfig;
use App\Models\User;
use Database\Seeders\DataSourceSeeder;
use Database\Seeders\ExchangeSeeder;
use Database\Seeders\MarketUniverseSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScoringConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminImportAndScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RoleSeeder::class,
            ExchangeSeeder::class,
            MarketUniverseSeeder::class,
            DataSourceSeeder::class,
            ScoringConfigSeeder::class,
        ]);
    }

    public function test_non_admin_cannot_access_imports_or_scoring(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/imports')->assertForbidden();
        $this->actingAs($user)->get('/admin/scoring')->assertForbidden();
        $this->actingAs($user)->patch('/admin/scoring', [
            'name' => 'Hack',
            'weights' => ['fundamentals' => 20, 'valuation' => 20, 'technical' => 20, 'risk' => 20, 'catalysts' => 20],
        ])->assertForbidden();
    }

    public function test_admin_can_upload_csv_and_update_scoring_weights(): void
    {
        $admin = User::factory()->create();
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        $csv = implode("\n", [
            'exchange_code,symbol,trade_date,open,high,low,close,volume',
            'IDX,BBCA,2026-11-26,9800,9950,9750,9900,12000000',
        ]);
        $file = UploadedFile::fake()->createWithContent('prices.csv', $csv);

        $this->actingAs($admin)
            ->post('/admin/imports/csv', [
                'dataset' => 'prices_daily',
                'file' => $file,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('data_import_runs', [
            'dataset' => 'prices_daily',
            'status' => 'completed',
            'rows_inserted' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'import.csv',
        ]);

        // Reject weights != 100
        $this->actingAs($admin)
            ->patch('/admin/scoring', [
                'name' => 'Bad Weights',
                'weights' => [
                    'fundamentals' => 50,
                    'valuation' => 20,
                    'technical' => 20,
                    'risk' => 15,
                    'catalysts' => 10,
                ],
            ])
            ->assertSessionHasErrors('weights');

        // Accept valid sum == 100
        $this->actingAs($admin)
            ->patch('/admin/scoring', [
                'name' => 'Value Tilt',
                'weights' => [
                    'fundamentals' => 25,
                    'valuation' => 35,
                    'technical' => 15,
                    'risk' => 15,
                    'catalysts' => 10,
                ],
            ])
            ->assertRedirect();

        $config = ScoringConfig::query()->where('code', 'default')->firstOrFail();
        $this->assertSame('Value Tilt', $config->name);
        $this->assertSame(35, $config->weights['valuation']);
        $this->assertSame('v1.1', $config->version);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'scoring_config.updated',
        ]);
    }
}
