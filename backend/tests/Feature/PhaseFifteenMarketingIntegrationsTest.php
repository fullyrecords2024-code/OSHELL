<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MarketingPlatformConnection;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseFifteenMarketingIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config(['services.frontend_url' => 'https://oshel.example']);
    }

    public function test_oauth_connection_uses_one_time_state_and_stores_tokens_encrypted_without_exposing_them(): void
    {
        [$organization, $branch] = $this->organization();
        $director = $this->staff($organization, $branch, 'Managing Director');
        config([
            'services.marketing.platforms.meta.client_id' => 'meta-client',
            'services.marketing.platforms.meta.client_secret' => 'meta-secret',
        ]);
        Cache::flush();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'access_token' => 'sensitive-provider-token',
                'expires_in' => 3600,
                'scope' => 'pages_show_list instagram_basic',
            ]),
        ]);

        $authorization = $this->actingAs($director)
            ->postJson('/api/v1/marketing/integrations/meta/authorize', ['branch_id' => $branch->id])
            ->assertOk()
            ->json('authorization_url');
        parse_str((string) parse_url($authorization, PHP_URL_QUERY), $query);

        $callback = $this->get('/api/v1/marketing/integrations/meta/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'single-use-code',
        ]));
        $callback->assertRedirect('https://oshel.example/?marketing_integration=connected&platform=meta#staff');

        $connection = MarketingPlatformConnection::query()->firstOrFail();
        $this->assertSame('sensitive-provider-token', $connection->access_token);
        $this->assertNotSame(
            'sensitive-provider-token',
            DB::table('marketing_platform_connections')->value('access_token'),
        );
        $this->assertSame(['pages_show_list', 'instagram_basic'], $connection->scopes);
        $this->assertSame($branch->id, $connection->branch_id);

        $this->actingAs($director)
            ->getJson('/api/v1/marketing/integrations')
            ->assertOk()
            ->assertJsonPath('platforms.1.connection.status', 'connected')
            ->assertJsonPath('platforms.1.capabilities.campaign_import', 'manual_only')
            ->assertJsonPath('platforms.1.capabilities.campaign_metrics_sync', 'manual_only')
            ->assertJsonPath('platforms.1.capabilities.campaign_publish', 'manual_only')
            ->assertJsonPath('platforms.1.capabilities.content_publish', 'manual_only')
            ->assertJsonPath('platforms.1.capabilities.analytics_sync', 'manual_only')
            ->assertJsonMissing(['access_token' => 'sensitive-provider-token']);

        $this->get('/api/v1/marketing/integrations/meta/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'reused-code',
        ]))->assertRedirect('https://oshel.example/?marketing_integration=invalid_state&platform=meta#staff');
    }

    public function test_campaign_import_is_explicitly_manual_and_does_not_claim_provider_sync(): void
    {
        [$organization, $branch] = $this->organization();
        $manager = $this->staff($organization, $branch, 'Marketing Officer');

        $this->actingAs($manager)
            ->postJson('/api/v1/marketing/external-campaigns', [
                'branch_id' => $branch->id,
                'platform' => 'meta',
                'name' => 'Winter skincare campaign',
                'status' => 'externally_managed',
                'spend_amount' => 125.50,
                'impressions' => 3200,
                'clicks' => 90,
                'conversions' => 6,
            ])
            ->assertCreated()
            ->assertJsonPath('campaign.source', 'manual')
            ->assertJsonPath('campaign.status', 'externally_managed');

        $this->assertDatabaseHas('marketing_platform_sync_logs', [
            'platform' => 'meta',
            'operation' => 'manual_campaign_import',
            'status' => 'completed',
            'summary' => 'Campaign data entered manually; no external platform synchronization was performed.',
        ]);
    }

    public function test_campaign_metrics_can_be_updated_as_an_audited_manual_snapshot(): void
    {
        [$organization, $branch] = $this->organization();
        $manager = $this->staff($organization, $branch, 'Marketing Officer');

        $campaign = $this->actingAs($manager)
            ->postJson('/api/v1/marketing/external-campaigns', [
                'branch_id' => $branch->id,
                'platform' => 'meta',
                'name' => 'Winter skincare campaign',
                'status' => 'externally_managed',
                'spend_amount' => 125.50,
                'impressions' => 3200,
            ])
            ->assertCreated()
            ->json('campaign');

        $response = $this->putJson('/api/v1/marketing/external-campaigns/'.$campaign['id'].'/metrics', [
            'spend_amount' => 180.25,
            'impressions' => 4700,
            'clicks' => 135,
            'conversions' => 9,
        ])->assertOk()
            ->assertJsonPath('campaign.source', 'manual')
            ->assertJsonPath('campaign.spend_amount', 180.25)
            ->assertJsonPath('campaign.impressions', 4700)
            ->assertJsonPath('campaign.clicks', 135)
            ->assertJsonPath('campaign.conversions', 9);

        $this->assertNotNull($response->json('campaign.metrics_recorded_at'));
        $this->assertDatabaseHas('marketing_platform_sync_logs', [
            'platform' => 'meta',
            'operation' => 'manual_metrics_update',
            'status' => 'completed',
            'summary' => 'Campaign metrics updated manually; no external platform synchronization was performed.',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'marketing.external-campaign.metrics_updated',
            'subject_id' => (string) $campaign['id'],
        ]);
    }

    public function test_campaign_metrics_update_requires_metrics_and_respects_branch_scope(): void
    {
        [$organization, $branch] = $this->organization();
        $manager = $this->staff($organization, $branch, 'Marketing Officer');
        $otherOrganization = Organization::create([
            'name' => 'Other Clinic',
            'slug' => 'other-clinic',
        ]);
        $otherBranch = Branch::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other branch',
            'code' => 'OTH',
        ]);
        $otherManager = $this->staff($otherOrganization, $otherBranch, 'Marketing Officer');
        $campaign = $this->actingAs($manager)
            ->postJson('/api/v1/marketing/external-campaigns', [
                'branch_id' => $branch->id,
                'platform' => 'meta',
                'name' => 'Local campaign',
                'status' => 'externally_managed',
            ])
            ->assertCreated()
            ->json('campaign');

        $this->putJson('/api/v1/marketing/external-campaigns/'.$campaign['id'].'/metrics', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metrics');

        $this->actingAs($otherManager)
            ->putJson('/api/v1/marketing/external-campaigns/'.$campaign['id'].'/metrics', ['clicks' => 10])
            ->assertNotFound();
    }

    public function test_social_content_requires_separate_approval_and_records_manual_publication_evidence(): void
    {
        [$organization, $branch] = $this->organization();
        $creator = $this->staff($organization, $branch, 'Marketing Officer');
        $director = $this->staff($organization, $branch, 'Managing Director');

        $post = $this->actingAs($creator)
            ->postJson('/api/v1/marketing/social-posts', [
                'branch_id' => $branch->id,
                'title' => 'A calm evening skincare routine',
                'caption' => 'Keep your evening routine gentle and consistent.',
                'channels' => ['instagram', 'facebook'],
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->json();

        $this->postJson('/api/v1/marketing/social-posts/'.$post['id'].'/record-publication', [
            'external_url' => 'https://www.instagram.com/p/example/',
        ])->assertStatus(409);

        $this->postJson('/api/v1/marketing/social-posts/'.$post['id'].'/submit')
            ->assertOk()
            ->assertJsonPath('status', 'pending_approval');

        $this->actingAs($director)
            ->postJson('/api/v1/marketing/social-posts/'.$post['id'].'/review', [
                'decision' => 'approve',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->actingAs($creator)
            ->postJson('/api/v1/marketing/social-posts/'.$post['id'].'/record-publication', [
                'external_url' => 'https://www.instagram.com/p/example/',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'published_externally')
            ->assertJsonPath('external_url', 'https://www.instagram.com/p/example/');
    }

    public function test_seo_audits_keywords_and_branch_settings_are_scoped_and_manual(): void
    {
        [$organization, $branch] = $this->organization();
        $specialist = $this->staff($organization, $branch, 'Marketing Officer');
        DB::table('website_pages')->insert([
            'organization_id' => $organization->id,
            'slug' => 'skin-care-guide',
            'page_type' => 'page',
            'title' => 'Skin care guide',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($specialist)
            ->getJson('/api/v1/marketing/seo')
            ->assertOk()
            ->assertJsonPath('summary.pages_needing_attention', 1)
            ->assertJsonPath('keyword_source', 'manual')
            ->assertJsonPath('keyword_notice', 'Ranking values are manual observations. Google Search Console synchronization is not enabled.');

        $this->postJson('/api/v1/marketing/seo/keywords', [
            'branch_id' => $branch->id,
            'keyword' => 'facial treatment Nairobi',
            'target_path' => '/services',
            'manual_position' => 14,
            'position_recorded_on' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('source', 'manual')
            ->assertJsonPath('manual_position', 14);

        $this->putJson('/api/v1/marketing/seo/branches/'.$branch->id, [
            'page_title' => 'Oshel Skin Care Nairobi',
            'meta_description' => 'Thoughtful skincare and beauty services in Nairobi.',
            'target_keywords' => ['skin care Nairobi', 'facial Nairobi'],
        ])->assertOk()
            ->assertJsonPath('branch_id', $branch->id)
            ->assertJsonCount(2, 'target_keywords');

        $this->postJson('/api/v1/marketing/seo/keywords', [
            'branch_id' => $branch->id,
            'keyword' => 'unsafe target',
            'target_path' => 'https://example.com/',
        ])->assertStatus(422);
    }

    /**
     * @return array{Organization, Branch}
     */
    private function organization(): array
    {
        $organization = Organization::create([
            'name' => 'Marketing Clinic',
            'slug' => 'marketing-clinic',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'MKT',
        ]);

        return [$organization, $branch];
    }

    private function staff(Organization $organization, Branch $branch, string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
