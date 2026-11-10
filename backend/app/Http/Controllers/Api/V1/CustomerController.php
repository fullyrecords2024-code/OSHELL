<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranchHistory;
use App\Models\CustomerConsent;
use App\Models\Organization;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Authorization\CustomerScope;
use App\Support\Authorization\FoundationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    private const CONSENT_TYPES = [
        'privacy_notice',
        'treatment',
        'marketing',
        'clinical_photography',
    ];

    public function index(
        Request $request,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $customers = $customerScope->customers($request->user(), $foundationScope);

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $term = trim($filters['search']);
            $customers->where(static function (Builder $query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        return response()->json(
            $customers->with(['registeredBranch', 'branchHistory.branch', 'patientProfile', 'portalUser'])
                ->orderBy('name')
                ->paginate(25)
                ->through(fn (Customer $customer): array => $this->customerPayload($customer, $request->user()))
        );
    }

    public function store(
        Request $request,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $actor = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'preferred_channel' => ['sometimes', Rule::in(['email', 'sms', 'phone', 'whatsapp', 'none'])],
            'registration_source' => ['sometimes', Rule::in(['walk_in', 'referral', 'website', 'phone', 'other'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_patient' => ['sometimes', 'boolean'],
            'patient.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'patient.emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'patient.emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'patient.emergency_contact_relationship' => ['nullable', 'string', 'max:60'],
            'activate_portal' => ['sometimes', 'boolean'],
        ]);

        $branchId = $data['branch_id'] ?? $actor->branch_id;
        $branch = $branchId === null ? null : Branch::query()->findOrFail($branchId);
        if ($branch) {
            $foundationScope->assertBranch($actor, $branch);
        } elseif (! $actor->can('branch.view_all')) {
            abort(403);
        }

        $preferredChannel = $data['preferred_channel'] ?? 'none';
        if ($preferredChannel === 'email' && empty($data['email'])) {
            throw ValidationException::withMessages([
                'email' => ['An email address is required for the selected contact preference.'],
            ]);
        }
        if (in_array($preferredChannel, ['sms', 'phone', 'whatsapp'], true) && empty($data['phone'])) {
            throw ValidationException::withMessages([
                'phone' => ['A phone number is required for the selected contact preference.'],
            ]);
        }
        if (! empty($data['is_patient']) && ! $actor->can('patients.register')) {
            abort(403);
        }
        if (! empty($data['activate_portal'])) {
            abort_unless($actor->can('customer.portal.activate'), 403);
            if (empty($data['email'])) {
                throw ValidationException::withMessages([
                    'email' => ['An email address is required to activate a portal account.'],
                ]);
            }
        }

        $name = trim(preg_replace('/\s+/', ' ', $data['name']) ?? $data['name']);
        $normalizedName = mb_strtolower($name);
        $normalizedEmail = isset($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        $normalizedPhone = isset($data['phone'])
            ? preg_replace('/\D+/', '', $data['phone'])
            : null;

        /** @var array{Customer, bool, User|null} $registration */
        $registration = DB::transaction(function () use (
            $actor,
            $branch,
            $data,
            $name,
            $normalizedName,
            $normalizedEmail,
            $normalizedPhone,
        ): array {
            Organization::query()
                ->whereKey($actor->organization_id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasEmail = $normalizedEmail !== null && $normalizedEmail !== '';
            $hasPhone = $normalizedPhone !== null && $normalizedPhone !== '';
            $duplicate = ($hasEmail || $hasPhone)
                ? Customer::query()
                    ->where('organization_id', $actor->organization_id)
                    ->whereNull('merged_into_id')
                    ->where(static function (Builder $query) use (
                        $hasEmail,
                        $hasPhone,
                        $normalizedEmail,
                        $normalizedName,
                        $normalizedPhone,
                    ): void {
                        if ($hasEmail) {
                            $query->where('normalized_email', $normalizedEmail);
                        }
                        if ($hasPhone) {
                            $method = $hasEmail ? 'orWhere' : 'where';
                            $query->{$method}(static fn (Builder $identityQuery) => $identityQuery
                                ->where('normalized_name', $normalizedName)
                                ->where('normalized_phone', $normalizedPhone));
                        }
                    })
                    ->first()
                : null;

            if ($duplicate) {
                return [$duplicate, true, null];
            }

            $customer = Customer::create([
                'organization_id' => $actor->organization_id,
                'registered_branch_id' => $branch?->id,
                'registered_by' => $actor->id,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'email' => $normalizedEmail,
                'normalized_email' => $normalizedEmail,
                'phone' => isset($data['phone']) ? trim($data['phone']) : null,
                'normalized_phone' => $normalizedPhone,
                'preferred_channel' => $data['preferred_channel'] ?? 'none',
                'registration_source' => $data['registration_source'] ?? 'walk_in',
            ]);

            if ($branch) {
                $customer->branchHistory()->create([
                    'organization_id' => $actor->organization_id,
                    'branch_id' => $branch->id,
                    'recorded_by' => $actor->id,
                    'activity' => 'registered',
                    'occurred_at' => now('UTC'),
                ]);
            }

            if (! empty($data['is_patient'])) {
                PatientProfile::create([
                    'organization_id' => $actor->organization_id,
                    'customer_id' => $customer->id,
                    'patient_number' => 'P-'.Str::upper((string) Str::ulid()),
                    'date_of_birth' => data_get($data, 'patient.date_of_birth'),
                    'emergency_contact_name' => data_get($data, 'patient.emergency_contact_name'),
                    'emergency_contact_phone' => data_get($data, 'patient.emergency_contact_phone'),
                    'emergency_contact_relationship' => data_get($data, 'patient.emergency_contact_relationship'),
                ]);
            }

            $portalUser = null;
            if (! empty($data['activate_portal'])) {
                if (User::query()->where('email', $normalizedEmail)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => ['An account already uses this email address.'],
                    ]);
                }

                $portalUser = User::create([
                    'name' => $name,
                    'email' => $normalizedEmail,
                    'password' => Hash::make(Str::random(64)),
                    'organization_id' => $actor->organization_id,
                    'branch_id' => $branch?->id,
                    'customer_id' => $customer->id,
                    'is_active' => true,
                    'password_reset_required' => true,
                ]);
                $portalUser->assignRole('Customer / Patient');
            }

            return [$customer, false, $portalUser];
        });
        [$customer, $wasDuplicate, $portalUser] = $registration;

        if ($wasDuplicate) {
            $customerScope->assertCustomer($actor, $customer, $foundationScope);

            return response()->json([
                'message' => 'A possible duplicate customer already exists. Review the matching profile before registering another.',
                'duplicate_customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ],
            ], 409);
        }

        if ($portalUser instanceof User) {
            $portalUser->sendEmailVerificationNotification();
            $portalUser->sendPasswordResetNotification(Password::broker()->createToken($portalUser));
        }

        $customer->load(['registeredBranch', 'branchHistory.branch']);

        return response()->json($this->customerPayload($customer, $actor), 201);
    }

    public function show(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $customerScope->assertCustomer($request->user(), $customer, $foundationScope);
        abort_if($customer->merged_into_id !== null, 410, 'This customer profile has been merged.');

        $customer->load(['registeredBranch', 'branchHistory.branch']);
        $this->recordPrivateAccess($request, $customer, 'customer.profile.view');

        return response()->json($this->customerPayload($customer, $request->user(), true));
    }

    public function activity(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $viewer = $request->user();
        $customerScope->assertCustomer($viewer, $customer, $foundationScope);
        abort_if($customer->merged_into_id !== null, 410, 'This customer profile has been merged.');

        $organizationId = (int) $customer->organization_id;
        $orgBranchIds = Branch::query()
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $branchIds = $viewer->can('branch.view_all')
            ? $orgBranchIds
            : array_values(array_intersect($foundationScope->branchIds($viewer), $orgBranchIds));
        $hasBranchScope = $branchIds !== [];

        $canViewAppointments = $hasBranchScope && $viewer->can('appointments.view');
        $canViewOrders = $hasBranchScope && $viewer->can('ecommerce.orders.view');
        $canViewPos = $hasBranchScope && $viewer->can('pos.view');
        $canViewClinical = $hasBranchScope && $viewer->can('clinical.view');
        $canViewLoyalty = $viewer->can('loyalty.manage');
        $canManageAppointments = $hasBranchScope && $viewer->can('appointments.create');
        $canManageAppointmentStatus = $hasBranchScope && $viewer->can('appointments.manage');
        $canManageAppointmentDeposits = $hasBranchScope && $viewer->can('appointments.deposit.manage');
        $canManageOrders = $hasBranchScope && $viewer->can('ecommerce.orders.manage');
        $canManageReturns = $hasBranchScope && $viewer->can('ecommerce.returns.manage');
        $canManagePosRefunds = $hasBranchScope && $viewer->can('pos.refund.approve');
        $canManageClinical = $hasBranchScope && $viewer->can('clinical.manage');
        $canAmendClinical = $canManageClinical && $viewer->can('clinical.amend');
        $canAdjustLoyalty = $viewer->can('loyalty.adjust');
        $canReconcilePayments = $hasBranchScope && $viewer->can('payments.reconcile');

        $summary = [
            'appointments_total' => 0,
            'appointments_upcoming' => 0,
            'orders_total' => 0,
            'orders_paid_total' => 0.0,
            'pos_sales_total' => 0,
            'pos_spend_total' => 0.0,
            'payments_total' => 0,
            'payments_paid_total' => 0.0,
            'clinical_encounters_total' => 0,
            'treatment_plans_total' => 0,
            'loyalty_points' => 0,
        ];
        $appointments = [];
        $orders = [];
        $posSales = [];
        $payments = [];
        $clinicalEncounters = [];

        if ($canViewAppointments) {
            $appointmentQuery = DB::table('appointments')
                ->where('appointments.organization_id', $organizationId)
                ->where('appointments.customer_id', $customer->id)
                ->whereIn('appointments.branch_id', $branchIds);
            $summary['appointments_total'] = (clone $appointmentQuery)->count();
            $summary['appointments_upcoming'] = (clone $appointmentQuery)
                ->where('appointments.starts_at', '>=', now('UTC'))
                ->whereNotIn('appointments.status', ['completed', 'cancelled', 'no_show', 'rescheduled'])
                ->count();
            $appointments = (clone $appointmentQuery)
                ->leftJoin('services', 'services.id', '=', 'appointments.service_id')
                ->leftJoin('branches', 'branches.id', '=', 'appointments.branch_id')
                ->orderByDesc('appointments.starts_at')
                ->limit(50)
                ->get([
                    'appointments.id',
                    'appointments.appointment_number',
                    'appointments.branch_id',
                    'branches.name as branch_name',
                    'appointments.service_id',
                    'services.name as service_name',
                    'appointments.status',
                    'appointments.booking_channel',
                    'appointments.starts_at',
                    'appointments.ends_at',
                    'appointments.service_price',
                    'appointments.currency_code',
                    'appointments.deposit_due',
                    'appointments.deposit_status',
                    'appointments.completed_at',
                    'appointments.cancelled_at',
                ])
                ->map(static fn (object $appointment): array => (array) $appointment)
                ->all();
        }

        if ($canViewOrders) {
            $orderQuery = DB::table('ecommerce_orders')
                ->where('ecommerce_orders.organization_id', $organizationId)
                ->where('ecommerce_orders.customer_id', $customer->id)
                ->whereIn('ecommerce_orders.branch_id', $branchIds);
            $summary['orders_total'] = (clone $orderQuery)->count();
            $summary['orders_paid_total'] = round((float) (clone $orderQuery)
                ->where('ecommerce_orders.payment_status', 'paid')
                ->where('ecommerce_orders.status', '!=', 'cancelled')
                ->sum('ecommerce_orders.total_amount'), 2);
            $orderRows = (clone $orderQuery)
                ->leftJoin('branches', 'branches.id', '=', 'ecommerce_orders.branch_id')
                ->orderByDesc('ecommerce_orders.created_at')
                ->limit(50)
                ->get([
                    'ecommerce_orders.id',
                    'ecommerce_orders.order_number',
                    'ecommerce_orders.branch_id',
                    'branches.name as branch_name',
                    'ecommerce_orders.status',
                    'ecommerce_orders.payment_status',
                    'ecommerce_orders.fulfillment_type',
                    'ecommerce_orders.currency_code',
                    'ecommerce_orders.subtotal',
                    'ecommerce_orders.delivery_fee',
                    'ecommerce_orders.discount_amount',
                    'ecommerce_orders.total_amount',
                    'ecommerce_orders.return_status',
                    'ecommerce_orders.return_reason',
                    'ecommerce_orders.paid_at',
                    'ecommerce_orders.created_at',
                ]);
            $orderLines = DB::table('ecommerce_order_lines')
                ->whereIn('order_id', $orderRows->pluck('id'))
                ->get(['order_id', 'name_snapshot', 'sku_snapshot', 'quantity', 'unit_price', 'line_total'])
                ->groupBy('order_id');
            $orders = $orderRows
                ->map(static fn (object $order): array => [
                    ...(array) $order,
                    'lines' => $orderLines->get($order->id, collect())->values()->all(),
                ])
                ->all();
        }

        if ($canViewPos) {
            $saleQuery = DB::table('pos_sales')
                ->where('pos_sales.organization_id', $organizationId)
                ->where('pos_sales.customer_id', $customer->id)
                ->whereIn('pos_sales.branch_id', $branchIds);
            $summary['pos_sales_total'] = (clone $saleQuery)->count();
            $summary['pos_spend_total'] = round((float) (clone $saleQuery)
                ->where('pos_sales.status', 'completed')
                ->sum('pos_sales.total_amount'), 2);
            $saleRows = (clone $saleQuery)
                ->leftJoin('branches', 'branches.id', '=', 'pos_sales.branch_id')
                ->leftJoin('users as cashiers', 'cashiers.id', '=', 'pos_sales.cashier_id')
                ->orderByDesc('pos_sales.sold_at')
                ->limit(50)
                ->get([
                    'pos_sales.id',
                    'pos_sales.receipt_number',
                    'pos_sales.branch_id',
                    'branches.name as branch_name',
                    'pos_sales.status',
                    'pos_sales.currency_code',
                    'pos_sales.subtotal',
                    'pos_sales.discount_amount',
                    'pos_sales.tax_amount',
                    'pos_sales.total_amount',
                    'pos_sales.refunded_amount',
                    'pos_sales.loyalty_points_redeemed',
                    'cashiers.name as cashier_name',
                    'pos_sales.sold_at',
                ]);
            $saleIds = $saleRows->pluck('id');
            $saleLines = DB::table('pos_sale_lines')
                ->whereIn('sale_id', $saleIds)
                ->get(['sale_id', 'item_type', 'item_name', 'item_code', 'quantity', 'unit_price', 'line_total'])
                ->groupBy('sale_id');
            $salePayments = DB::table('pos_sale_payments')
                ->whereIn('sale_id', $saleIds)
                ->get(['sale_id', 'tender_type', 'amount', 'reference', 'recorded_at'])
                ->groupBy('sale_id');
            $posSales = $saleRows
                ->map(static fn (object $sale): array => [
                    ...(array) $sale,
                    'lines' => $saleLines->get($sale->id, collect())->values()->all(),
                    'payments' => $salePayments->get($sale->id, collect())->values()->all(),
                ])
                ->all();
        }

        $onlinePayments = [];
        if ($canViewOrders) {
            $paymentQuery = DB::table('payment_transactions')
                ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'payment_transactions.ecommerce_order_id')
                ->where('payment_transactions.organization_id', $organizationId)
                ->whereIn('payment_transactions.branch_id', $branchIds)
                ->where('ecommerce_orders.customer_id', $customer->id);
            $summary['payments_total'] += (clone $paymentQuery)->count();
            $summary['payments_paid_total'] += (float) (clone $paymentQuery)
                ->where('payment_transactions.status', 'paid')
                ->sum('payment_transactions.amount');
            $onlinePayments = (clone $paymentQuery)
                ->orderByDesc('payment_transactions.created_at')
                ->limit(100)
                ->get([
                    'payment_transactions.id',
                    'payment_transactions.provider',
                    'payment_transactions.method',
                    'payment_transactions.status',
                    'payment_transactions.currency_code',
                    'payment_transactions.amount',
                    'payment_transactions.merchant_reference',
                    'payment_transactions.provider_reference',
                    'payment_transactions.settled_at',
                    'payment_transactions.paid_at',
                    'payment_transactions.created_at',
                    'ecommerce_orders.order_number',
                ])
                ->map(static fn (object $payment): array => [
                    'key' => 'online-'.$payment->id,
                    'transaction_id' => (int) $payment->id,
                    'source' => 'online_order',
                    'source_label' => 'Online order',
                    'reference' => $payment->merchant_reference,
                    'order_number' => $payment->order_number,
                    'receipt_number' => null,
                    'provider' => $payment->provider,
                    'method' => $payment->method,
                    'status' => $payment->status,
                    'currency_code' => $payment->currency_code,
                    'amount' => $payment->amount,
                    'settled_at' => $payment->settled_at,
                    'occurred_at' => $payment->paid_at ?? $payment->created_at,
                ])
                ->all();
        }

        $posTenders = [];
        if ($canViewPos) {
            $tenderQuery = DB::table('pos_sale_payments')
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_payments.sale_id')
                ->where('pos_sales.organization_id', $organizationId)
                ->where('pos_sales.customer_id', $customer->id)
                ->whereIn('pos_sales.branch_id', $branchIds);
            $summary['payments_total'] += (clone $tenderQuery)->count();
            $summary['payments_paid_total'] += (float) (clone $tenderQuery)->sum('pos_sale_payments.amount');
            $posTenders = (clone $tenderQuery)
                ->orderByDesc('pos_sale_payments.recorded_at')
                ->limit(100)
                ->get([
                    'pos_sale_payments.id',
                    'pos_sale_payments.sale_id',
                    'pos_sale_payments.tender_type',
                    'pos_sale_payments.amount',
                    'pos_sale_payments.reference',
                    'pos_sale_payments.recorded_at',
                    'pos_sales.receipt_number',
                    'pos_sales.currency_code',
                ])
                ->map(static fn (object $payment): array => [
                    'key' => 'pos-'.$payment->id,
                    'transaction_id' => null,
                    'source' => 'pos_sale',
                    'source_label' => 'POS sale',
                    'reference' => $payment->reference,
                    'order_number' => null,
                    'receipt_number' => $payment->receipt_number,
                    'provider' => 'in_store',
                    'method' => $payment->tender_type,
                    'status' => 'paid',
                    'currency_code' => $payment->currency_code,
                    'amount' => $payment->amount,
                    'settled_at' => null,
                    'occurred_at' => $payment->recorded_at,
                ])
                ->all();
        }

        $payments = collect($onlinePayments)
            ->concat($posTenders)
            ->sortByDesc(static fn (array $payment): string => (string) ($payment['occurred_at'] ?? ''))
            ->take(100)
            ->values()
            ->all();
        $summary['payments_paid_total'] = round($summary['payments_paid_total'], 2);

        if ($canViewClinical) {
            $encounterQuery = DB::table('clinical_encounters')
                ->where('clinical_encounters.organization_id', $organizationId)
                ->where('clinical_encounters.customer_id', $customer->id)
                ->whereIn('clinical_encounters.branch_id', $branchIds);
            $summary['clinical_encounters_total'] = (clone $encounterQuery)->count();
            $summary['treatment_plans_total'] = (clone $encounterQuery)
                ->whereNotNull('clinical_encounters.treatment_plan')
                ->where('clinical_encounters.treatment_plan', '!=', '')
                ->count();
            $clinicalEncounters = (clone $encounterQuery)
                ->leftJoin('branches', 'branches.id', '=', 'clinical_encounters.branch_id')
                ->leftJoin('users as clinicians', 'clinicians.id', '=', 'clinical_encounters.created_by')
                ->leftJoin('appointments', 'appointments.id', '=', 'clinical_encounters.appointment_id')
                ->orderByDesc('clinical_encounters.started_at')
                ->limit(50)
                ->get([
                    'clinical_encounters.id',
                    'clinical_encounters.branch_id',
                    'branches.name as branch_name',
                    'clinical_encounters.appointment_id',
                    'appointments.appointment_number',
                    'clinical_encounters.status',
                    'clinical_encounters.vitals',
                    'clinical_encounters.assessment',
                    'clinical_encounters.clinical_note',
                    'clinical_encounters.treatment_plan',
                    'clinical_encounters.follow_up_at',
                    'clinical_encounters.follow_up_notes',
                    'clinical_encounters.started_at',
                    'clinical_encounters.completed_at',
                    'clinicians.name as clinician_name',
                ])
                ->map(static fn (object $encounter): array => (array) $encounter)
                ->all();
        }

        $loyalty = null;
        if ($canViewLoyalty) {
            $account = DB::table('loyalty_accounts')
                ->where('organization_id', $organizationId)
                ->where('customer_id', $customer->id)
                ->first(['id', 'points_balance', 'tier', 'created_at']);
            $summary['loyalty_points'] = $account === null ? 0 : (int) $account->points_balance;
            $loyalty = [
                'account' => $account === null ? null : (array) $account,
                'entries' => $account === null ? [] : DB::table('loyalty_ledger_entries')
                    ->where('account_id', $account->id)
                    ->orderByDesc('created_at')
                    ->limit(50)
                    ->get(['event_type', 'points_delta', 'source_type', 'reason', 'created_at'])
                    ->map(static fn (object $entry): array => (array) $entry)
                    ->all(),
            ];
        }

        $this->recordPrivateAccess($request, $customer, 'customer.activity.view');

        return response()->json([
            'customer_id' => $customer->id,
            'patient_profile_linked' => $customer->patientProfile()->exists(),
            'capabilities' => [
                'appointments' => $canViewAppointments,
                'orders' => $canViewOrders,
                'pos_sales' => $canViewPos,
                'payments' => $canViewOrders || $canViewPos,
                'clinical' => $canViewClinical,
                'loyalty' => $canViewLoyalty,
                'manage' => [
                    'book_appointments' => $canManageAppointments,
                    'appointment_status' => $canManageAppointmentStatus,
                    'appointment_deposits' => $canManageAppointmentDeposits,
                    'orders' => $canManageOrders,
                    'returns' => $canManageReturns,
                    'pos_refunds' => $canManagePosRefunds,
                    'clinical' => $canManageClinical,
                    'clinical_amendments' => $canAmendClinical,
                    'loyalty' => $canAdjustLoyalty,
                    'payments' => $canReconcilePayments,
                ],
            ],
            'summary' => $summary,
            'appointments' => $appointments,
            'orders' => $orders,
            'pos_sales' => $posSales,
            'payments' => $payments,
            'clinical_encounters' => $clinicalEncounters,
            'loyalty' => $loyalty,
        ]);
    }

    public function update(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $customerScope->assertCustomer($request->user(), $customer, $foundationScope);
        abort_if($customer->merged_into_id !== null, 410, 'This customer profile has been merged.');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'preferred_channel' => ['sometimes', Rule::in(['email', 'sms', 'phone', 'whatsapp', 'none'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $name = isset($data['name'])
            ? trim(preg_replace('/\s+/', ' ', $data['name']) ?? $data['name'])
            : $customer->name;
        $email = array_key_exists('email', $data)
            ? (isset($data['email']) ? mb_strtolower(trim($data['email'])) : null)
            : $customer->normalized_email;
        $phone = array_key_exists('phone', $data)
            ? (isset($data['phone']) ? trim($data['phone']) : null)
            : $customer->phone;
        $preferredChannel = $data['preferred_channel'] ?? $customer->preferred_channel;

        if ($preferredChannel === 'email' && empty($email)) {
            throw ValidationException::withMessages([
                'email' => ['An email address is required for the selected contact preference.'],
            ]);
        }
        if (in_array($preferredChannel, ['sms', 'phone', 'whatsapp'], true) && empty($phone)) {
            throw ValidationException::withMessages([
                'phone' => ['A phone number is required for the selected contact preference.'],
            ]);
        }
        if (
            array_key_exists('email', $data)
            && $email !== $customer->normalized_email
            && $customer->portalUser()->exists()
        ) {
            throw ValidationException::withMessages([
                'email' => ['Portal-linked email changes must be completed through a verified account recovery process.'],
            ]);
        }

        DB::transaction(function () use ($customer, $name, $email, $phone, $data): void {
            Organization::query()
                ->whereKey($customer->organization_id)
                ->lockForUpdate()
                ->firstOrFail();

            $normalizedName = mb_strtolower($name);
            $normalizedPhone = $phone === null ? null : preg_replace('/\D+/', '', $phone);
            $hasEmail = $email !== null && $email !== '';
            $hasPhone = $normalizedPhone !== null && $normalizedPhone !== '';
            $duplicate = false;
            if ($hasEmail || $hasPhone) {
                $duplicate = Customer::query()
                    ->where('organization_id', $customer->organization_id)
                    ->whereNull('merged_into_id')
                    ->where('id', '!=', $customer->id)
                    ->where(static function (Builder $query) use (
                        $email,
                        $hasEmail,
                        $hasPhone,
                        $normalizedName,
                        $normalizedPhone,
                    ): void {
                        if ($hasEmail) {
                            $query->where('normalized_email', $email);
                        }
                        if ($hasPhone) {
                            $method = $hasEmail ? 'orWhere' : 'where';
                            $query->{$method}(static fn (Builder $identityQuery) => $identityQuery
                                ->where('normalized_name', $normalizedName)
                                ->where('normalized_phone', $normalizedPhone));
                        }
                    })
                    ->exists();
            }
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'email' => ['The updated contact details match another customer. Review the possible duplicate first.'],
                ]);
            }

            $customer->update([
                'name' => $name,
                'normalized_name' => $normalizedName,
                'email' => $email,
                'normalized_email' => $email,
                'phone' => $phone,
                'normalized_phone' => $normalizedPhone,
                'preferred_channel' => $data['preferred_channel'] ?? $customer->preferred_channel,
                'status' => $data['status'] ?? $customer->status,
            ]);
        });

        if (isset($data['status'])) {
            if ($data['status'] === 'inactive') {
                $customer->portalUser()->update(['is_active' => false]);
                $customer->portalUser?->tokens()->delete();
            } else {
                $customer->portalUser()->update(['is_active' => true]);
            }
        }

        return response()->json($this->customerPayload(
            $customer->refresh()->load(['registeredBranch', 'branchHistory.branch']),
            $request->user(),
        ));
    }

    public function recordBranchVisit(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $actor = $request->user();
        $customerScope->assertCustomer($actor, $customer, $foundationScope);
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'occurred_at' => ['sometimes', 'date'],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $foundationScope->assertBranch($actor, $branch);
        abort_unless($actor->can('customers.history.record'), 403);

        $history = $customer->branchHistory()->create([
            'organization_id' => $actor->organization_id,
            'branch_id' => $branch->id,
            'recorded_by' => $actor->id,
            'activity' => 'visit',
            'occurred_at' => $data['occurred_at'] ?? now('UTC'),
        ]);

        return response()->json($history->load('branch'), 201);
    }

    public function storeConsent(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $actor = $request->user();
        $customerScope->assertCustomer($actor, $customer, $foundationScope);
        abort_unless($actor->can('consents.manage'), 403);

        $data = $request->validate([
            'consent_type' => ['required', Rule::in(self::CONSENT_TYPES)],
            'status' => ['required', Rule::in(['granted', 'declined', 'revoked'])],
            'capture_method' => ['required', Rule::in(['signed_form', 'verbal', 'electronic', 'other'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'captured_at' => ['sometimes', 'date'],
            'expires_at' => ['nullable', 'date', 'after:captured_at'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        if (
            $data['consent_type'] === 'clinical_photography'
            && ! $actor->can('patients.manage')
        ) {
            abort(403);
        }

        $branchId = $data['branch_id'] ?? $customer->registered_branch_id ?? $actor->branch_id;
        if ($branchId !== null) {
            $foundationScope->assertBranch($actor, Branch::query()->findOrFail($branchId));
        }

        $documentPath = null;
        /** @var UploadedFile|null $document */
        $document = $request->file('document');
        if ($document) {
            $documentPath = $document->store(
                "customer-consents/{$actor->organization_id}/{$customer->id}",
                'local',
            );
            if ($documentPath === false) {
                throw new \RuntimeException('The consent document could not be stored securely.');
            }
        }

        $consent = CustomerConsent::create([
            'organization_id' => $actor->organization_id,
            'customer_id' => $customer->id,
            'branch_id' => $branchId,
            'captured_by' => $actor->id,
            'consent_type' => $data['consent_type'],
            'status' => $data['status'],
            'capture_method' => $data['capture_method'],
            'document_path' => $documentPath,
            'captured_at' => $data['captured_at'] ?? now('UTC'),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json($this->consentPayload($consent), 201);
    }

    public function consentDocument(
        Request $request,
        Customer $customer,
        CustomerConsent $consent,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): StreamedResponse {
        $actor = $request->user();
        $customerScope->assertCustomer($actor, $customer, $foundationScope);
        abort_unless($actor->can('consents.view'), 403);
        abort_unless((int) $consent->customer_id === (int) $customer->id, 404);
        if ($consent->consent_type === 'clinical_photography') {
            abort_unless($actor->can('patients.view'), 403);
        }
        abort_unless(
            $consent->document_path !== null
            && Storage::disk('local')->exists($consent->document_path),
            404,
        );

        $this->recordPrivateAccess($request, $customer, 'customer.consent-document.view', [
            'consent_type' => $consent->consent_type,
        ]);

        return Storage::disk('local')->download(
            $consent->document_path,
            "consent-{$consent->id}.".pathinfo($consent->document_path, PATHINFO_EXTENSION),
        );
    }

    public function patientProfile(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $customerScope->assertCustomer($request->user(), $customer, $foundationScope);
        abort_unless($request->user()->can('patients.view'), 403);
        abort_if($customer->merged_into_id !== null, 410, 'This customer profile has been merged.');
        $profile = $customer->patientProfile;
        abort_if($profile === null, 404, 'No patient profile is linked to this customer.');

        $this->recordPrivateAccess($request, $customer, 'patient.profile.view');

        return response()->json($this->patientPayload($profile));
    }

    public function updatePatientProfile(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $actor = $request->user();
        $customerScope->assertCustomer($actor, $customer, $foundationScope);
        abort_unless($actor->can('patients.manage'), 403);
        abort_if($customer->merged_into_id !== null, 410, 'This customer profile has been merged.');

        $data = $request->validate([
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $profile = PatientProfile::query()->firstOrCreate(
            ['customer_id' => $customer->id],
            [
                'organization_id' => $actor->organization_id,
                'patient_number' => 'P-'.Str::upper((string) Str::ulid()),
            ],
        );
        $profile->fill($data)->save();

        return response()->json($this->patientPayload($profile->refresh()));
    }

    public function merge(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor->can('customers.merge'), 403);
        $customerScope->assertCustomer($actor, $customer, $foundationScope);
        abort_if($customer->merged_into_id !== null, 410, 'This customer profile has already been merged.');
        $data = $request->validate([
            'target_customer_id' => ['required', 'integer', 'exists:customers,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        if ((int) $data['target_customer_id'] === (int) $customer->id) {
            throw ValidationException::withMessages([
                'target_customer_id' => ['Choose a different customer profile to retain.'],
            ]);
        }

        $target = Customer::query()->findOrFail($data['target_customer_id']);
        $customerScope->assertCustomer($actor, $target, $foundationScope);
        if ((int) $target->organization_id !== (int) $customer->organization_id) {
            abort(404);
        }

        DB::transaction(function () use ($customer, $target): void {
            $source = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $canonical = Customer::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            abort_if(
                $source->merged_into_id !== null || $canonical->merged_into_id !== null,
                409,
                'Merged profiles cannot be merged again.',
            );
            if (
                $source->patientProfile()->exists()
                && $canonical->patientProfile()->exists()
            ) {
                throw ValidationException::withMessages([
                    'target_customer_id' => ['Both profiles have patient records. Resolve the patient records before merging.'],
                ]);
            }
            if ($source->portalUser()->exists() && $canonical->portalUser()->exists()) {
                throw ValidationException::withMessages([
                    'target_customer_id' => ['Both profiles have portal accounts. Resolve the linked accounts before merging.'],
                ]);
            }

            $source->branchHistory()->update(['customer_id' => $canonical->id]);
            $source->consents()->update(['customer_id' => $canonical->id]);
            $source->patientProfile()->update(['customer_id' => $canonical->id]);
            $source->portalUser()->update(['customer_id' => $canonical->id]);
            $source->forceFill([
                'merged_into_id' => $canonical->id,
                'status' => 'merged',
                'normalized_email' => null,
            ])->save();
        });

        AuditEvent::create([
            'user_id' => $actor->id,
            'organization_id' => $actor->organization_id,
            'branch_id' => $customer->registered_branch_id ?? $actor->branch_id,
            'action' => 'customer.identity.merge',
            'subject_type' => Customer::class,
            'subject_id' => (string) $customer->id,
            'status_code' => 200,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => [
                'target_customer_id' => $target->id,
                'reason' => $data['reason'],
            ],
            'occurred_at' => now('UTC'),
        ]);

        return response()->json([
            'message' => 'Customer profiles merged. The source record is retained for audit and linked to the retained profile.',
            'customer' => $this->customerPayload(
                $target->refresh()->load(['registeredBranch', 'branchHistory.branch']),
                $actor,
            ),
        ]);
    }

    public function activatePortal(
        Request $request,
        Customer $customer,
        CustomerScope $customerScope,
        FoundationScope $foundationScope,
    ): JsonResponse {
        $actor = $request->user();
        $customerScope->assertCustomer($actor, $customer, $foundationScope);
        abort_unless($actor->can('customer.portal.activate'), 403);
        abort_if($customer->merged_into_id !== null, 410, 'This customer profile has been merged.');

        if (! $customer->email) {
            throw ValidationException::withMessages([
                'email' => ['A customer email address is required to activate a portal account.'],
            ]);
        }
        if ($customer->portalUser()->exists()) {
            return response()->json(['message' => 'A portal account is already linked.'], 409);
        }
        if (User::query()->where('email', $customer->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['An account already uses this email address.'],
            ]);
        }

        $portalUser = DB::transaction(function () use ($customer): User {
            $user = User::create([
                'name' => $customer->name,
                'email' => $customer->email,
                'password' => Hash::make(Str::random(64)),
                'organization_id' => $customer->organization_id,
                'branch_id' => $customer->registered_branch_id,
                'customer_id' => $customer->id,
                'is_active' => true,
                'password_reset_required' => true,
            ]);
            $user->assignRole('Customer / Patient');

            return $user;
        });

        $portalUser->sendEmailVerificationNotification();
        $portalUser->sendPasswordResetNotification(Password::broker()->createToken($portalUser));

        return response()->json(['message' => 'Portal activation instructions were sent to the customer email.'], 202);
    }

    public function portalProfile(Request $request): JsonResponse
    {
        $customer = $request->user()->customer;
        abort_if($customer === null || $customer->merged_into_id !== null || $customer->status !== 'active', 404);

        return response()->json([
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'preferred_channel' => $customer->preferred_channel,
            'status' => $customer->status,
        ]);
    }

    public function updatePortalProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $customer = $user->customer;
        abort_if($customer === null || $customer->merged_into_id !== null || $customer->status !== 'active', 404);
        $data = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'preferred_channel' => ['sometimes', Rule::in(['email', 'sms', 'phone', 'whatsapp', 'none'])],
        ]);
        $phone = array_key_exists('phone', $data)
            ? (isset($data['phone']) ? trim($data['phone']) : null)
            : $customer->phone;
        $preferredChannel = $data['preferred_channel'] ?? $customer->preferred_channel;
        if ($preferredChannel === 'email' && ! $customer->email) {
            throw ValidationException::withMessages([
                'preferred_channel' => ['This account has no email address to use for contact.'],
            ]);
        }
        if (in_array($preferredChannel, ['sms', 'phone', 'whatsapp'], true) && ! $phone) {
            throw ValidationException::withMessages([
                'phone' => ['A phone number is required for the selected contact preference.'],
            ]);
        }

        $customer->update([
            'phone' => $phone,
            'normalized_phone' => $phone === null ? null : preg_replace('/\D+/', '', $phone),
            'preferred_channel' => $preferredChannel,
        ]);

        return response()->json([
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'preferred_channel' => $customer->preferred_channel,
            'status' => $customer->status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(Customer $customer, User $viewer, bool $includeConsents = false): array
    {
        $payload = $customer->only([
            'id',
            'name',
            'email',
            'phone',
            'preferred_channel',
            'status',
            'registration_source',
            'registered_branch_id',
            'created_at',
            'updated_at',
        ]);
        $registeredBranchId = $customer->registered_branch_id;
        $canViewRegisteredBranch = $registeredBranchId === null
            || $viewer->can('branch.view_all')
            || in_array(
                (int) $registeredBranchId,
                app(FoundationScope::class)->branchIds($viewer),
                true,
            );
        if (! $canViewRegisteredBranch) {
            $payload['registered_branch_id'] = null;
        }
        $payload['registered_branch'] = $canViewRegisteredBranch
            ? $customer->registeredBranch?->only(['id', 'name', 'code'])
            : null;
        $payload['branch_history'] = $customer->branchHistory
            ->filter(fn (CustomerBranchHistory $history): bool => $viewer->can('customers.view_all')
                || in_array((int) $history->branch_id, app(FoundationScope::class)->branchIds($viewer), true))
            ->map(static fn (CustomerBranchHistory $history): array => [
                'id' => $history->id,
                'activity' => $history->activity,
                'occurred_at' => $history->occurred_at,
                'branch' => $history->branch?->only(['id', 'name', 'code']),
            ])->values();
        if ($viewer->can('patients.view')) {
            $patient = $customer->patientProfile;
            $payload['patient_profile_linked'] = $patient !== null;
            if ($patient) {
                $payload['patient_profile'] = $this->patientPayload($patient);
            }
        }
        if ($includeConsents && $viewer->can('consents.view')) {
            $payload['consents'] = $customer->consents
                ->filter(fn (CustomerConsent $consent): bool => $consent->consent_type !== 'clinical_photography'
                    || $viewer->can('patients.view'))
                ->map(fn (CustomerConsent $consent): array => $this->consentPayload($consent))
                ->values();
        }
        $payload['portal_linked'] = $customer->portalUser !== null;
        $payload['portal_active'] = $customer->portalUser !== null && $customer->portalUser->is_active;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function patientPayload(PatientProfile $profile): array
    {
        return $profile->only([
            'id',
            'patient_number',
            'date_of_birth',
            'emergency_contact_name',
            'emergency_contact_phone',
            'emergency_contact_relationship',
            'is_active',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function consentPayload(CustomerConsent $consent): array
    {
        return [
            'id' => $consent->id,
            'consent_type' => $consent->consent_type,
            'status' => $consent->status,
            'capture_method' => $consent->capture_method,
            'branch_id' => $consent->branch_id,
            'captured_at' => $consent->captured_at,
            'expires_at' => $consent->expires_at,
            'document_available' => $consent->document_path !== null,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $properties
     */
    private function recordPrivateAccess(
        Request $request,
        Customer $customer,
        string $action,
        array $properties = [],
    ): void {
        AuditEvent::create([
            'user_id' => $request->user()->id,
            'organization_id' => $customer->organization_id,
            'branch_id' => $customer->registered_branch_id,
            'action' => $action,
            'subject_type' => Customer::class,
            'subject_id' => (string) $customer->id,
            'status_code' => 200,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => ['access' => 'read'] + $properties,
            'occurred_at' => now('UTC'),
        ]);
    }
}
