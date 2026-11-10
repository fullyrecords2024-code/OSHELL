<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_content_manager_saves_copy_and_managing_director_publishes_it(): void
    {
        [$organization, $branch] = $this->organization();
        $editor = $this->staff('Marketing Officer', $organization, $branch);
        $director = $this->staff('Managing Director', $organization, $branch);
        $payload = [
            'slug' => 'home',
            'title' => 'Thoughtful skincare, every day',
            'eyebrow' => 'OSHEL SKIN CARE',
            'summary' => 'Explore considered care for your routine.',
            'body' => 'Visit our clinic for personalized support.',
            'seo_title' => 'Oshel Skin Care | Thoughtful care',
            'seo_description' => 'Shop skincare and explore services at Oshel.',
        ];

        $page = $this->actingAs($editor)->postJson('/api/v1/website/pages', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->json();
        $this->getJson('/api/v1/public/site-content?organization_slug='.$organization->slug.'&slug=home')
            ->assertOk()
            ->assertJsonPath('page', null);

        $this->actingAs($director)->patchJson('/api/v1/website/pages/'.$page['id'].'/publication', [
            'status' => 'published',
        ])->assertOk()->assertJsonPath('status', 'published');

        $this->getJson('/api/v1/public/site-content?organization_slug='.$organization->slug.'&slug=home')
            ->assertOk()
            ->assertJsonPath('page.title', $payload['title'])
            ->assertJsonPath('page.seo_title', $payload['seo_title'])
            ->assertJsonMissingPath('page.created_by')
            ->assertJsonMissingPath('page.organization_id');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'website.page.create',
            'subject_id' => (string) $page['id'],
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'website.page.published',
            'subject_id' => (string) $page['id'],
        ]);
    }

    public function test_publication_schedule_and_tenant_boundaries_are_enforced(): void
    {
        [$organization, $branch] = $this->organization();
        $editor = $this->staff('Marketing Officer', $organization, $branch);
        $director = $this->staff('Managing Director', $organization, $branch);
        $page = $this->actingAs($editor)->postJson('/api/v1/website/pages', [
            'slug' => 'about',
            'title' => 'About Oshel',
        ])->assertCreated()->json();
        $future = now('UTC')->addDay()->toISOString();

        $this->actingAs($director)->patchJson('/api/v1/website/pages/'.$page['id'].'/publication', [
            'status' => 'published',
            'published_at' => $future,
        ])->assertOk();
        $this->getJson('/api/v1/public/site-content?organization_slug='.$organization->slug.'&slug=about')
            ->assertOk()
            ->assertJsonPath('page', null);

        [$otherOrganization, $otherBranch] = $this->organization('Other clinic', 'other-clinic');
        $otherEditor = $this->staff('Marketing Officer', $otherOrganization, $otherBranch);
        $this->actingAs($otherEditor)->putJson('/api/v1/website/pages/'.$page['id'], [
            'slug' => 'about',
            'title' => 'Unauthorized edit',
        ])->assertNotFound();
        $this->getJson('/api/v1/public/site-content?organization_slug='.$otherOrganization->slug.'&slug=about')
            ->assertOk()
            ->assertJsonPath('page', null);
    }

    public function test_content_creator_can_edit_but_cannot_publish_and_unpermitted_roles_cannot_view(): void
    {
        [$organization, $branch] = $this->organization();
        $creator = $this->staff('Marketing Officer', $organization, $branch);
        $page = $this->actingAs($creator)->postJson('/api/v1/website/pages', [
            'slug' => 'journal',
            'title' => 'Journal',
        ])->assertCreated()->json();
        $this->actingAs($creator)->patchJson('/api/v1/website/pages/'.$page['id'].'/publication', [
            'status' => 'published',
        ])->assertForbidden();

        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branch);
        $this->actingAs($receptionist)->getJson('/api/v1/website/pages')->assertForbidden();
    }

    /**
     * @return array{Organization, Branch}
     */
    private function organization(string $name = 'Website Clinic', string $slug = 'website-clinic'): array
    {
        $organization = Organization::create(['name' => $name, 'slug' => $slug]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => strtoupper(substr($slug, 0, 4)),
        ]);

        return [$organization, $branch];
    }

    private function staff(string $role, Organization $organization, Branch $branch): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
