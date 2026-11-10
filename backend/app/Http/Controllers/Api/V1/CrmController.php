<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Support\Authorization\CustomerScope;
use App\Support\Authorization\FoundationScope;
use App\Support\Notifications\Notifier;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CrmController extends Controller
{
    private const LEAD_SOURCES = ['website', 'phone', 'walk_in', 'referral', 'social', 'campaign', 'other'];

    public function overview(Request $request, FoundationScope $scope): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $leads = $this->scopedQuery(DB::table('crm_leads')->where('crm_leads.organization_id', $organizationId), $request->user(), $scope, 'branch_id');
        $tasks = $this->scopedQuery(DB::table('crm_tasks')->where('crm_tasks.organization_id', $organizationId), $request->user(), $scope, 'branch_id');
        $campaigns = $this->scopedQuery(DB::table('crm_campaigns')->where('crm_campaigns.organization_id', $organizationId), $request->user(), $scope, 'branch_id');
        $expenses = $this->scopedQuery(DB::table('crm_marketing_expenses')->where('crm_marketing_expenses.organization_id', $organizationId), $request->user(), $scope, 'branch_id');
        $conversions = DB::table('crm_conversions')->where('crm_conversions.organization_id', $organizationId);
        $this->applyBranchScope($conversions, $request->user(), $scope, 'lead_id', 'crm_leads');

        return response()->json([
            'summary' => [
                'new_leads' => (clone $leads)->where('status', 'new')->count(),
                'open_tasks' => (clone $tasks)->whereIn('status', ['open', 'in_progress'])->count(),
                'pending_campaigns' => (clone $campaigns)->where('status', 'submitted')->count(),
                'pending_expenses' => (clone $expenses)->where('status', 'submitted')->count(),
                'conversions' => (clone $conversions)->count(),
            ],
            'leads' => $leads->leftJoin('branches', 'branches.id', '=', 'crm_leads.branch_id')
                ->leftJoin('users as owners', 'owners.id', '=', 'crm_leads.assigned_to')
                ->orderByDesc('crm_leads.created_at')->limit(100)
                ->get([
                    'crm_leads.id', 'crm_leads.branch_id', 'crm_leads.customer_id', 'crm_leads.campaign_id',
                    'crm_leads.assigned_to', 'crm_leads.source', 'crm_leads.status', 'crm_leads.name',
                    'crm_leads.email', 'crm_leads.phone', 'crm_leads.subject', 'crm_leads.message',
                    'crm_leads.contact_consent', 'crm_leads.utm_source', 'crm_leads.utm_medium',
                    'crm_leads.utm_campaign', 'crm_leads.created_at', 'branches.name as branch_name',
                    'owners.name as assigned_name',
                ]),
            'tasks' => $tasks->leftJoin('users as owners', 'owners.id', '=', 'crm_tasks.assigned_to')
                ->leftJoin('branches', 'branches.id', '=', 'crm_tasks.branch_id')
                ->orderByRaw('CASE WHEN crm_tasks.status IN ("open", "in_progress") THEN 0 ELSE 1 END')
                ->orderBy('crm_tasks.due_at')->limit(100)
                ->get([
                    'crm_tasks.id', 'crm_tasks.branch_id', 'crm_tasks.lead_id', 'crm_tasks.customer_id',
                    'crm_tasks.campaign_id', 'crm_tasks.assigned_to', 'crm_tasks.title', 'crm_tasks.details',
                    'crm_tasks.priority', 'crm_tasks.status', 'crm_tasks.due_at', 'crm_tasks.completed_at',
                    'owners.name as assigned_name', 'branches.name as branch_name',
                ]),
            'campaigns' => $campaigns->leftJoin('crm_segments', 'crm_segments.id', '=', 'crm_campaigns.segment_id')
                ->leftJoin('branches', 'branches.id', '=', 'crm_campaigns.branch_id')
                ->orderByDesc('crm_campaigns.created_at')->limit(100)
                ->get([
                    'crm_campaigns.id', 'crm_campaigns.branch_id', 'crm_campaigns.segment_id',
                    'crm_campaigns.team_id', 'crm_campaigns.name', 'crm_campaigns.objective',
                    'crm_campaigns.channels', 'crm_campaigns.budget_amount', 'crm_campaigns.currency_code',
                    'crm_campaigns.starts_on', 'crm_campaigns.ends_on', 'crm_campaigns.status',
                    'crm_campaigns.created_by', 'crm_campaigns.submitted_by', 'crm_campaigns.approved_by',
                    'crm_campaigns.approved_at', 'crm_segments.name as segment_name',
                    'branches.name as branch_name',
                ]),
            'teams' => DB::table('crm_teams')->where('organization_id', $organizationId)
                ->orderBy('name')->get(['id', 'name', 'description']),
            'segments' => $this->scopedQuery(DB::table('crm_segments')->where('organization_id', $organizationId), $request->user(), $scope, 'branch_id')
                ->where('is_active', true)->orderBy('name')->get(['id', 'branch_id', 'name', 'criteria']),
            'expenses' => $expenses->leftJoin('crm_campaigns', 'crm_campaigns.id', '=', 'crm_marketing_expenses.campaign_id')
                ->leftJoin('users as submitters', 'submitters.id', '=', 'crm_marketing_expenses.submitted_by')
                ->orderByDesc('crm_marketing_expenses.created_at')->limit(100)
                ->get([
                    'crm_marketing_expenses.id', 'crm_marketing_expenses.branch_id',
                    'crm_marketing_expenses.campaign_id', 'crm_marketing_expenses.category',
                    'crm_marketing_expenses.description', 'crm_marketing_expenses.vendor',
                    'crm_marketing_expenses.amount', 'crm_marketing_expenses.currency_code',
                    'crm_marketing_expenses.incurred_on', 'crm_marketing_expenses.status',
                    'crm_marketing_expenses.submitted_by', 'crm_marketing_expenses.reviewed_by',
                    'crm_marketing_expenses.review_note', 'crm_marketing_expenses.reviewed_at',
                    'crm_campaigns.name as campaign_name', 'submitters.name as submitted_by_name',
                ]),
            'attribution' => $conversions
                ->leftJoin('crm_leads', 'crm_leads.id', '=', 'crm_conversions.lead_id')
                ->selectRaw('COALESCE(crm_conversions.attribution_source, crm_leads.utm_source, crm_leads.source) as source, COUNT(*) as conversions')
                ->groupByRaw('COALESCE(crm_conversions.attribution_source, crm_leads.utm_source, crm_leads.source)')
                ->orderByDesc('conversions')->limit(20)->get(),
            'members' => DB::table('users')->where('organization_id', $organizationId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'branches' => $scope->branches($request->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function createPublicEnquiry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_slug' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:180'],
            'message' => ['nullable', 'string', 'max:2000'],
            'contact_consent' => ['accepted'],
            'utm_source' => ['nullable', 'string', 'max:160'],
            'utm_medium' => ['nullable', 'string', 'max:160'],
            'utm_campaign' => ['nullable', 'string', 'max:160'],
            'utm_content' => ['nullable', 'string', 'max:160'],
            'utm_term' => ['nullable', 'string', 'max:160'],
            'referrer' => ['nullable', 'url', 'max:500'],
            'landing_page' => ['nullable', 'string', 'max:500'],
        ]);
        if (blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
            throw ValidationException::withMessages([
                'email' => ['Provide an email address or phone number so the team can respond.'],
            ]);
        }

        $organization = DB::table('organizations')->where('slug', $data['organization_slug'])->first();
        abort_if($organization === null, 404);
        $branchId = $data['branch_id'] ?? DB::table('branches')
            ->where('organization_id', $organization->id)->orderBy('id')->value('id');
        if ($branchId !== null) {
            abort_unless(DB::table('branches')->where('organization_id', $organization->id)->where('id', $branchId)->exists(), 422, 'The selected location is not available.');
        }
        $email = isset($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        $customerId = $email === null ? null : DB::table('customers')
            ->where('organization_id', $organization->id)
            ->whereNull('merged_into_id')->where('normalized_email', $email)->value('id');
        $now = now('UTC');
        $id = DB::table('crm_leads')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branchId,
            'customer_id' => $customerId,
            'source' => 'website',
            'status' => 'new',
            'name' => trim($data['name']),
            'email' => $email,
            'phone' => isset($data['phone']) ? trim($data['phone']) : null,
            'subject' => trim($data['subject']),
            'message' => isset($data['message']) ? trim($data['message']) : null,
            'contact_consent' => true,
            'contact_consented_at' => $now,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,
            'utm_term' => $data['utm_term'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'landing_page' => $data['landing_page'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(Notifier::class)->enquiryReceived($data, $organization);

        return response()->json([
            'message' => 'Your enquiry has been sent to the Oshel team.',
            'reference' => $id,
        ], 201);
    }

    public function createLead(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'source' => ['required', Rule::in(self::LEAD_SOURCES)],
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:180'],
            'message' => ['nullable', 'string', 'max:2000'],
            'contact_consent' => ['sometimes', 'boolean'],
            'utm_source' => ['nullable', 'string', 'max:160'],
            'utm_medium' => ['nullable', 'string', 'max:160'],
            'utm_campaign' => ['nullable', 'string', 'max:160'],
            'utm_content' => ['nullable', 'string', 'max:160'],
            'utm_term' => ['nullable', 'string', 'max:160'],
            'referrer' => ['nullable', 'url', 'max:500'],
            'landing_page' => ['nullable', 'string', 'max:500'],
        ]);
        if (blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
            throw ValidationException::withMessages(['email' => ['Provide an email address or phone number.']]);
        }
        $branchId = $this->assertBranchId($request->user(), $scope, $data['branch_id'] ?? null);
        $this->assertOptionalCustomer($request->user(), $scope, $data['customer_id'] ?? null);
        $this->assertOptionalCampaign($request, $scope, $data['campaign_id'] ?? null);
        $this->assertAssignee($request->user(), $scope, $data['assigned_to'] ?? null, $branchId);
        $now = now('UTC');
        $id = DB::table('crm_leads')->insertGetId([
            ...$this->leadFields($data),
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'customer_id' => $data['customer_id'] ?? null,
            'campaign_id' => $data['campaign_id'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $request->user()->id,
            'source' => $data['source'],
            'status' => 'new',
            'contact_consent' => $data['contact_consent'] ?? false,
            'contact_consented_at' => ($data['contact_consent'] ?? false) ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit($request, 'crm.lead.create', 'crm_lead', $id, $branchId);

        return response()->json(DB::table('crm_leads')->where('id', $id)->first(), 201);
    }

    public function updateLead(Request $request, int $leadId, FoundationScope $scope): JsonResponse
    {
        $lead = $this->findScopedLead($request, $leadId, $scope);
        $data = $request->validate([
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'campaign_id' => ['sometimes', 'nullable', 'integer'],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'source' => ['sometimes', Rule::in(self::LEAD_SOURCES)],
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'lost'])],
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:180'],
            'message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact_consent' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('customer_id', $data)) {
            $this->assertOptionalCustomer($request->user(), $scope, $data['customer_id']);
        }
        if (array_key_exists('campaign_id', $data)) {
            $this->assertOptionalCampaign($request, $scope, $data['campaign_id']);
        }
        if (array_key_exists('assigned_to', $data)) {
            $this->assertAssignee($request->user(), $scope, $data['assigned_to'], $lead->branch_id);
        }
        $update = array_intersect_key($data, array_flip([
            'customer_id', 'campaign_id', 'assigned_to', 'source', 'status', 'name', 'email', 'phone',
            'subject', 'message', 'contact_consent',
        ]));
        $resultingEmail = array_key_exists('email', $update) ? $update['email'] : $lead->email;
        $resultingPhone = array_key_exists('phone', $update) ? $update['phone'] : $lead->phone;
        if (blank($resultingEmail) && blank($resultingPhone)) {
            throw ValidationException::withMessages([
                'email' => ['A lead must retain an email address or phone number.'],
            ]);
        }
        if (array_key_exists('email', $update) && $update['email'] !== null) {
            $update['email'] = mb_strtolower(trim($update['email']));
        }
        if (array_key_exists('contact_consent', $update)) {
            $update['contact_consented_at'] = $update['contact_consent'] ? now('UTC') : null;
        }
        $update['updated_at'] = now('UTC');
        DB::table('crm_leads')->where('id', $leadId)->update($update);
        $this->audit($request, 'crm.lead.update', 'crm_lead', $leadId, $lead->branch_id);

        return response()->json(DB::table('crm_leads')->where('id', $leadId)->first());
    }

    public function linkConversion(Request $request, int $leadId, FoundationScope $scope, CustomerScope $customers): JsonResponse
    {
        $lead = $this->findScopedLead($request, $leadId, $scope);
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'appointment_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'sale_id' => ['nullable', 'integer'],
        ]);
        $targets = array_filter([
            'appointment_id' => $data['appointment_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'sale_id' => $data['sale_id'] ?? null,
        ], fn ($value): bool => $value !== null);
        abort_unless(count($targets) === 1, 422, 'Link exactly one completed booking or purchase.');
        $customer = Customer::query()->where('organization_id', $request->user()->organization_id)->findOrFail($data['customer_id']);
        $customers->assertCustomer($request->user(), $customer, $scope);

        $targetColumn = array_key_first($targets);
        $targetId = (int) $targets[$targetColumn];
        $type = match ($targetColumn) {
            'appointment_id' => 'booking',
            default => 'purchase',
        };
        $record = match ($targetColumn) {
            'appointment_id' => DB::table('appointments')->where('organization_id', $customer->organization_id)
                ->where('customer_id', $customer->id)->whereNotIn('status', ['cancelled', 'expired'])->where('id', $targetId)->first(),
            'order_id' => DB::table('ecommerce_orders')->where('organization_id', $customer->organization_id)
                ->where('customer_id', $customer->id)->where('payment_status', 'paid')->where('id', $targetId)->first(),
            'sale_id' => DB::table('pos_sales')->where('organization_id', $customer->organization_id)
                ->where('customer_id', $customer->id)->where('status', 'completed')->where('id', $targetId)->first(),
        };
        abort_if($record === null, 404, 'The selected booking or completed purchase does not belong to this customer.');
        $branchId = (int) $record->branch_id;
        $scope->assertBranch($request->user(), Branch::query()->where('organization_id', $customer->organization_id)->findOrFail($branchId));
        $this->assertBranchId($request->user(), $scope, $lead->branch_id);
        if ($lead->customer_id !== null) {
            abort_unless((int) $lead->customer_id === (int) $customer->id, 422, 'The lead is already linked to a different customer.');
        }
        $exists = DB::table('crm_conversions')->where('lead_id', $leadId)
            ->where($targetColumn, $targetId)->exists();
        abort_if($exists, 409, 'This conversion has already been linked.');

        $now = now('UTC');
        $id = DB::table('crm_conversions')->insertGetId([
            'organization_id' => $customer->organization_id,
            'lead_id' => $leadId,
            'customer_id' => $customer->id,
            'campaign_id' => $lead->campaign_id,
            $targetColumn => $targetId,
            'conversion_type' => $type,
            'attribution_source' => $lead->utm_source ?: $lead->source,
            'attribution_medium' => $lead->utm_medium,
            'attribution_campaign' => $lead->utm_campaign,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('crm_leads')->where('id', $leadId)->update([
            'customer_id' => $customer->id,
            'status' => 'converted',
            'converted_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit($request, 'crm.lead.convert', 'crm_conversion', $id, $lead->branch_id);

        return response()->json(['id' => $id, 'lead_id' => $leadId, 'customer_id' => $customer->id, 'conversion_type' => $type], 201);
    }

    public function saveSegment(Request $request, FoundationScope $scope): JsonResponse
    {
        $criteriaInput = $request->input('criteria');
        abort_unless(is_array($criteriaInput), 422, 'Segment criteria must be an object.');
        $allowed = ['registration_source', 'branch_id', 'email_marketing', 'sms_marketing'];
        abort_unless(array_diff(array_keys($criteriaInput), $allowed) === [], 422, 'Segment criteria contain unsupported customer fields.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer'],
            'criteria' => ['required', 'array'],
            'criteria.registration_source' => ['sometimes', Rule::in(['walk_in', 'referral', 'website', 'phone', 'other'])],
            'criteria.branch_id' => ['sometimes', 'integer'],
            'criteria.email_marketing' => ['sometimes', Rule::in(['opted_in', 'opted_out'])],
            'criteria.sms_marketing' => ['sometimes', Rule::in(['opted_in', 'opted_out'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $branchId = $this->assertBranchId($request->user(), $scope, $data['branch_id'] ?? ($data['criteria']['branch_id'] ?? null));
        if (! $request->user()->can('branch.view_all')) {
            $data['criteria']['branch_id'] = $branchId;
        }
        $now = now('UTC');
        $id = DB::table('crm_segments')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'created_by' => $request->user()->id,
            'name' => trim($data['name']),
            'criteria' => json_encode($data['criteria'], JSON_THROW_ON_ERROR),
            'is_active' => $data['is_active'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit($request, 'crm.segment.create', 'crm_segment', $id, $branchId);

        return response()->json(DB::table('crm_segments')->where('id', $id)->first(), 201);
    }

    public function segmentMembers(Request $request, int $segmentId, FoundationScope $scope): JsonResponse
    {
        $segment = DB::table('crm_segments')->where('organization_id', $request->user()->organization_id)
            ->where('id', $segmentId)->first();
        abort_if($segment === null, 404);
        $this->assertBranchId($request->user(), $scope, $segment->branch_id);
        $criteria = json_decode($segment->criteria, true, 512, JSON_THROW_ON_ERROR);
        $query = DB::table('customers')->where('customers.organization_id', $request->user()->organization_id)
            ->whereNull('customers.merged_into_id')->where('customers.status', 'active');
        if (! $request->user()->can('branch.view_all')) {
            $branchIds = $scope->branchIds($request->user());
            $query->whereIn('customers.registered_branch_id', $branchIds);
        }
        if (isset($criteria['registration_source'])) {
            $query->where('customers.registration_source', $criteria['registration_source']);
        }
        if (isset($criteria['branch_id'])) {
            $query->where('customers.registered_branch_id', $criteria['branch_id']);
        }
        foreach (['email' => 'email_marketing', 'sms' => 'sms_marketing'] as $channel => $key) {
            if (isset($criteria[$key])) {
                $query->whereExists(function ($subquery) use ($channel, $criteria, $key): void {
                    $subquery->selectRaw('1')->from('crm_customer_preferences')
                        ->whereColumn('crm_customer_preferences.customer_id', 'customers.id')
                        ->whereColumn('crm_customer_preferences.organization_id', 'customers.organization_id')
                        ->where('crm_customer_preferences.channel', $channel)
                        ->where('crm_customer_preferences.purpose', 'marketing')
                        ->where('crm_customer_preferences.status', $criteria[$key]);
                });
            }
        }

        return response()->json([
            'segment' => ['id' => $segment->id, 'name' => $segment->name],
            'members' => $query->orderBy('customers.name')->paginate(50)->through(fn (object $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ]),
        ]);
    }

    public function createTeam(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'member_ids' => ['sometimes', 'array', 'max:100'],
            'member_ids.*' => ['integer'],
        ]);
        $this->assertTeamMembers($request, $data['member_ids'] ?? []);
        $now = now('UTC');
        $teamId = DB::transaction(function () use ($request, $data, $now): int {
            $id = DB::table('crm_teams')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'created_by' => $request->user()->id,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($data['member_ids'] ?? [] as $memberId) {
                DB::table('crm_team_members')->insert([
                    'team_id' => $id,
                    'user_id' => $memberId,
                    'team_role' => 'member',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $id;
        });
        $this->audit($request, 'crm.team.create', 'crm_team', $teamId);

        return response()->json(DB::table('crm_teams')->where('id', $teamId)->first(), 201);
    }

    public function setTeamMembers(Request $request, int $teamId): JsonResponse
    {
        $team = DB::table('crm_teams')->where('organization_id', $request->user()->organization_id)->where('id', $teamId)->first();
        abort_if($team === null, 404);
        $data = $request->validate([
            'member_ids' => ['required', 'array', 'max:100'],
            'member_ids.*' => ['integer'],
        ]);
        $this->assertTeamMembers($request, $data['member_ids']);
        DB::transaction(function () use ($teamId, $data): void {
            DB::table('crm_team_members')->where('team_id', $teamId)->delete();
            foreach (array_unique($data['member_ids']) as $memberId) {
                DB::table('crm_team_members')->insert([
                    'team_id' => $teamId,
                    'user_id' => $memberId,
                    'team_role' => 'member',
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }
        });
        $this->audit($request, 'crm.team.members.update', 'crm_team', $teamId);

        return response()->json(['team_id' => $teamId, 'member_ids' => array_values(array_unique($data['member_ids']))]);
    }

    public function createTask(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'assigned_to' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:180'],
            'details' => ['nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'due_at' => ['nullable', 'date'],
        ]);
        $branchId = $data['branch_id'] ?? null;
        if (isset($data['lead_id'])) {
            $lead = $this->findScopedLead($request, (int) $data['lead_id'], $scope);
            abort_unless($branchId === null || (int) $lead->branch_id === $branchId, 422, 'Task and lead branch must match.');
            $branchId ??= $lead->branch_id;
        }
        $branchId = $this->assertBranchId($request->user(), $scope, $branchId);
        if (isset($data['customer_id'])) {
            $this->assertOptionalCustomer($request->user(), $scope, $data['customer_id']);
        }
        if (isset($data['campaign_id'])) {
            $this->assertOptionalCampaign($request, $scope, $data['campaign_id']);
        }
        $this->assertAssignee($request->user(), $scope, (int) $data['assigned_to'], $branchId);
        $now = now('UTC');
        $id = DB::table('crm_tasks')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'lead_id' => $data['lead_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'campaign_id' => $data['campaign_id'] ?? null,
            'assigned_to' => $data['assigned_to'],
            'created_by' => $request->user()->id,
            'title' => trim($data['title']),
            'details' => isset($data['details']) ? trim($data['details']) : null,
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'open',
            'due_at' => $data['due_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit($request, 'crm.task.create', 'crm_task', $id, $branchId);

        return response()->json(DB::table('crm_tasks')->where('id', $id)->first(), 201);
    }

    public function updateTask(Request $request, int $taskId, FoundationScope $scope): JsonResponse
    {
        $task = $this->scopedQuery(DB::table('crm_tasks')->where('organization_id', $request->user()->organization_id), $request->user(), $scope, 'branch_id')
            ->where('id', $taskId)->first();
        abort_if($task === null, 404);
        $data = $request->validate([
            'assigned_to' => ['sometimes', 'integer'],
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'details' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
            'status' => ['sometimes', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);
        if (isset($data['assigned_to'])) {
            $this->assertAssignee($request->user(), $scope, $data['assigned_to'], $task->branch_id);
        }
        $update = $data;
        if (isset($data['status'])) {
            $update['completed_at'] = $data['status'] === 'completed' ? now('UTC') : null;
        }
        $update['updated_at'] = now('UTC');
        DB::table('crm_tasks')->where('id', $taskId)->update($update);
        $this->audit($request, 'crm.task.update', 'crm_task', $taskId, $task->branch_id);

        return response()->json(DB::table('crm_tasks')->where('id', $taskId)->first());
    }

    public function createCampaign(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'segment_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:160'],
            'objective' => ['nullable', 'string', 'max:500'],
            'channels' => ['required', 'array', 'min:1', 'max:8'],
            'channels.*' => [Rule::in(['email', 'sms', 'whatsapp', 'social', 'website', 'events', 'referral'])],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        $branchId = $this->assertBranchId($request->user(), $scope, $data['branch_id'] ?? null);
        $this->assertOptionalSegment($request->user(), $scope, $data['segment_id'] ?? null);
        if (isset($data['team_id'])) {
            abort_unless(DB::table('crm_teams')->where('organization_id', $request->user()->organization_id)->where('id', $data['team_id'])->exists(), 422, 'The selected team does not belong to this organization.');
        }
        $now = now('UTC');
        $id = DB::table('crm_campaigns')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'segment_id' => $data['segment_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'created_by' => $request->user()->id,
            'name' => trim($data['name']),
            'objective' => isset($data['objective']) ? trim($data['objective']) : null,
            'channels' => json_encode(array_values(array_unique($data['channels'])), JSON_THROW_ON_ERROR),
            'budget_amount' => $data['budget_amount'] ?? null,
            'currency_code' => strtoupper($data['currency_code'] ?? 'KES'),
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit($request, 'crm.campaign.create', 'crm_campaign', $id, $branchId);

        return response()->json(DB::table('crm_campaigns')->where('id', $id)->first(), 201);
    }

    public function submitCampaign(Request $request, int $campaignId, FoundationScope $scope): JsonResponse
    {
        $campaign = $this->findScopedCampaign($request, $campaignId, $scope);
        abort_unless($campaign->status === 'draft', 409, 'Only draft campaigns can be submitted for approval.');
        DB::table('crm_campaigns')->where('id', $campaignId)->update([
            'status' => 'submitted',
            'submitted_by' => $request->user()->id,
            'submitted_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'crm.campaign.submit', 'crm_campaign', $campaignId, $campaign->branch_id);

        return response()->json(DB::table('crm_campaigns')->where('id', $campaignId)->first());
    }

    public function approveCampaign(Request $request, int $campaignId, FoundationScope $scope): JsonResponse
    {
        $campaign = $this->findScopedCampaign($request, $campaignId, $scope);
        abort_unless($campaign->status === 'submitted', 409, 'Only submitted campaigns can be approved.');
        abort_unless((int) $campaign->submitted_by !== (int) $request->user()->id, 403, 'The campaign submitter cannot approve their own campaign.');
        DB::table('crm_campaigns')->where('id', $campaignId)->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'crm.campaign.approve', 'crm_campaign', $campaignId, $campaign->branch_id);

        return response()->json(DB::table('crm_campaigns')->where('id', $campaignId)->first());
    }

    public function updateCampaignStatus(Request $request, int $campaignId, FoundationScope $scope): JsonResponse
    {
        $campaign = $this->findScopedCampaign($request, $campaignId, $scope);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'completed', 'cancelled'])]]);
        abort_unless(
            ($campaign->status === 'approved' && in_array($data['status'], ['active', 'cancelled'], true))
                || ($campaign->status === 'active' && in_array($data['status'], ['completed', 'cancelled'], true)),
            409,
            'The requested campaign status transition is not allowed.',
        );
        DB::table('crm_campaigns')->where('id', $campaignId)->update([
            'status' => $data['status'],
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'crm.campaign.status', 'crm_campaign', $campaignId, $campaign->branch_id);

        return response()->json(DB::table('crm_campaigns')->where('id', $campaignId)->first());
    }

    public function createExpense(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:500'],
            'vendor' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'incurred_on' => ['required', 'date'],
        ]);
        $branchId = $this->assertBranchId($request->user(), $scope, $data['branch_id'] ?? null);
        if (isset($data['campaign_id'])) {
            $campaign = $this->findScopedCampaign($request, $data['campaign_id'], $scope);
            abort_unless(in_array($campaign->status, ['approved', 'active'], true), 422, 'Expenses can only be attached to an approved or active campaign.');
            abort_unless(strtoupper($campaign->currency_code) === strtoupper($data['currency_code'] ?? 'KES'), 422, 'The expense currency must match the campaign budget currency.');
            $branchId ??= $campaign->branch_id;
        }
        $now = now('UTC');
        $id = DB::transaction(function () use ($request, $data, $branchId, $now): int {
            if (isset($data['campaign_id'])) {
                $campaign = DB::table('crm_campaigns')->where('organization_id', $request->user()->organization_id)
                    ->where('id', $data['campaign_id'])->lockForUpdate()->first();
                abort_if($campaign === null, 404);
                abort_unless(in_array($campaign->status, ['approved', 'active'], true), 422, 'Expenses can only be attached to an approved or active campaign.');
                abort_unless(strtoupper($campaign->currency_code) === strtoupper($data['currency_code'] ?? 'KES'), 422, 'The expense currency must match the campaign budget currency.');
                $this->assertWithinBudget($campaign, $data['amount']);
            }

            return DB::table('crm_marketing_expenses')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'branch_id' => $branchId,
                'campaign_id' => $data['campaign_id'] ?? null,
                'submitted_by' => $request->user()->id,
                'category' => trim($data['category']),
                'description' => trim($data['description']),
                'vendor' => isset($data['vendor']) ? trim($data['vendor']) : null,
                'amount' => $data['amount'],
                'currency_code' => strtoupper($data['currency_code'] ?? 'KES'),
                'incurred_on' => $data['incurred_on'],
                'status' => 'submitted',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
        $this->audit($request, 'crm.expense.submit', 'crm_marketing_expense', $id, $branchId);

        return response()->json(DB::table('crm_marketing_expenses')->where('id', $id)->first(), 201);
    }

    public function reviewExpense(Request $request, int $expenseId, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);
        $expense = DB::transaction(function () use ($expenseId, $data, $request, $scope): object {
            $expense = $this->scopedQuery(DB::table('crm_marketing_expenses')->where('organization_id', $request->user()->organization_id), $request->user(), $scope, 'branch_id')
                ->where('id', $expenseId)->lockForUpdate()->first();
            abort_if($expense === null, 404);
            abort_unless($expense->status === 'submitted', 409, 'Only submitted expenses can be reviewed.');
            abort_unless((int) $expense->submitted_by !== (int) $request->user()->id, 403, 'The expense submitter cannot approve their own expense.');
            if ($data['decision'] === 'approved' && $expense->campaign_id !== null) {
                $campaign = DB::table('crm_campaigns')->where('organization_id', $expense->organization_id)
                    ->where('id', $expense->campaign_id)->lockForUpdate()->first();
                abort_if($campaign === null, 404);
                $this->assertWithinBudget($campaign, $expense->amount, (int) $expense->id, true);
            }
            DB::table('crm_marketing_expenses')->where('id', $expense->id)->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'review_note' => isset($data['review_note']) ? trim($data['review_note']) : null,
                'reviewed_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return $expense;
        });
        $this->audit($request, 'crm.expense.review', 'crm_marketing_expense', $expenseId, $expense->branch_id);

        return response()->json(DB::table('crm_marketing_expenses')->where('id', $expenseId)->first());
    }

    public function updatePreference(Request $request, int $customerId, FoundationScope $scope, CustomerScope $customerScope): JsonResponse
    {
        return $this->recordPreference($request, $customerId, $scope, $customerScope, 'staff');
    }

    public function updateOwnPreference(Request $request, FoundationScope $scope, CustomerScope $customerScope): JsonResponse
    {
        abort_if($request->user()->customer_id === null, 404);

        return $this->recordPreference($request, (int) $request->user()->customer_id, $scope, $customerScope, 'portal');
    }

    public function ownPreferences(Request $request): JsonResponse
    {
        abort_if($request->user()->customer_id === null, 404);
        $customer = Customer::query()->where('organization_id', $request->user()->organization_id)
            ->findOrFail($request->user()->customer_id);
        $statuses = DB::table('crm_customer_preferences')
            ->where('organization_id', $customer->organization_id)
            ->where('customer_id', $customer->id)
            ->where('purpose', 'marketing')
            ->pluck('status', 'channel');

        return response()->json([
            'preferences' => collect(['email', 'sms', 'whatsapp'])->map(fn (string $channel): array => [
                'channel' => $channel,
                'status' => $statuses->get($channel, 'not_set'),
            ])->all(),
        ]);
    }

    private function recordPreference(Request $request, int $customerId, FoundationScope $scope, CustomerScope $customerScope, string $source): JsonResponse
    {
        if ($source === 'portal') {
            abort_unless((int) $request->user()->customer_id === $customerId, 403);
        } else {
            abort_unless($request->user()->can('crm.consent.manage'), 403);
        }
        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp'])],
            'status' => ['required', Rule::in(['opted_in', 'opted_out'])],
            'consent_text_version' => ['nullable', 'string', 'max:40'],
        ]);
        $customer = Customer::query()->where('organization_id', $request->user()->organization_id)->findOrFail($customerId);
        $customerScope->assertCustomer($request->user(), $customer, $scope);
        if ($data['status'] === 'opted_in') {
            abort_unless(
                ($data['channel'] === 'email' && filled($customer->email))
                    || (in_array($data['channel'], ['sms', 'whatsapp'], true) && filled($customer->phone)),
                422,
                'A matching contact address is required before opting in.',
            );
        }
        $now = now('UTC');
        DB::transaction(function () use ($request, $customer, $data, $source, $now): void {
            $previous = DB::table('crm_customer_preferences')
                ->where('organization_id', $customer->organization_id)
                ->where('customer_id', $customer->id)
                ->where('channel', $data['channel'])
                ->where('purpose', 'marketing')
                ->lockForUpdate()->first();
            DB::table('crm_customer_preferences')->updateOrInsert(
                [
                    'organization_id' => $customer->organization_id,
                    'customer_id' => $customer->id,
                    'channel' => $data['channel'],
                    'purpose' => 'marketing',
                ],
                [
                    'status' => $data['status'],
                    'source' => $source,
                    'captured_by' => $source === 'staff' ? $request->user()->id : null,
                    'consent_text_version' => $data['consent_text_version'] ?? null,
                    'changed_at' => $now,
                    'created_at' => $previous?->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );
            DB::table('crm_consent_events')->insert([
                'organization_id' => $customer->organization_id,
                'customer_id' => $customer->id,
                'actor_id' => $source === 'staff' ? $request->user()->id : null,
                'channel' => $data['channel'],
                'purpose' => 'marketing',
                'previous_status' => $previous?->status,
                'new_status' => $data['status'],
                'source' => $source,
                'consent_text_version' => $data['consent_text_version'] ?? null,
                'changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
        if ($source === 'staff') {
            $this->audit($request, 'crm.consent.preference', 'customer', $customerId, $customer->registered_branch_id);
        }

        return response()->json(DB::table('crm_customer_preferences')
            ->where('organization_id', $customer->organization_id)->where('customer_id', $customerId)
            ->where('channel', $data['channel'])->where('purpose', 'marketing')->first());
    }

    private function findScopedLead(Request $request, int $leadId, FoundationScope $scope): object
    {
        $lead = $this->scopedQuery(DB::table('crm_leads')->where('organization_id', $request->user()->organization_id), $request->user(), $scope, 'branch_id')
            ->where('id', $leadId)->first();
        abort_if($lead === null, 404);

        return $lead;
    }

    private function findScopedCampaign(Request $request, int $campaignId, FoundationScope $scope): object
    {
        $campaign = $this->scopedQuery(DB::table('crm_campaigns')->where('organization_id', $request->user()->organization_id), $request->user(), $scope, 'branch_id')
            ->where('id', $campaignId)->first();
        abort_if($campaign === null, 404);

        return $campaign;
    }

    private function scopedQuery(Builder $query, User $user, FoundationScope $scope, string $column): Builder
    {
        if (! $user->can('branch.view_all')) {
            $ids = $scope->branchIds($user);
            if ($ids === []) {
                return $query->whereRaw('1 = 0');
            }
            $table = is_string($query->from) ? $query->from : null;
            $qualifiedColumn = str_contains($column, '.') || $table === null ? $column : $table.'.'.$column;
            $query->whereIn($qualifiedColumn, $ids);
        }

        return $query;
    }

    private function applyBranchScope(Builder $query, User $user, FoundationScope $scope, string $foreignKey, string $table): void
    {
        if (! $user->can('branch.view_all')) {
            $ids = $scope->branchIds($user);
            if ($ids === []) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn($foreignKey, DB::table($table)->select('id')->whereIn('branch_id', $ids));
        }
    }

    private function assertBranchId(User $user, FoundationScope $scope, ?int $branchId): ?int
    {
        if ($branchId === null) {
            abort_unless($user->can('branch.view_all'), 422, 'Select an assigned branch for this CRM record.');

            return null;
        }
        $branch = Branch::query()->where('organization_id', $user->organization_id)->findOrFail($branchId);
        $scope->assertBranch($user, $branch);

        return (int) $branch->id;
    }

    private function assertOptionalCustomer(User $user, FoundationScope $scope, ?int $customerId): void
    {
        if ($customerId === null) {
            return;
        }
        $customer = Customer::query()->where('organization_id', $user->organization_id)->findOrFail($customerId);
        app(CustomerScope::class)->assertCustomer($user, $customer, $scope);
    }

    private function assertOptionalCampaign(Request $request, FoundationScope $scope, ?int $campaignId): void
    {
        if ($campaignId !== null) {
            $this->findScopedCampaign($request, $campaignId, $scope);
        }
    }

    private function assertOptionalSegment(User $user, FoundationScope $scope, ?int $segmentId): void
    {
        if ($segmentId === null) {
            return;
        }
        $segment = DB::table('crm_segments')->where('organization_id', $user->organization_id)->where('id', $segmentId)->first();
        abort_if($segment === null, 422, 'The selected segment does not belong to this organization.');
        $this->assertBranchId($user, $scope, $segment->branch_id);
    }

    private function assertAssignee(User $actor, FoundationScope $scope, ?int $userId, ?int $branchId): void
    {
        if ($userId === null) {
            return;
        }
        $assignee = User::query()->where('organization_id', $actor->organization_id)
            ->where('is_active', true)->findOrFail($userId);
        if ($branchId === null) {
            abort_unless($assignee->can('branch.view_all'), 422, 'Organization-wide tasks can only be assigned to an organization-wide user.');

            return;
        }
        abort_unless(
            in_array($branchId, $scope->branchIds($assignee), true),
            422,
            'The selected assignee is not assigned to this branch.',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function leadFields(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'email' => isset($data['email']) ? mb_strtolower(trim($data['email'])) : null,
            'phone' => isset($data['phone']) ? trim($data['phone']) : null,
            'subject' => isset($data['subject']) ? trim($data['subject']) : null,
            'message' => isset($data['message']) ? trim($data['message']) : null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'utm_content' => $data['utm_content'] ?? null,
            'utm_term' => $data['utm_term'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'landing_page' => $data['landing_page'] ?? null,
        ];
    }

    private function assertTeamMembers(Request $request, array $memberIds): void
    {
        $uniqueIds = array_values(array_unique($memberIds));
        abort_unless(
            DB::table('users')->where('organization_id', $request->user()->organization_id)
                ->where('is_active', true)->whereIn('id', $uniqueIds)->count() === count($uniqueIds),
            422,
            'Every team member must be an active user in this organization.',
        );
    }

    private function assertWithinBudget(object $campaign, string|int|float $amount, ?int $excludeExpenseId = null): void
    {
        abort_unless($campaign->budget_amount !== null, 422, 'Set an approved campaign budget before submitting campaign expenses.');
        $committed = (float) DB::table('crm_marketing_expenses')
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['submitted', 'approved'])
            ->when($excludeExpenseId !== null, fn (Builder $query) => $query->where('id', '<>', $excludeExpenseId))
            ->sum('amount');
        $proposed = (float) $amount;
        abort_unless(
            $committed + $proposed <= (float) $campaign->budget_amount,
            422,
            'This expense would exceed the approved campaign budget.',
        );
    }

    private function audit(Request $request, string $action, string $subjectType, int $subjectId, ?int $branchId = null): void
    {
        DB::table('audit_events')->insert([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'properties' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now('UTC'),
        ]);
    }
}
