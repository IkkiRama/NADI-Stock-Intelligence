<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\NewsArticle;
use App\Models\ResearchNote;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase8ModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_dividends_newsroom_research_dashboard_stock_detail_and_global_search_work(): void
    {
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();

        $this->actingAs($admin)
            ->get('/dividends')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portfolio/dividends')
                ->has('dividends.holdings', 4)
                ->has('dividends.monthly_projected', 12)
                ->has('dividends.ledger_history')
            );

        $this->actingAs($admin)
            ->get('/newsroom')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('newsroom/index')
                ->where('canPublish', true)
                ->has('articles.data', 12)
            );

        $this->actingAs($admin)
            ->get('/newsroom?scope=portfolio')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('newsroom/index'));

        $this->actingAs($admin)
            ->get('/research-center')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('research/index')
                ->has('notes', 3)
            );

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('curatedNews', 5)
            );

        $this->actingAs($admin)
            ->get('/stocks/idx/bbca')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stocks/show')
                ->has('relatedNews')
                ->has('researchNotes')
            );

        $searchRes = $this->actingAs($admin)
            ->getJson('/search?q=BBCA')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($searchRes['articles']);
        $this->assertNotEmpty($searchRes['research']);
    }

    public function test_research_note_crud_and_news_publish_enforce_authorization_and_isolation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $admin = User::query()->where('email', 'admin@nadi.test')->firstOrFail();
        $bbca = Instrument::query()->where('symbol', 'BBCA')->firstOrFail();

        // User A creates a private research note
        $this->actingAs($userA)
            ->post('/research-center/notes', [
                'instrument_id' => $bbca->id,
                'title' => 'User A Private BBCA Thesis',
                'template_type' => 'thesis',
                'conviction' => 'high',
                'stance' => 'accumulate',
                'target_price' => 11500,
                'horizon' => 'long_term',
                'thesis_body' => 'Detailed private thesis notes for User A.',
                'tags_text' => 'bank, core',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'research-note-created');

        $note = ResearchNote::query()->where('user_id', $userA->id)->firstOrFail();
        $this->assertSame(['bank', 'core'], $note->tags);

        // User B cannot see User A's note in Global Search, and gets 403 on update/pin/delete
        $searchB = $this->actingAs($userB)->getJson('/search?q=Private+BBCA+Thesis')->assertOk()->json();
        $this->assertEmpty($searchB['research']);

        $this->actingAs($userB)
            ->put("/research-center/notes/{$note->id}", [
                'title' => 'Hijack',
                'template_type' => 'thesis',
                'conviction' => 'low',
                'stance' => 'hold',
                'thesis_body' => 'Attempted hijack',
            ])
            ->assertForbidden();

        $this->actingAs($userB)->patch("/research-center/notes/{$note->id}/pin")->assertForbidden();
        $this->actingAs($userB)->delete("/research-center/notes/{$note->id}")->assertForbidden();

        // User A can pin, edit, and delete own note
        $this->actingAs($userA)->patch("/research-center/notes/{$note->id}/pin")->assertRedirect();
        $this->assertTrue($note->fresh()->is_pinned);

        $this->actingAs($userA)
            ->put("/research-center/notes/{$note->id}", [
                'instrument_id' => $bbca->id,
                'title' => 'User A Updated BBCA Thesis',
                'template_type' => 'valuation',
                'conviction' => 'high',
                'stance' => 'accumulate',
                'target_price' => 12000,
                'thesis_body' => 'Updated valuation targets.',
            ])
            ->assertRedirect();
        $this->assertSame('User A Updated BBCA Thesis', $note->fresh()->title);

        $this->actingAs($userA)->delete("/research-center/notes/{$note->id}")->assertRedirect();
        $this->assertDatabaseMissing('research_notes', ['id' => $note->id]);

        // Non-admin user cannot publish editorial news; admin can
        $this->actingAs($userA)
            ->post('/newsroom/articles', [
                'title' => 'Unauthorized Brief',
                'summary' => 'Should fail authorization.',
                'source_name' => 'Test',
                'category' => 'macro',
                'impact_tag' => 'neutral',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post('/newsroom/articles', [
                'title' => 'IDX Bank Liquidity Update',
                'summary' => 'Official curated macro briefing for portfolio investors.',
                'source_name' => 'NADI Editorial',
                'category' => 'macro',
                'country_code' => 'ID',
                'impact_tag' => 'positive',
                'instrument_ids' => [$bbca->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'news-article-published');

        $published = NewsArticle::query()->where('title', 'IDX Bank Liquidity Update')->firstOrFail();
        $this->assertFalse($published->is_demo);
    }
}
