<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\MarketingPlatformConnection;
use App\Models\User;
use App\Support\Authorization\FoundationScope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MarketingIntegrationsController extends Controller
{
    private const CHANNELS = ['facebook', 'instagram', 'tiktok', 'whatsapp'];

    private const EXTERNAL_CAMPAIGN_STATUSES = [
        'externally_managed',
        'paused',
        'ended',
        'sync_pending',
        'sync_failed',
    ];

    public function integrations(Request $request, FoundationScope $scope): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $connections = MarketingPlatformConnection::query()
            ->where('organization_id', $organizationId)
            ->when(
                ! $request->user()->can('branch.view_all'),
                fn (EloquentBuilder $query) => $query->where(function (EloquentBuilder $branches) use ($scope, $request): void {
                    $branches->whereNull('branch_id')->orWhereIn('branch_id', $scope->branchIds($request->user()));
                }),
            )
            ->get([
                'id',
                'branch_id',
                'platform',
                'external_account_id',
                'external_account_name',
                'status',
                'scopes',
                'token_expires_at',
                'connected_at',
            ])
            ->keyBy('platform');

        $platforms = [];
        foreach (array_keys((array) config('services.marketing.platforms', [])) as $platform) {
            $provider = config("services.marketing.platforms.{$platform}");
            $configured = filled($provider['client_id'] ?? null)
                && filled($provider['client_secret'] ?? null)
                && filled($provider['authorize_url'] ?? null)
                && filled($provider['token_url'] ?? null)
                && ($provider['authorization_supported'] ?? false);
            $connection = $connections->get($platform);
            $connectionStatus = $connection?->status;
            if ($connection?->token_expires_at?->isPast() && $connectionStatus === 'connected') {
                $connectionStatus = 'reconnect_required';
            }

            $platforms[] = [
                'platform' => $platform,
                'name' => $provider['name'] ?? Str::headline($platform),
                'authorization' => $configured ? 'available' : 'manual_only',
                'authorization_reason' => $configured
                    ? null
                    : 'Provider OAuth credentials are not configured on the server.',
                'connection' => $connection === null ? null : [
                    'id' => $connection->id,
                    'branch_id' => $connection->branch_id,
                    'external_account_id' => $connection->external_account_id,
                    'external_account_name' => $connection->external_account_name,
                    'status' => $connectionStatus,
                    'scopes' => $connection->scopes ?? [],
                    'token_expires_at' => $connection->token_expires_at,
                    'connected_at' => $connection->connected_at,
                ],
                'capabilities' => [
                    'campaign_import' => 'manual_only',
                    'campaign_metrics_sync' => 'manual_only',
                    'campaign_publish' => 'manual_only',
                    'content_publish' => 'manual_only',
                    'analytics_sync' => 'manual_only',
                    'reason' => 'Provider API access and publishing adapters are not enabled; manual workflows are available.',
                ],
            ];
        }

        return response()->json([
            'platforms' => $platforms,
            'sync_logs' => $this->syncLogsQuery($request, $scope)->limit(50)->get(),
        ]);
    }

    public function authorizeProvider(Request $request, string $platform, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
        ]);
        $provider = config("services.marketing.platforms.{$platform}");
        abort_if(! is_array($provider), 404, 'Marketing platform not found.');
        abort_unless(
            filled($provider['client_id'] ?? null)
                && filled($provider['client_secret'] ?? null)
                && filled($provider['authorize_url'] ?? null)
                && filled($provider['token_url'] ?? null)
                && ($provider['authorization_supported'] ?? false),
            422,
            'OAuth is unavailable until provider credentials and settings are configured.',
        );

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $this->assertBranchScope($request, $branchId, $scope);
        $state = Str::random(64);
        $callbackUrl = route('marketing.integrations.oauth.callback', ['platform' => $platform]);
        Cache::put($this->oauthStateKey($state), [
            'platform' => $platform,
            'organization_id' => (int) $request->user()->organization_id,
            'user_id' => (int) $request->user()->id,
            'branch_id' => $branchId,
            'callback_url' => $callbackUrl,
        ], now()->addMinutes(10));

        $query = array_merge([
            'client_id' => $provider['client_id'],
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'scope' => implode(' ', $provider['scopes'] ?? []),
            'state' => $state,
        ], $provider['authorize_params'] ?? []);

        if (isset($provider['authorize_client_id_field'])) {
            $query[$provider['authorize_client_id_field']] = $query['client_id'];
            unset($query['client_id']);
        }

        return response()->json([
            'authorization_url' => $provider['authorize_url'].'?'.http_build_query($query),
        ]);
    }

    public function oauthCallback(Request $request, string $platform): RedirectResponse
    {
        $state = $request->string('state')->toString();
        $stateData = $state !== '' ? Cache::pull($this->oauthStateKey($state)) : null;
        if (! is_array($stateData) || ($stateData['platform'] ?? null) !== $platform) {
            return $this->frontendIntegrationResult($platform, 'invalid_state');
        }

        $user = User::query()
            ->whereKey($stateData['user_id'])
            ->where('organization_id', $stateData['organization_id'])
            ->where('is_active', true)
            ->first();
        if ($user === null) {
            return $this->frontendIntegrationResult($platform, 'account_unavailable');
        }

        $provider = config("services.marketing.platforms.{$platform}");
        $startedAt = now('UTC');
        if ($request->filled('error')) {
            $this->writeSyncLog(
                (int) $stateData['organization_id'],
                isset($stateData['branch_id']) ? (int) $stateData['branch_id'] : null,
                null,
                (int) $user->id,
                $platform,
                'oauth_authorization',
                'failed',
                'provider_authorization_denied',
                'Provider authorization was not completed.',
                $startedAt,
            );

            return $this->frontendIntegrationResult($platform, 'authorization_denied');
        }

        $code = $request->string('code')->toString();
        if ($code === '' || ! is_array($provider)) {
            return $this->frontendIntegrationResult($platform, 'invalid_callback');
        }

        $tokenPayload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $stateData['callback_url'],
        ];
        $clientIdField = $provider['token_client_id_field'] ?? 'client_id';
        $tokenPayload[$clientIdField] = $provider['client_id'];
        $tokenPayload[$provider['token_client_secret_field'] ?? 'client_secret'] = $provider['client_secret'];

        try {
            $http = Http::acceptJson()->asForm()->timeout(20);
            $response = ($provider['token_method'] ?? 'post') === 'get'
                ? $http->get($provider['token_url'], $tokenPayload)
                : $http->post($provider['token_url'], $tokenPayload);
        } catch (ConnectionException) {
            $this->writeSyncLog(
                (int) $stateData['organization_id'],
                isset($stateData['branch_id']) ? (int) $stateData['branch_id'] : null,
                null,
                (int) $user->id,
                $platform,
                'oauth_token_exchange',
                'failed',
                'provider_unreachable',
                'The provider could not be reached. Retry authorization later.',
                $startedAt,
            );

            return $this->frontendIntegrationResult($platform, 'provider_unreachable');
        }

        $body = $response->json();
        $accessToken = is_array($body)
            ? data_get($body, $provider['token_access_path'] ?? 'access_token')
            : null;
        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            $errorCode = 'token_exchange_failed';
            if (is_array($body) && is_string(data_get($body, 'error'))) {
                $errorCode = Str::limit((string) data_get($body, 'error'), 100, '');
            }
            $this->writeSyncLog(
                (int) $stateData['organization_id'],
                isset($stateData['branch_id']) ? (int) $stateData['branch_id'] : null,
                null,
                (int) $user->id,
                $platform,
                'oauth_token_exchange',
                'failed',
                $errorCode,
                'Provider token exchange failed. No credentials were stored.',
                $startedAt,
            );

            return $this->frontendIntegrationResult($platform, 'token_exchange_failed');
        }

        $expiresIn = is_array($body) ? data_get($body, $provider['token_expires_path'] ?? 'expires_in') : null;
        $refreshToken = is_array($body)
            ? data_get($body, $provider['token_refresh_path'] ?? 'refresh_token')
            : null;
        $externalAccountId = is_array($body)
            ? data_get($body, $provider['account_id_path'] ?? '')
            : null;
        $connection = MarketingPlatformConnection::query()->updateOrCreate(
            [
                'organization_id' => (int) $stateData['organization_id'],
                'platform' => $platform,
            ],
            [
                'branch_id' => $stateData['branch_id'] ?? null,
                'connected_by' => $user->id,
                'external_account_id' => is_string($externalAccountId) ? Str::limit($externalAccountId, 180, '') : null,
                'external_account_name' => null,
                'status' => 'connected',
                'scopes' => $this->grantedScopes($body, $provider['scopes'] ?? []),
                'access_token' => $accessToken,
                'refresh_token' => is_string($refreshToken) ? $refreshToken : null,
                'token_expires_at' => is_numeric($expiresIn) ? $startedAt->copy()->addSeconds((int) $expiresIn) : null,
                'connected_at' => $startedAt,
            ],
        );
        $this->writeSyncLog(
            (int) $stateData['organization_id'],
            isset($stateData['branch_id']) ? (int) $stateData['branch_id'] : null,
            (int) $connection->id,
            (int) $user->id,
            $platform,
            'oauth_authorization',
            'completed',
            null,
            'Provider authorization succeeded. Campaign and content publishing remain manual-only.',
            $startedAt,
        );
        $this->auditValues(
            (int) $user->id,
            (int) $stateData['organization_id'],
            isset($stateData['branch_id']) ? (int) $stateData['branch_id'] : null,
            'marketing.integration.connected',
            'marketing_platform_connection',
            (string) $connection->id,
            ['platform' => $platform],
        );

        return $this->frontendIntegrationResult($platform, 'connected');
    }

    public function disconnect(Request $request, int $connection, FoundationScope $scope): Response
    {
        $record = MarketingPlatformConnection::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail($connection);
        $this->assertBranchScope($request, $record->branch_id, $scope);
        $platform = $record->platform;
        $record->forceFill([
            'status' => 'disconnected',
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ])->save();
        $this->writeSyncLog(
            (int) $record->organization_id,
            $record->branch_id === null ? null : (int) $record->branch_id,
            (int) $record->id,
            (int) $request->user()->id,
            $platform,
            'disconnect',
            'completed',
            null,
            'Provider connection disconnected and stored tokens cleared.',
            now('UTC'),
        );
        $this->auditValues(
            (int) $request->user()->id,
            (int) $record->organization_id,
            $record->branch_id === null ? null : (int) $record->branch_id,
            'marketing.integration.disconnected',
            'marketing_platform_connection',
            (string) $record->id,
            ['platform' => $platform],
        );

        return response()->noContent();
    }

    public function externalCampaigns(Request $request, FoundationScope $scope): JsonResponse
    {
        $query = DB::table('marketing_external_campaigns')
            ->where('organization_id', $request->user()->organization_id);
        $this->applyBranchFilter($query, $request, $scope);

        return response()->json([
            'campaigns' => $query->orderByDesc('updated_at')->limit(100)->get(),
            'sync_logs' => $this->syncLogsQuery($request, $scope)
                ->whereIn('operation', ['manual_campaign_import', 'manual_metrics_update'])
                ->limit(50)->get(),
        ]);
    }

    public function importExternalCampaign(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'platform' => ['required', 'string', 'in:google,meta,tiktok,whatsapp'],
            'external_campaign_id' => ['nullable', 'string', 'max:180'],
            'name' => ['required', 'string', 'max:180'],
            'objective' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'string', 'in:'.implode(',', self::EXTERNAL_CAMPAIGN_STATUSES)],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'spend_amount' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'alpha'],
            'impressions' => ['nullable', 'integer', 'min:0'],
            'clicks' => ['nullable', 'integer', 'min:0'],
            'conversions' => ['nullable', 'integer', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $this->assertBranchScope($request, $branchId, $scope);
        $now = now('UTC');
        $campaign = DB::transaction(function () use ($data, $request, $branchId, $now): object {
            $campaignId = DB::table('marketing_external_campaigns')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'branch_id' => $branchId,
                'created_by' => $request->user()->id,
                'platform' => $data['platform'],
                'external_campaign_id' => $data['external_campaign_id'] ?? null,
                'name' => $data['name'],
                'objective' => $data['objective'] ?? null,
                'status' => $data['status'],
                'budget_amount' => $data['budget_amount'] ?? null,
                'spend_amount' => $data['spend_amount'] ?? null,
                'currency_code' => strtoupper($data['currency_code'] ?? 'KES'),
                'impressions' => $data['impressions'] ?? null,
                'clicks' => $data['clicks'] ?? null,
                'conversions' => $data['conversions'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'source' => 'manual',
                'metrics_recorded_at' => collect(['spend_amount', 'impressions', 'clicks', 'conversions'])
                    ->contains(fn (string $metric): bool => array_key_exists($metric, $data))
                    ? $now
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->writeSyncLog(
                (int) $request->user()->organization_id,
                $branchId,
                null,
                (int) $request->user()->id,
                $data['platform'],
                'manual_campaign_import',
                'completed',
                null,
                'Campaign data entered manually; no external platform synchronization was performed.',
                $now,
            );

            return DB::table('marketing_external_campaigns')->where('id', $campaignId)->first();
        });
        $this->auditValues(
            (int) $request->user()->id,
            (int) $request->user()->organization_id,
            $branchId,
            'marketing.external-campaign.imported',
            'marketing_external_campaign',
            (string) $campaign->id,
            ['platform' => $campaign->platform, 'source' => 'manual'],
        );

        return response()->json(['campaign' => $campaign], 201);
    }

    public function updateExternalCampaignMetrics(Request $request, int $campaign, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'spend_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'impressions' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'clicks' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'conversions' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        $metrics = array_intersect_key($data, array_flip([
            'spend_amount',
            'impressions',
            'clicks',
            'conversions',
        ]));
        if ($metrics === []) {
            throw ValidationException::withMessages([
                'metrics' => ['Provide at least one campaign metric to update.'],
            ]);
        }

        $record = DB::table('marketing_external_campaigns')
            ->where('organization_id', $request->user()->organization_id)
            ->find($campaign);
        abort_if($record === null, 404);
        $branchId = $record->branch_id === null ? null : (int) $record->branch_id;
        $this->assertBranchScope($request, $branchId, $scope);
        abort_unless($record->source === 'manual', 409, 'Only manually recorded campaign snapshots can be updated here.');

        $now = now('UTC');
        $campaign = DB::transaction(function () use ($metrics, $campaign, $now, $request, $branchId, $record): object {
            DB::table('marketing_external_campaigns')
                ->where('id', $campaign)
                ->update(array_merge($metrics, [
                    'metrics_recorded_at' => $now,
                    'updated_at' => $now,
                ]));
            $this->writeSyncLog(
                (int) $request->user()->organization_id,
                $branchId,
                null,
                (int) $request->user()->id,
                $record->platform,
                'manual_metrics_update',
                'completed',
                null,
                'Campaign metrics updated manually; no external platform synchronization was performed.',
                $now,
            );

            return DB::table('marketing_external_campaigns')->find($campaign);
        });
        $this->auditValues(
            (int) $request->user()->id,
            (int) $request->user()->organization_id,
            $branchId,
            'marketing.external-campaign.metrics_updated',
            'marketing_external_campaign',
            (string) $campaign->id,
            ['platform' => $campaign->platform, 'source' => 'manual'],
        );

        return response()->json(['campaign' => $campaign]);
    }

    public function socialPosts(Request $request, FoundationScope $scope): JsonResponse
    {
        $query = DB::table('marketing_social_posts')
            ->where('organization_id', $request->user()->organization_id);
        $this->applyBranchFilter($query, $request, $scope);

        return response()->json($query->orderByDesc('updated_at')->limit(100)->get());
    }

    public function createSocialPost(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'caption' => ['required', 'string', 'max:5000'],
            'channels' => ['required', 'array', 'min:1', 'max:4'],
            'channels.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::CHANNELS)],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $this->assertBranchScope($request, $branchId, $scope);
        $now = now('UTC');
        $postId = DB::table('marketing_social_posts')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'created_by' => $request->user()->id,
            'title' => $data['title'],
            'caption' => $data['caption'],
            'channels' => json_encode(array_values(array_unique($data['channels'])), JSON_THROW_ON_ERROR),
            'status' => 'draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->auditValues(
            (int) $request->user()->id,
            (int) $request->user()->organization_id,
            $branchId,
            'marketing.social-post.created',
            'marketing_social_post',
            (string) $postId,
            ['channels' => $data['channels'], 'status' => 'draft'],
        );

        return response()->json(DB::table('marketing_social_posts')->find($postId), 201);
    }

    public function submitSocialPost(Request $request, int $post, FoundationScope $scope): JsonResponse
    {
        $record = $this->findSocialPost($request, $post, $scope);
        abort_unless($record->status === 'draft' || $record->status === 'rejected', 409, 'Only a draft or rejected post can be submitted.');
        $now = now('UTC');
        DB::table('marketing_social_posts')->where('id', $record->id)->update([
            'status' => 'pending_approval',
            'submitted_by' => $request->user()->id,
            'submitted_at' => $now,
            'approved_by' => null,
            'approved_at' => null,
            'review_note' => null,
            'external_url' => null,
            'published_externally_at' => null,
            'updated_at' => $now,
        ]);
        $this->auditPost($request, 'marketing.social-post.submitted', $record);

        return response()->json(DB::table('marketing_social_posts')->find($record->id));
    }

    public function reviewSocialPost(Request $request, int $post, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $record = $this->findSocialPost($request, $post, $scope);
        abort_unless($record->status === 'pending_approval', 409, 'Only a submitted post can be reviewed.');
        abort_if(
            $data['decision'] === 'approve' && (int) $record->submitted_by === (int) $request->user()->id,
            422,
            'A different authorized user must approve a submitted post.',
        );
        $now = now('UTC');
        $action = $data['decision'] === 'approve' ? 'approved' : 'rejected';
        DB::table('marketing_social_posts')->where('id', $record->id)->update([
            'status' => $action,
            'approved_by' => $data['decision'] === 'approve' ? $request->user()->id : null,
            'approved_at' => $data['decision'] === 'approve' ? $now : null,
            'review_note' => $data['review_note'] ?? null,
            'updated_at' => $now,
        ]);
        $this->auditPost($request, 'marketing.social-post.'.$action, $record);

        return response()->json(DB::table('marketing_social_posts')->find($record->id));
    }

    public function recordExternalPublication(Request $request, int $post, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'external_url' => ['required', 'url', 'max:500'],
        ]);
        $record = $this->findSocialPost($request, $post, $scope);
        abort_unless($record->status === 'approved', 409, 'Only an approved post can be recorded as published externally.');
        abort_unless(
            in_array(strtolower((string) parse_url($data['external_url'], PHP_URL_SCHEME)), ['https'], true),
            422,
            'The external publication URL must use HTTPS.',
        );
        $now = now('UTC');
        DB::table('marketing_social_posts')->where('id', $record->id)->update([
            'status' => 'published_externally',
            'external_url' => $data['external_url'],
            'published_externally_at' => $now,
            'updated_at' => $now,
        ]);
        $this->auditPost($request, 'marketing.social-post.recorded_external_publication', $record);

        return response()->json(DB::table('marketing_social_posts')->find($record->id));
    }

    public function seoDashboard(Request $request, FoundationScope $scope): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $pages = DB::table('website_pages')->where('organization_id', $organizationId)
            ->orderBy('slug')
            ->get(['id', 'slug', 'page_type', 'title', 'seo_title', 'seo_description', 'status', 'updated_at']);
        $audits = $pages->map(function (object $page): array {
            $issues = [];
            $title = trim((string) ($page->seo_title ?: $page->title));
            $description = trim((string) ($page->seo_description ?? ''));
            if ($title === '') {
                $issues[] = ['code' => 'missing_title', 'severity' => 'high', 'message' => 'Add a page title.'];
            } elseif (mb_strlen($title) > 60) {
                $issues[] = ['code' => 'title_too_long', 'severity' => 'warning', 'message' => 'Keep the search title to about 60 characters.'];
            }
            if ($description === '') {
                $issues[] = ['code' => 'missing_description', 'severity' => 'warning', 'message' => 'Add a search description.'];
            } elseif (mb_strlen($description) > 160) {
                $issues[] = ['code' => 'description_too_long', 'severity' => 'warning', 'message' => 'Keep the search description within 160 characters.'];
            }

            return [
                'id' => $page->id,
                'slug' => $page->slug,
                'page_type' => $page->page_type,
                'title' => $page->title,
                'status' => $page->status,
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'updated_at' => $page->updated_at,
                'issues' => $issues,
                'audit_status' => $issues === [] ? 'pass' : 'needs_attention',
            ];
        });

        $branchQuery = $scope->branches($request->user())->where('is_active', true);
        $branches = $branchQuery->orderBy('name')->get(['id', 'name', 'city']);
        $branchIds = $branches->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $branchSettings = DB::table('marketing_seo_branch_settings')
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', $branchIds)
            ->get();
        $keywordQuery = DB::table('marketing_seo_keywords')->where('organization_id', $organizationId);
        $this->applyBranchFilter($keywordQuery, $request, $scope);

        return response()->json([
            'summary' => [
                'pages_audited' => $audits->count(),
                'pages_needing_attention' => $audits->where('audit_status', 'needs_attention')->count(),
                'tracked_keywords' => $keywordQuery->count(),
                'branch_profiles_configured' => $branchSettings->count(),
            ],
            'pages' => $audits,
            'branches' => $branches->map(function (Branch $branch) use ($branchSettings): array {
                $settings = $branchSettings->firstWhere('branch_id', $branch->id);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'city' => $branch->city,
                    'seo' => $settings === null ? null : [
                        'page_title' => $settings->page_title,
                        'meta_description' => $settings->meta_description,
                        'target_keywords' => json_decode($settings->target_keywords ?? '[]', true) ?: [],
                    ],
                ];
            }),
            'keywords' => $keywordQuery->orderBy('branch_id')->orderBy('keyword')->limit(200)->get(),
            'keyword_source' => 'manual',
            'keyword_notice' => 'Ranking values are manual observations. Google Search Console synchronization is not enabled.',
        ]);
    }

    public function saveSeoKeyword(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'keyword' => ['required', 'string', 'max:180'],
            'target_path' => ['required', 'string', 'max:500'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high'],
            'manual_position' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'position_recorded_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
        $this->assertInternalPath($data['target_path']);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $this->assertBranchScope($request, $branchId, $scope);
        $existing = DB::table('marketing_seo_keywords')
            ->where('organization_id', $request->user()->organization_id)
            ->where('keyword', $data['keyword'])
            ->where(function (QueryBuilder $query) use ($branchId): void {
                $branchId === null
                    ? $query->whereNull('branch_id')
                    : $query->where('branch_id', $branchId);
            })
            ->exists();
        if ($existing) {
            throw ValidationException::withMessages([
                'keyword' => ['This keyword is already tracked in the selected branch scope.'],
            ]);
        }
        $now = now('UTC');
        $id = DB::table('marketing_seo_keywords')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'created_by' => $request->user()->id,
            'keyword' => $data['keyword'],
            'target_path' => $data['target_path'],
            'priority' => $data['priority'] ?? 'normal',
            'manual_position' => $data['manual_position'] ?? null,
            'position_recorded_on' => $data['position_recorded_on'] ?? null,
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->auditValues(
            (int) $request->user()->id,
            (int) $request->user()->organization_id,
            $branchId,
            'marketing.seo-keyword.created',
            'marketing_seo_keyword',
            (string) $id,
            ['source' => 'manual'],
        );

        return response()->json(DB::table('marketing_seo_keywords')->find($id), 201);
    }

    public function deleteSeoKeyword(Request $request, int $keyword, FoundationScope $scope): Response
    {
        $record = DB::table('marketing_seo_keywords')
            ->where('organization_id', $request->user()->organization_id)
            ->find($keyword);
        abort_if($record === null, 404);
        $this->assertBranchScope($request, $record->branch_id === null ? null : (int) $record->branch_id, $scope);
        DB::table('marketing_seo_keywords')->where('id', $keyword)->delete();
        $this->auditValues(
            (int) $request->user()->id,
            (int) $request->user()->organization_id,
            $record->branch_id === null ? null : (int) $record->branch_id,
            'marketing.seo-keyword.deleted',
            'marketing_seo_keyword',
            (string) $keyword,
            [],
        );

        return response()->noContent();
    }

    public function saveBranchSeo(Request $request, Branch $branch, FoundationScope $scope): JsonResponse
    {
        $scope->assertBranch($request->user(), $branch);
        $data = $request->validate([
            'page_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'target_keywords' => ['sometimes', 'array', 'max:20'],
            'target_keywords.*' => ['required', 'string', 'max:120', 'distinct'],
        ]);
        $keywords = array_values(array_filter(array_map('trim', $data['target_keywords'] ?? [])));
        $now = now('UTC');
        DB::table('marketing_seo_branch_settings')->updateOrInsert(
            [
                'organization_id' => $request->user()->organization_id,
                'branch_id' => $branch->id,
            ],
            [
                'page_title' => $data['page_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'target_keywords' => json_encode($keywords, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
        $this->auditValues(
            (int) $request->user()->id,
            (int) $request->user()->organization_id,
            (int) $branch->id,
            'marketing.branch-seo.updated',
            'branch',
            (string) $branch->id,
            ['keyword_count' => count($keywords)],
        );

        return response()->json([
            'branch_id' => $branch->id,
            'page_title' => $data['page_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'target_keywords' => $keywords,
        ]);
    }

    private function syncLogsQuery(Request $request, FoundationScope $scope): QueryBuilder
    {
        $query = DB::table('marketing_platform_sync_logs')
            ->where('organization_id', $request->user()->organization_id);
        $this->applyBranchFilter($query, $request, $scope);

        return $query->orderByDesc('created_at');
    }

    private function applyBranchFilter(QueryBuilder $query, Request $request, FoundationScope $scope): void
    {
        $branchId = $request->query('branch_id');
        if ($branchId !== null) {
            $this->assertBranchScope($request, (int) $branchId, $scope);
            $query->where('branch_id', (int) $branchId);
        } elseif (! $request->user()->can('branch.view_all')) {
            $query->where(function (QueryBuilder $branches) use ($request, $scope): void {
                $branches->whereNull('branch_id')->orWhereIn('branch_id', $scope->branchIds($request->user()));
            });
        }
    }

    private function assertBranchScope(Request $request, ?int $branchId, FoundationScope $scope): void
    {
        if ($branchId === null) {
            abort_unless($request->user()->can('branch.view_all'), 403, 'Organization-wide marketing records require organization-wide branch access.');

            return;
        }

        $branch = Branch::query()->findOrFail($branchId);
        $scope->assertBranch($request->user(), $branch);
    }

    private function findSocialPost(Request $request, int $postId, FoundationScope $scope): object
    {
        $record = DB::table('marketing_social_posts')
            ->where('organization_id', $request->user()->organization_id)
            ->find($postId);
        abort_if($record === null, 404);
        $this->assertBranchScope(
            $request,
            $record->branch_id === null ? null : (int) $record->branch_id,
            $scope,
        );

        return $record;
    }

    private function assertInternalPath(string $path): void
    {
        $segments = explode('/', trim($path, '/'));
        abort_unless(
            str_starts_with($path, '/')
                && ! str_starts_with($path, '//')
                && parse_url($path, PHP_URL_HOST) === null
                && ! in_array('.', $segments, true)
                && ! in_array('..', $segments, true)
                && ! str_contains($path, '\\'),
            422,
            'The target must be an internal website path.',
        );
    }

    private function grantedScopes(mixed $body, array $requestedScopes): array
    {
        $scopeValue = is_array($body) ? data_get($body, 'scope') : null;
        if (is_string($scopeValue) && $scopeValue !== '') {
            return preg_split('/[\s,]+/', trim($scopeValue), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return $requestedScopes;
    }

    private function oauthStateKey(string $state): string
    {
        return 'marketing.oauth.state.'.hash('sha256', $state);
    }

    private function frontendIntegrationResult(string $platform, string $result): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('services.frontend_url'), '/');
        $query = http_build_query([
            'marketing_integration' => $result,
            'platform' => $platform,
        ]);

        return redirect()->away($frontendUrl.'/?'.$query.'#staff');
    }

    private function writeSyncLog(
        int $organizationId,
        ?int $branchId,
        ?int $connectionId,
        ?int $userId,
        string $platform,
        string $operation,
        string $status,
        ?string $errorCode,
        string $summary,
        Carbon $startedAt,
    ): void {
        $now = now('UTC');
        DB::table('marketing_platform_sync_logs')->insert([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'connection_id' => $connectionId,
            'created_by' => $userId,
            'platform' => $platform,
            'operation' => $operation,
            'status' => $status,
            'records_processed' => 0,
            'error_code' => $errorCode,
            'summary' => $summary,
            'started_at' => $startedAt,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function auditPost(Request $request, string $action, object $record): void
    {
        $this->auditValues(
            (int) $request->user()->id,
            (int) $request->user()->organization_id,
            $record->branch_id === null ? null : (int) $record->branch_id,
            $action,
            'marketing_social_post',
            (string) $record->id,
            ['status' => $record->status],
        );
    }

    private function auditValues(
        int $userId,
        int $organizationId,
        ?int $branchId,
        string $action,
        string $subjectType,
        string $subjectId,
        array $properties,
    ): void {
        AuditEvent::query()->create([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'status_code' => 200,
            'properties' => $properties,
            'occurred_at' => now('UTC'),
        ]);
    }
}
