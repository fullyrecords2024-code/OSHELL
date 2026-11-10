<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\ClinicalAssessmentTemplate;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalEncounterAmendment;
use App\Models\Customer;
use App\Models\User;
use App\Support\Authorization\CustomerScope;
use App\Support\Authorization\FoundationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClinicalEncounterController extends Controller
{
    private const VITAL_LIMITS = [
        'systolic_bp' => [40, 300],
        'diastolic_bp' => [20, 200],
        'heart_rate' => [20, 250],
        'temperature_c' => [25, 45],
        'respiratory_rate' => [4, 80],
        'oxygen_saturation' => [0, 100],
        'weight_kg' => [0.1, 500],
        'height_cm' => [20, 280],
    ];

    public function branches(Request $request, FoundationScope $foundationScope): JsonResponse
    {
        $branches = $foundationScope->branches($request->user())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $this->recordAccess($request, 'clinical.branches.view', null, ['count' => $branches->count()]);

        return response()->json($branches);
    }

    public function patients(
        Request $request,
        FoundationScope $foundationScope,
        CustomerScope $customerScope,
    ): JsonResponse {
        $patients = $customerScope->customers($request->user(), $foundationScope)
            ->whereHas('patientProfile')
            ->orderBy('name')
            ->paginate(100, ['id', 'name']);
        $this->recordAccess($request, 'clinical.patients.list', null, ['count' => $patients->count()]);

        return response()->json($patients);
    }

    public function templates(Request $request): JsonResponse
    {
        $templates = ClinicalAssessmentTemplate::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $this->recordAccess($request, 'clinical.templates.view', null, ['count' => $templates->count()]);

        return response()->json($templates);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:160',
                Rule::unique('clinical_assessment_templates', 'name')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'fields' => ['required', 'array', 'min:1', 'max:50'],
            'fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,63}$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:160'],
            'fields.*.type' => ['required', Rule::in(['text', 'number', 'boolean', 'select'])],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.options' => ['sometimes', 'array', 'max:50'],
            'fields.*.options.*' => ['string', 'max:160'],
        ]);
        foreach ($data['fields'] as $field) {
            if ($field['type'] === 'select' && empty($field['options'])) {
                return response()->json([
                    'message' => "Select assessment field [{$field['key']}] must define options.",
                ], 422);
            }
        }

        $template = ClinicalAssessmentTemplate::query()->create([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);
        $this->recordAccess($request, 'clinical.templates.create', $template);

        return response()->json($template, 201);
    }

    public function index(
        Request $request,
        FoundationScope $foundationScope,
        CustomerScope $customerScope,
    ): JsonResponse {
        $data = $request->validate([
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'status' => ['sometimes', Rule::in(['in_progress', 'completed'])],
        ]);
        $user = $request->user();
        $query = $this->encountersForUser($user, $foundationScope)
            ->with(['customer:id,name', 'branch:id,name', 'creator:id,name', 'template:id,name']);

        if (isset($data['customer_id'])) {
            $customer = Customer::query()->findOrFail($data['customer_id']);
            $customerScope->assertCustomer($user, $customer, $foundationScope);
            abort_unless($customer->patientProfile()->exists(), 404);
            $query->where('customer_id', $customer->id);
        }
        if (isset($data['branch_id'])) {
            $foundationScope->assertBranch($user, Branch::query()->findOrFail($data['branch_id']));
            $query->where('branch_id', $data['branch_id']);
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        $result = $query->orderByDesc('started_at')->paginate(25);
        $this->recordAccess($request, 'clinical.encounters.list', null, [
            'customer_id' => $data['customer_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'count' => $result->count(),
        ]);

        return response()->json($result);
    }

    public function show(
        Request $request,
        ClinicalEncounter $encounter,
        FoundationScope $foundationScope,
        CustomerScope $customerScope,
    ): JsonResponse {
        $this->assertEncounterScope($request->user(), $encounter, $foundationScope, $customerScope);
        $encounter->load([
            'customer:id,name',
            'branch:id,name',
            'creator:id,name',
            'appointment:id,appointment_number,starts_at,status',
            'template:id,name,description,fields',
            'amendments.user:id,name',
        ]);
        $this->recordAccess($request, 'clinical.encounter.view', $encounter);

        return response()->json($encounter);
    }

    public function store(
        Request $request,
        FoundationScope $foundationScope,
        CustomerScope $customerScope,
    ): JsonResponse {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'template_id' => ['nullable', 'integer', 'exists:clinical_assessment_templates,id'],
            'vitals' => ['sometimes', 'nullable', 'array'],
            'assessment' => ['sometimes', 'nullable', 'array'],
            'clinical_note' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'treatment_plan' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'follow_up_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'follow_up_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $user = $request->user();
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $customerScope->assertCustomer($user, $customer, $foundationScope);
        abort_unless($customer->patientProfile()->exists(), 422, 'Clinical encounters require a linked patient profile.');

        $branch = Branch::query()->findOrFail($data['branch_id']);
        $foundationScope->assertBranch($user, $branch);
        abort_unless((int) $branch->organization_id === (int) $customer->organization_id, 422);
        $template = $this->resolveTemplate($data['template_id'] ?? null, $user);
        if ($template) {
            $this->validateAssessment($data['assessment'] ?? [], $template);
        } elseif (! empty($data['assessment'])) {
            abort(422, 'An assessment template is required when assessment values are supplied.');
        }
        $this->validateVitals($data['vitals'] ?? []);
        $appointment = $this->resolveAppointment($data['appointment_id'] ?? null, $customer, $branch, $user);

        $encounter = ClinicalEncounter::query()->create([
            ...$data,
            'organization_id' => $user->organization_id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'appointment_id' => $appointment?->id,
            'template_id' => $template?->id,
            'created_by' => $user->id,
            'status' => 'in_progress',
            'started_at' => now('UTC'),
        ]);
        $encounter->load(['customer:id,name', 'branch:id,name', 'template:id,name']);
        $this->recordAccess($request, 'clinical.encounter.create', $encounter);

        return response()->json($encounter, 201);
    }

    public function update(
        Request $request,
        ClinicalEncounter $encounter,
        FoundationScope $foundationScope,
        CustomerScope $customerScope,
    ): JsonResponse {
        $this->assertEncounterScope($request->user(), $encounter, $foundationScope, $customerScope);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'template_id' => ['sometimes', 'nullable', 'integer', 'exists:clinical_assessment_templates,id'],
            'vitals' => ['sometimes', 'nullable', 'array'],
            'assessment' => ['sometimes', 'nullable', 'array'],
            'clinical_note' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'treatment_plan' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'follow_up_at' => ['sometimes', 'nullable', 'date'],
            'follow_up_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        abort_unless($encounter->status === 'in_progress' || $request->user()->can('clinical.amend'), 403);
        $templateId = array_key_exists('template_id', $data) ? $data['template_id'] : $encounter->template_id;
        $template = $this->resolveTemplate($templateId, $request->user());
        $assessment = $data['assessment'] ?? $encounter->assessment ?? [];
        $templateChanged = array_key_exists('template_id', $data)
            && (int) ($data['template_id'] ?? 0) !== (int) ($encounter->template_id ?? 0);
        if ($template && (array_key_exists('assessment', $data) || $templateChanged)) {
            $this->validateAssessment($assessment, $template);
        } elseif (! $template && ! empty($assessment)) {
            abort(422, 'An assessment template is required when assessment values are supplied.');
        }
        if (array_key_exists('vitals', $data)) {
            $this->validateVitals($data['vitals'] ?? []);
        }

        $fields = array_values(array_diff(array_keys($data), ['reason']));
        abort_if($fields === [], 422, 'Provide at least one clinical field to amend.');
        DB::transaction(function () use ($data, $encounter, $fields, $request, $template): void {
            $before = $this->clinicalState($encounter);
            foreach ($fields as $field) {
                $encounter->{$field} = $field === 'template_id' ? $template?->id : $data[$field];
            }
            $encounter->save();
            ClinicalEncounterAmendment::query()->create([
                'clinical_encounter_id' => $encounter->id,
                'user_id' => $request->user()->id,
                'reason' => $data['reason'],
                'before_state' => $before,
                'after_state' => $this->clinicalState($encounter->refresh()),
                'created_at' => now('UTC'),
            ]);
        });
        $encounter->load(['customer:id,name', 'branch:id,name', 'template:id,name']);
        $this->recordAccess($request, 'clinical.encounter.amend', $encounter, ['fields' => $fields]);

        return response()->json($encounter);
    }

    public function complete(
        Request $request,
        ClinicalEncounter $encounter,
        FoundationScope $foundationScope,
        CustomerScope $customerScope,
    ): JsonResponse {
        $this->assertEncounterScope($request->user(), $encounter, $foundationScope, $customerScope);
        abort_unless($encounter->status === 'in_progress', 409, 'Only an in-progress encounter can be completed.');
        $data = $request->validate([
            'completion_note' => ['nullable', 'string', 'max:5000'],
        ]);
        if (isset($data['completion_note']) && trim($data['completion_note']) !== '') {
            $before = $this->clinicalState($encounter);
            $encounter->clinical_note = trim(($encounter->clinical_note ? $encounter->clinical_note."\n\n" : '')
                .'Completion: '.$data['completion_note']);
            $encounter->save();
            ClinicalEncounterAmendment::query()->create([
                'clinical_encounter_id' => $encounter->id,
                'user_id' => $request->user()->id,
                'reason' => 'Completion note recorded.',
                'before_state' => $before,
                'after_state' => $this->clinicalState($encounter->refresh()),
                'created_at' => now('UTC'),
            ]);
        }
        $encounter->forceFill([
            'status' => 'completed',
            'completed_at' => now('UTC'),
        ])->save();
        $encounter->load(['customer:id,name', 'branch:id,name', 'template:id,name']);
        $this->recordAccess($request, 'clinical.encounter.complete', $encounter);

        return response()->json($encounter);
    }

    public function workflowReport(
        Request $request,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $data = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
        $user = $request->user();
        $query = $this->encountersForUser($user, $foundationScope);
        if (isset($data['branch_id'])) {
            $foundationScope->assertBranch($user, Branch::query()->findOrFail($data['branch_id']));
            $query->where('branch_id', $data['branch_id']);
        }
        if (isset($data['from'])) {
            $query->whereDate('started_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('started_at', '<=', $data['to']);
        }
        $report = [
            'total' => (clone $query)->count(),
            'in_progress' => (clone $query)->where('status', 'in_progress')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'follow_ups_due' => (clone $query)->whereNotNull('follow_up_at')
                ->where('follow_up_at', '<=', now('UTC'))
                ->count(),
            'follow_ups_upcoming' => (clone $query)->whereNotNull('follow_up_at')
                ->where('follow_up_at', '>', now('UTC'))
                ->count(),
        ];
        $this->recordAccess($request, 'clinical.workflow.report', null, [
            'branch_id' => $data['branch_id'] ?? null,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ]);

        return response()->json($report);
    }

    private function encountersForUser(User $user, FoundationScope $scope): Builder
    {
        $query = ClinicalEncounter::query()->where('organization_id', $user->organization_id);
        if (! $user->can('branch.view_all')) {
            $query->whereIn('branch_id', $scope->branchIds($user));
        }

        return $query;
    }

    private function assertEncounterScope(
        User $user,
        ClinicalEncounter $encounter,
        FoundationScope $foundationScope,
        CustomerScope $customerScope,
    ): void {
        abort_unless((int) $encounter->organization_id === (int) $user->organization_id, 404);
        $foundationScope->assertBranch($user, Branch::query()->findOrFail($encounter->branch_id));
        $customerScope->assertCustomer($user, $encounter->customer, $foundationScope);
    }

    private function resolveTemplate(?int $templateId, User $user): ?ClinicalAssessmentTemplate
    {
        if ($templateId === null) {
            return null;
        }
        $template = ClinicalAssessmentTemplate::query()->findOrFail($templateId);
        abort_unless(
            (int) $template->organization_id === (int) $user->organization_id && $template->is_active,
            404,
        );

        return $template;
    }

    private function resolveAppointment(
        ?int $appointmentId,
        Customer $customer,
        Branch $branch,
        User $user,
    ): ?Appointment {
        if ($appointmentId === null) {
            return null;
        }
        $appointment = Appointment::query()->findOrFail($appointmentId);
        abort_unless(
            (int) $appointment->organization_id === (int) $user->organization_id
                && (int) $appointment->customer_id === (int) $customer->id
                && (int) $appointment->branch_id === (int) $branch->id
                && ! in_array($appointment->status, ['cancelled', 'no_show', 'rescheduled'], true),
            422,
            'The appointment must belong to this patient and branch and cannot be cancelled, missed, or rescheduled.',
        );

        return $appointment;
    }

    /**
     * @param  array<string, mixed>  $vitals
     */
    private function validateVitals(array $vitals): void
    {
        $unknown = array_diff(array_keys($vitals), array_keys(self::VITAL_LIMITS + ['pain_score' => [0, 10], 'notes' => []]));
        abort_if($unknown !== [], 422, 'Unsupported vital fields: '.implode(', ', $unknown));
        foreach (self::VITAL_LIMITS + ['pain_score' => [0, 10]] as $key => [$minimum, $maximum]) {
            if (array_key_exists($key, $vitals) && $vitals[$key] !== null) {
                abort_if(
                    ! is_numeric($vitals[$key]) || $vitals[$key] < $minimum || $vitals[$key] > $maximum,
                    422,
                    "Vital [{$key}] is outside the accepted range.",
                );
            }
        }
        if (isset($vitals['notes'])) {
            abort_if(! is_string($vitals['notes']) || mb_strlen($vitals['notes']) > 2000, 422, 'Vital notes are invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $assessment
     */
    private function validateAssessment(array $assessment, ClinicalAssessmentTemplate $template): void
    {
        $definitions = collect($template->fields)->keyBy('key');
        $unknown = array_diff(array_keys($assessment), $definitions->keys()->all());
        abort_if($unknown !== [], 422, 'Assessment contains fields not defined by the selected template.');
        foreach ($definitions as $key => $field) {
            $value = $assessment[$key] ?? null;
            if (($field['required'] ?? false) && ($value === null || $value === '')) {
                abort(422, "Assessment field [{$key}] is required.");
            }
            if ($value === null || $value === '') {
                continue;
            }
            $valid = match ($field['type']) {
                'text' => is_string($value) && mb_strlen($value) <= 5000,
                'number' => is_numeric($value),
                'boolean' => is_bool($value),
                'select' => is_string($value) && in_array($value, $field['options'] ?? [], true),
                default => false,
            };
            abort_unless($valid, 422, "Assessment field [{$key}] has an invalid value.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function clinicalState(ClinicalEncounter $encounter): array
    {
        return [
            'template_id' => $encounter->template_id,
            'vitals' => $encounter->vitals,
            'assessment' => $encounter->assessment,
            'clinical_note' => $encounter->clinical_note,
            'treatment_plan' => $encounter->treatment_plan,
            'follow_up_at' => $encounter->follow_up_at?->toIso8601String(),
            'follow_up_notes' => $encounter->follow_up_notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordAccess(
        Request $request,
        string $action,
        ClinicalEncounter|ClinicalAssessmentTemplate|null $subject,
        array $properties = [],
    ): void {
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $subject instanceof ClinicalEncounter
                ? $subject->branch_id
                : $request->user()->branch_id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'status_code' => 200,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => $properties,
            'occurred_at' => now('UTC'),
        ]);
    }
}
