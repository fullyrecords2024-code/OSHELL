<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebsitePage;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseFourteenWebsiteSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config(['services.public_site_url' => 'https://oshel.example']);
    }

    public function test_storefront_exposes_the_organization_currency_for_public_product_metadata(): void
    {
        [$organization] = $this->organization();
        $organization->update(['currency_code' => 'USD']);

        $this->getJson('/api/v1/public/storefront?organization_slug='.$organization->slug)
            ->assertOk()
            ->assertJsonPath('organization.currency_code', 'USD');
    }

    public function test_sitemap_and_robots_include_public_organization_urls_and_published_content(): void
    {
        [$organization] = $this->organization();
        WebsitePage::query()->create([
            'organization_id' => $organization->id,
            'slug' => 'skin-guidance',
            'page_type' => 'page',
            'title' => 'Skin guidance',
            'status' => 'published',
            'published_at' => now('UTC'),
        ]);
        WebsitePage::query()->create([
            'organization_id' => $organization->id,
            'slug' => 'winter-routine',
            'page_type' => 'journal',
            'title' => 'A winter routine',
            'status' => 'published',
            'published_at' => now('UTC'),
        ]);
        WebsitePage::query()->create([
            'organization_id' => $organization->id,
            'slug' => 'draft-page',
            'page_type' => 'page',
            'title' => 'Draft',
            'status' => 'draft',
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee('https://oshel.example/website-clinic', false)
            ->assertSee('https://oshel.example/website-clinic/skin-guidance', false)
            ->assertSee('https://oshel.example/website-clinic/journal/winter-routine', false)
            ->assertDontSee('draft-page');
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://oshel.example/sitemap.xml');
    }

    public function test_analytics_events_are_recorded_only_after_analytics_consent_and_are_idempotent(): void
    {
        [$organization] = $this->organization();
        $anonymousId = (string) str()->uuid();
        $eventId = (string) str()->uuid();
        $event = [
            'organization_slug' => $organization->slug,
            'anonymous_id' => $anonymousId,
            'event_id' => $eventId,
            'event_name' => 'page_view',
            'page_path' => '/website-clinic/shop',
        ];

        $this->postJson('/api/v1/public/analytics/events', $event)->assertNoContent();
        $this->assertDatabaseCount('website_analytics_events', 0);

        $this->postJson('/api/v1/public/privacy-consent', [
            'organization_slug' => $organization->slug,
            'anonymous_id' => $anonymousId,
            'consent_version' => '1',
            'analytics_allowed' => true,
            'marketing_allowed' => false,
        ])->assertOk();
        $this->postJson('/api/v1/public/analytics/events', $event)->assertNoContent();
        $this->postJson('/api/v1/public/analytics/events', $event)->assertNoContent();
        $this->assertDatabaseCount('website_analytics_events', 1);

        DB::table('website_privacy_consents')
            ->where('organization_id', $organization->id)
            ->where('anonymous_id', $anonymousId)
            ->update(['analytics_allowed' => false]);
        $this->postJson('/api/v1/public/analytics/events', [
            ...$event,
            'event_id' => (string) str()->uuid(),
        ])->assertNoContent();
        $this->assertDatabaseCount('website_analytics_events', 1);
    }

    public function test_content_manager_can_upload_media_and_manage_scoped_internal_redirects(): void
    {
        Storage::fake('public');
        [$organization, $branch] = $this->organization();
        $editor = $this->staff($organization, $branch);
        $page = WebsitePage::query()->create([
            'organization_id' => $organization->id,
            'created_by' => $editor->id,
            'updated_by' => $editor->id,
            'slug' => 'skin-guidance',
            'page_type' => 'page',
            'title' => 'Skin guidance',
            'status' => 'draft',
        ]);

        $this->actingAs($editor)->post('/api/v1/website/pages/'.$page->id.'/media', [
            'image' => UploadedFile::fake()->image('cover.jpg'),
        ])->assertOk()->assertJsonPath('cover_image_url', fn (?string $url): bool => is_string($url) && str_contains($url, '/storage/website/'));
        $page->refresh();
        $this->assertNotNull($page->cover_image_path);
        Storage::disk('public')->assertExists($page->cover_image_path);

        $this->actingAs($editor)->postJson('/api/v1/website/redirects', [
            'source_path' => '/old-guide',
            'target_path' => '/skin-guidance',
            'status_code' => 301,
        ])->assertCreated()->assertJsonPath('redirect.source_path', '/old-guide');
        $this->actingAs($editor)->postJson('/api/v1/website/redirects', [
            'source_path' => '/skin-guidance',
            'target_path' => '/old-guide',
            'status_code' => 301,
        ])->assertUnprocessable();
        $this->getJson('/api/v1/public/seo/redirect?organization_slug='.$organization->slug.'&source_path=%2Fold-guide')
            ->assertOk()
            ->assertJsonPath('redirect.target_path', '/skin-guidance')
            ->assertJsonPath('redirect.status_code', 301);
        $this->get('/'.$organization->slug.'/old-guide')
            ->assertRedirect('https://oshel.example/'.$organization->slug.'/skin-guidance')
            ->assertStatus(301);

        $this->actingAs($editor)->getJson('/api/v1/website/redirects')
            ->assertOk()
            ->assertJsonCount(1, 'redirects');
        $this->actingAs($editor)->deleteJson('/api/v1/website/redirects/1')->assertNoContent();
    }

    public function test_published_journal_media_is_exposed_as_a_public_image_url(): void
    {
        Storage::fake('public');
        [$organization, $branch] = $this->organization();
        $editor = $this->staff($organization, $branch);
        $director = $this->staff($organization, $branch, 'Managing Director');
        $article = WebsitePage::query()->create([
            'organization_id' => $organization->id,
            'created_by' => $editor->id,
            'updated_by' => $editor->id,
            'slug' => 'winter-routine',
            'page_type' => 'journal',
            'title' => 'A winter routine',
            'status' => 'draft',
        ]);
        $this->actingAs($editor)->post('/api/v1/website/pages/'.$article->id.'/media', [
            'image' => UploadedFile::fake()->image('journal-cover.jpg'),
        ])->assertOk();
        $this->actingAs($director)->patchJson('/api/v1/website/pages/'.$article->id.'/publication', [
            'status' => 'published',
        ])->assertOk();

        $this->getJson('/api/v1/public/site-content?organization_slug='.$organization->slug.'&slug=winter-routine')
            ->assertOk()
            ->assertJsonPath('page.page_type', 'journal')
            ->assertJsonPath('page.title', 'A winter routine')
            ->assertJsonMissingPath('page.cover_image_path');
        $this->getJson('/api/v1/public/journal?organization_slug='.$organization->slug)
            ->assertOk()
            ->assertJsonPath('articles.0.slug', 'winter-routine')
            ->assertJsonPath('articles.0.cover_image_url', fn (?string $url): bool => is_string($url) && str_contains($url, '/storage/website/'));
    }

    /**
     * @return array{Organization, Branch}
     */
    private function organization(): array
    {
        $organization = Organization::create(['name' => 'Website Clinic', 'slug' => 'website-clinic']);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'WEB',
        ]);

        return [$organization, $branch];
    }

    private function staff(Organization $organization, Branch $branch, string $role = 'Marketing Officer'): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
