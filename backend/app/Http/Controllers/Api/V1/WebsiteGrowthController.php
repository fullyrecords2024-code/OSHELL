<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\NewsletterConfirmationMail;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Services\LoyaltyService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsiteGrowthController extends Controller
{
    public function publicJournal(Request $request): JsonResponse
    {
        $organization = $this->publicOrganization($request);
        $articles = DB::table('website_pages')
            ->where('organization_id', $organization->id)
            ->where('page_type', 'journal')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now('UTC'))
            ->orderByDesc('published_at')
            ->limit(50)
            ->get(['slug', 'title', 'eyebrow', 'summary', 'body', 'seo_title', 'seo_description', 'cover_image_path', 'published_at']);
        $articles->transform(function (object $article): object {
            $article->cover_image_url = $article->cover_image_path
                ? Storage::disk('public')->url($article->cover_image_path)
                : null;
            unset($article->cover_image_path);

            return $article;
        });

        return response()->json(['articles' => $articles]);
    }

    public function publicReviews(Request $request): JsonResponse
    {
        $organization = $this->publicOrganization($request);
        $reviews = DB::table('customer_reviews')
            ->leftJoin('inventory_products', 'inventory_products.id', '=', 'customer_reviews.product_id')
            ->leftJoin('services', 'services.id', '=', 'customer_reviews.service_id')
            ->where('customer_reviews.organization_id', $organization->id)
            ->where('customer_reviews.status', 'approved')
            ->orderByDesc('customer_reviews.featured')
            ->orderByDesc('customer_reviews.published_at')
            ->limit(40)
            ->get([
                'customer_reviews.id',
                'customer_reviews.rating',
                'customer_reviews.display_name',
                'customer_reviews.review_text',
                'customer_reviews.featured',
                'customer_reviews.published_at',
                'inventory_products.name as product_name',
                'services.name as service_name',
            ]);

        return response()->json([
            'reviews' => $reviews->map(fn (object $review): array => [
                ...(array) $review,
                'featured' => (bool) $review->featured,
            ]),
        ]);
    }

    public function publicPromotions(Request $request): JsonResponse
    {
        $organization = $this->publicOrganization($request);
        $promotions = DB::table('marketing_promotions')
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->where('show_publicly', true)
            ->where(static fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now('UTC')))
            ->where(static fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now('UTC')))
            ->where(static fn ($query) => $query->whereNull('maximum_redemptions')->orWhereColumn('redemption_count', '<', 'maximum_redemptions'))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['title', 'message', 'coupon_code', 'starts_at', 'ends_at']);

        return response()->json(['promotions' => $promotions]);
    }

    public function publicSocialLinks(Request $request): JsonResponse
    {
        $organization = $this->publicOrganization($request);

        return response()->json([
            'links' => DB::table('website_social_links')
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->orderBy('platform')
                ->get(['platform', 'url']),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_slug' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'consent' => ['required', 'accepted'],
        ]);
        $organization = Organization::query()->where('slug', $data['organization_slug'])->where('is_active', true)->firstOrFail();
        $email = Str::lower(trim($data['email']));
        $token = Str::random(64);
        $shouldSendConfirmation = DB::transaction(function () use ($data, $email, $organization, $token): bool {
            $subscriber = DB::table('newsletter_subscribers')
                ->where('organization_id', $organization->id)
                ->where('email_normalized', $email)
                ->lockForUpdate()
                ->first();

            if ($subscriber?->status === 'subscribed') {
                return false;
            }

            $now = now('UTC');
            $confirmation = [
                'confirmation_token' => hash('sha256', $token),
                'confirmation_expires_at' => $now->copy()->addHours(24),
                'updated_at' => $now,
            ];

            if ($subscriber) {
                DB::table('newsletter_subscribers')->where('id', $subscriber->id)->update($confirmation);
            } else {
                DB::table('newsletter_subscribers')->insert([
                    'organization_id' => $organization->id,
                    'email' => trim($data['email']),
                    'email_normalized' => $email,
                    'unsubscribe_token' => Str::random(48),
                    'status' => 'pending',
                    'consented_at' => null,
                    'unsubscribed_at' => null,
                    'created_at' => $now,
                    ...$confirmation,
                ]);
            }

            return true;
        });

        if ($shouldSendConfirmation) {
            Mail::to(trim($data['email']))->send(new NewsletterConfirmationMail(
                $organization->name,
                route('public.newsletter.confirm.show', ['token' => $token]),
            ));
        }

        return response()->json([
            'message' => 'If this address needs a subscription confirmation, a confirmation message will be sent. Your subscription will only become active after you confirm your email address.',
        ], 202);
    }

    public function showNewsletterConfirmation(string $token): View
    {
        return view('newsletter.confirmation', [
            'token' => $token,
            'action' => route('public.newsletter.confirm', ['token' => $token]),
        ]);
    }

    public function confirmNewsletter(Request $request, string $token): JsonResponse|View
    {
        $confirmed = DB::transaction(function () use ($token): bool {
            $subscriber = DB::table('newsletter_subscribers')
                ->where('confirmation_token', hash('sha256', $token))
                ->where('confirmation_expires_at', '>', now('UTC'))
                ->lockForUpdate()
                ->first();

            if (! $subscriber || ! in_array($subscriber->status, ['pending', 'unsubscribed'], true)) {
                return false;
            }

            $customerId = DB::table('customers')
                ->where('organization_id', $subscriber->organization_id)
                ->whereRaw('LOWER(email) = ?', [$subscriber->email_normalized])
                ->value('id');

            return DB::table('newsletter_subscribers')
                ->where('id', $subscriber->id)
                ->where('confirmation_token', $subscriber->confirmation_token)
                ->where('confirmation_expires_at', '>', now('UTC'))
                ->whereIn('status', ['pending', 'unsubscribed'])
                ->update([
                    'customer_id' => $customerId,
                    'status' => 'subscribed',
                    'consented_at' => now('UTC'),
                    'unsubscribed_at' => null,
                    'confirmation_token' => null,
                    'confirmation_expires_at' => null,
                    'updated_at' => now('UTC'),
                ]) === 1;
        });

        if (! $confirmed) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'This confirmation link is invalid, expired, or no longer active.'], 410);
            }

            return view('newsletter.confirmation-result', ['confirmed' => false]);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Your email address is confirmed and the newsletter subscription is active.']);
        }

        return view('newsletter.confirmation-result', ['confirmed' => true]);
    }

    public function unsubscribeCurrentUser(Request $request): JsonResponse
    {
        $user = $request->user();
        $email = DB::table('customers')
            ->where('organization_id', $user->organization_id)
            ->where('id', $user->customer_id)
            ->value('email');
        if ($email) {
            DB::table('newsletter_subscribers')
                ->where('organization_id', $user->organization_id)
                ->where('email_normalized', Str::lower(trim($email)))
                ->update([
                    'status' => 'unsubscribed',
                    'unsubscribed_at' => now('UTC'),
                    'confirmation_token' => null,
                    'confirmation_expires_at' => null,
                    'updated_at' => now('UTC'),
                ]);
        }

        return response()->json(['message' => 'Marketing email consent has been withdrawn for this account when a matching subscription exists.']);
    }

    public function unsubscribe(Request $request, string $token): JsonResponse
    {
        $updated = DB::table('newsletter_subscribers')
            ->where('unsubscribe_token', $token)
            ->update([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now('UTC'),
                'confirmation_token' => null,
                'confirmation_expires_at' => null,
                'updated_at' => now('UTC'),
            ]);
        abort_if($updated === 0 && ! DB::table('newsletter_subscribers')->where('unsubscribe_token', $token)->exists(), 404);

        return response()->json(['message' => 'Newsletter subscription is inactive.']);
    }

    public function savePrivacyConsent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_slug' => ['required', 'string', 'max:160'],
            'anonymous_id' => ['required', 'uuid'],
            'consent_version' => ['required', 'string', 'max:30'],
            'analytics_allowed' => ['required', 'boolean'],
            'marketing_allowed' => ['required', 'boolean'],
        ]);
        $organization = Organization::query()->where('slug', $data['organization_slug'])->where('is_active', true)->firstOrFail();
        DB::table('website_privacy_consents')->upsert([[
            'organization_id' => $organization->id,
            'anonymous_id' => $data['anonymous_id'],
            'consent_version' => $data['consent_version'],
            'analytics_allowed' => $data['analytics_allowed'],
            'marketing_allowed' => $data['marketing_allowed'],
            'decided_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]], ['organization_id', 'anonymous_id'], ['consent_version', 'analytics_allowed', 'marketing_allowed', 'decided_at', 'updated_at']);

        return response()->json(['saved' => true]);
    }

    public function loyaltySummary(Request $request): JsonResponse
    {
        $customerId = $request->user()->customer_id;
        abort_if($customerId === null, 403, 'A customer account is required to view rewards.');
        $account = DB::table('loyalty_accounts')
            ->where('organization_id', $request->user()->organization_id)
            ->where('customer_id', $customerId)
            ->first();

        return response()->json([
            'program' => DB::table('loyalty_programs')
                ->where('organization_id', $request->user()->organization_id)
                ->where('is_active', true)
                ->first(['points_per_100_currency', 'points_per_currency_redemption', 'minimum_redemption_points', 'tiers']),
            'account' => $account,
            'entries' => $account === null ? [] : DB::table('loyalty_ledger_entries')
                ->where('account_id', $account->id)
                ->orderByDesc('created_at')->limit(100)->get(['event_type', 'points_delta', 'reason', 'created_at']),
        ]);
    }

    public function eligibleReviewSources(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->customer_id === null, 403, 'A linked customer account is required to review a purchase or visit.');
        $organizationId = (int) $user->organization_id;
        $customerId = (int) $user->customer_id;
        $products = DB::table('pos_sale_lines')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.sale_id')
            ->join('inventory_products', 'inventory_products.id', '=', 'pos_sale_lines.product_id')
            ->where('pos_sales.organization_id', $organizationId)
            ->where('pos_sales.customer_id', $customerId)
            ->where('pos_sales.status', 'completed')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('customer_reviews')
                    ->whereColumn('customer_reviews.sale_id', 'pos_sales.id')
                    ->whereColumn('customer_reviews.product_id', 'pos_sale_lines.product_id');
            })
            ->groupBy('inventory_products.id', 'inventory_products.name')
            ->orderBy('inventory_products.name')
            ->get(['inventory_products.id', 'inventory_products.name']);
        $services = DB::table('appointments')
            ->join('services', 'services.id', '=', 'appointments.service_id')
            ->where('appointments.organization_id', $organizationId)
            ->where('appointments.customer_id', $customerId)
            ->where('appointments.status', 'completed')
            ->whereNotNull('appointments.completed_at')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('customer_reviews')
                    ->whereColumn('customer_reviews.appointment_id', 'appointments.id')
                    ->whereColumn('customer_reviews.service_id', 'appointments.service_id');
            })
            ->groupBy('services.id', 'services.name')
            ->orderBy('services.name')
            ->get(['services.id', 'services.name']);

        return response()->json(['products' => $products, 'services' => $services]);
    }

    public function staffLoyalty(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organization_id;
        $accounts = DB::table('loyalty_accounts')
            ->join('customers', 'customers.id', '=', 'loyalty_accounts.customer_id')
            ->where('loyalty_accounts.organization_id', $organizationId)
            ->orderByDesc('loyalty_accounts.points_balance')
            ->limit(100)
            ->get(['customers.id as customer_id', 'customers.name', 'customers.email', 'loyalty_accounts.points_balance', 'loyalty_accounts.tier']);

        return response()->json([
            'program' => DB::table('loyalty_programs')->where('organization_id', $organizationId)->first(),
            'accounts' => $accounts,
            'recent_entries' => DB::table('loyalty_ledger_entries')->where('organization_id', $organizationId)
                ->orderByDesc('created_at')->limit(100)->get(),
        ]);
    }

    public function updateLoyaltyProgram(Request $request): JsonResponse
    {
        $data = $request->validate([
            'points_per_100_currency' => ['required', 'integer', 'min:0', 'max:100000'],
            'points_per_currency_redemption' => ['required', 'integer', 'min:1', 'max:100000'],
            'minimum_redemption_points' => ['required', 'integer', 'min:1', 'max:10000000'],
            'is_active' => ['required', 'boolean'],
            'tiers' => ['nullable', 'array', 'max:10'],
            'tiers.*.name' => ['required', 'string', 'max:40'],
            'tiers.*.minimum_points' => ['required', 'integer', 'min:0'],
        ]);
        DB::table('loyalty_programs')->updateOrInsert(
            ['organization_id' => $request->user()->organization_id],
            [...$data, 'updated_at' => now('UTC'), 'created_at' => now('UTC')],
        );
        $this->audit($request, 'website.loyalty.configure', 'loyalty_program', (string) $request->user()->organization_id);

        return response()->json(DB::table('loyalty_programs')->where('organization_id', $request->user()->organization_id)->first());
    }

    public function adjustLoyalty(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $customer = DB::table('customers')->where('organization_id', $request->user()->organization_id)->find($data['customer_id']);
        abort_if($customer === null, 404);
        DB::transaction(fn () => $loyalty->adjust(
            (int) $request->user()->organization_id,
            (int) $customer->id,
            (int) $data['points'],
            $data['reason'],
            (int) $request->user()->id,
        ));
        $this->audit($request, 'website.loyalty.adjust', 'customer', (string) $customer->id);

        return response()->json(['message' => 'Rewards balance adjusted.']);
    }

    public function submitReview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['product', 'service'])],
            'source_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'review_text' => ['required', 'string', 'min:10', 'max:2000'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'display_name_consent' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        abort_if($user->customer_id === null, 403, 'A linked customer account is required to submit a review.');
        $organizationId = (int) $user->organization_id;
        $productId = null;
        $serviceId = null;
        $saleId = null;
        $appointmentId = null;
        $branchId = null;
        if ($data['kind'] === 'product') {
            $purchase = DB::table('pos_sale_lines')
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.sale_id')
                ->where('pos_sales.organization_id', $organizationId)
                ->where('pos_sales.customer_id', $user->customer_id)
                ->where('pos_sales.status', 'completed')
                ->where('pos_sale_lines.product_id', $data['source_id'])
                ->whereNotExists(static function ($query): void {
                    $query->selectRaw('1')->from('customer_reviews')
                        ->whereColumn('customer_reviews.sale_id', 'pos_sales.id')
                        ->whereColumn('customer_reviews.product_id', 'pos_sale_lines.product_id');
                })
                ->select('pos_sales.id', 'pos_sales.branch_id', 'pos_sale_lines.product_id')
                ->first();
            abort_if($purchase === null, 403, 'A completed purchase of this product is required for a verified review.');
            $productId = (int) $purchase->product_id;
            $saleId = (int) $purchase->id;
            $branchId = (int) $purchase->branch_id;
        } else {
            $visit = DB::table('appointments')
                ->where('organization_id', $organizationId)
                ->where('customer_id', $user->customer_id)
                ->where('service_id', $data['source_id'])
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereNotExists(static function ($query): void {
                    $query->selectRaw('1')->from('customer_reviews')
                        ->whereColumn('customer_reviews.appointment_id', 'appointments.id')
                        ->whereColumn('customer_reviews.service_id', 'appointments.service_id');
                })
                ->first(['id', 'branch_id', 'service_id']);
            abort_if($visit === null, 403, 'A completed appointment for this service is required for a verified review.');
            $serviceId = (int) $visit->service_id;
            $appointmentId = (int) $visit->id;
            $branchId = (int) $visit->branch_id;
        }

        $reviewId = DB::table('customer_reviews')->insertGetId([
            'organization_id' => $organizationId,
            'customer_id' => $user->customer_id,
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'service_id' => $serviceId,
            'sale_id' => $saleId,
            'appointment_id' => $appointmentId,
            'rating' => $data['rating'],
            'display_name' => ($data['display_name_consent'] ?? false) ? ($data['display_name'] ?: 'Verified customer') : 'Verified customer',
            'review_text' => trim($data['review_text']),
            'status' => 'pending',
            'display_name_consent' => (bool) ($data['display_name_consent'] ?? false),
            'featured' => false,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return response()->json(['id' => $reviewId, 'status' => 'pending', 'verified' => true], 201);
    }

    public function staffReviews(Request $request): JsonResponse
    {
        $reviews = DB::table('customer_reviews')
            ->leftJoin('customers', 'customers.id', '=', 'customer_reviews.customer_id')
            ->leftJoin('inventory_products', 'inventory_products.id', '=', 'customer_reviews.product_id')
            ->leftJoin('services', 'services.id', '=', 'customer_reviews.service_id')
            ->where('customer_reviews.organization_id', $request->user()->organization_id)
            ->orderByDesc('customer_reviews.created_at')
            ->paginate(50, [
                'customer_reviews.*', 'customers.name as customer_name',
                'inventory_products.name as product_name', 'services.name as service_name',
            ]);

        return response()->json($reviews);
    }

    public function moderateReview(Request $request, int $review): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'hidden'])],
            'featured' => ['sometimes', 'boolean'],
        ]);
        $record = DB::table('customer_reviews')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $review)
            ->first();
        abort_if($record === null, 404);
        DB::table('customer_reviews')->where('id', $review)->update([
            'status' => $data['status'],
            'featured' => $data['status'] === 'approved' && ($data['featured'] ?? false),
            'published_at' => $data['status'] === 'approved' ? ($record->published_at ?? now('UTC')) : null,
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'website.review.'.$data['status'], 'customer_review', (string) $review);

        return response()->json(['id' => $review, 'status' => $data['status']]);
    }

    public function staffSections(Request $request): JsonResponse
    {
        return response()->json(DB::table('website_product_sections')
            ->where('organization_id', $request->user()->organization_id)
            ->orderBy('sort_order')
            ->get()
            ->map(function (object $section): object {
                $section->product_ids = DB::table('website_product_section_items')
                    ->where('section_id', $section->id)->orderBy('sort_order')->pluck('product_id');

                return $section;
            }));
    }

    public function staffProducts(Request $request): JsonResponse
    {
        return response()->json(DB::table('inventory_products')
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'is_public']));
    }

    public function saveSection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash:ascii', 'max:80'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'product_ids' => ['present', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct'],
        ]);
        $organizationId = (int) $request->user()->organization_id;
        $products = DB::table('inventory_products')->where('organization_id', $organizationId)
            ->whereIn('id', $data['product_ids'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_unless(count($products) === count($data['product_ids']), 422, 'Every selected product must belong to this organization.');
        DB::table('website_product_sections')->updateOrInsert(
            ['organization_id' => $organizationId, 'slug' => $data['slug']],
            [
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'],
                'is_active' => $data['is_active'],
                'updated_at' => now('UTC'),
                'created_at' => now('UTC'),
            ],
        );
        $sectionId = (int) DB::table('website_product_sections')->where('organization_id', $organizationId)->where('slug', $data['slug'])->value('id');
        DB::table('website_product_section_items')->where('section_id', $sectionId)->delete();
        foreach ($products as $index => $productId) {
            DB::table('website_product_section_items')->insert([
                'section_id' => $sectionId,
                'product_id' => $productId,
                'sort_order' => $index,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }
        $this->audit($request, 'website.merchandising.save', 'website_product_section', (string) $sectionId);

        return response()->json(['id' => $sectionId, 'message' => 'Product section saved.']);
    }

    public function publicSections(Request $request): JsonResponse
    {
        $organization = $this->publicOrganization($request);
        $sections = DB::table('website_product_sections')
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        foreach ($sections as $section) {
            $section->products = DB::table('website_product_section_items')
                ->join('inventory_products', 'inventory_products.id', '=', 'website_product_section_items.product_id')
                ->where('website_product_section_items.section_id', $section->id)
                ->where('inventory_products.is_active', true)
                ->where('inventory_products.is_public', true)
                ->orderBy('website_product_section_items.sort_order')
                ->get(['inventory_products.id', 'inventory_products.slug', 'inventory_products.name', 'inventory_products.description', 'inventory_products.selling_price', 'inventory_products.sku']);
        }

        return response()->json(['sections' => $sections]);
    }

    public function staffPromotions(Request $request): JsonResponse
    {
        return response()->json(DB::table('marketing_promotions')
            ->where('organization_id', $request->user()->organization_id)->orderByDesc('created_at')->paginate(50));
    }

    public function savePromotion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:500'],
            'coupon_code' => ['nullable', 'alpha_dash:ascii', 'max:40'],
            'discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'minimum_subtotal' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'maximum_redemptions' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['required', Rule::in(['draft', 'active', 'ended'])],
            'show_publicly' => ['required', 'boolean'],
        ]);
        abort_unless(($data['coupon_code'] === null) === ($data['discount_type'] === null), 422, 'Coupon code and discount type must be configured together.');
        abort_unless(($data['coupon_code'] === null) === ($data['discount_value'] === null), 422, 'Coupon code and discount value must be configured together.');
        if (($data['discount_type'] ?? null) === 'percent') {
            abort_if((float) $data['discount_value'] > 100, 422, 'Percentage discounts cannot exceed 100%.');
        }

        $record = [
            ...$data,
            'coupon_code' => isset($data['coupon_code']) ? Str::upper($data['coupon_code']) : null,
            'created_by' => $request->user()->id,
            'updated_at' => now('UTC'),
            'created_at' => now('UTC'),
        ];
        $id = DB::table('marketing_promotions')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            ...$record,
        ]);
        $this->audit($request, 'website.promotion.create', 'marketing_promotion', (string) $id);

        return response()->json(DB::table('marketing_promotions')->find($id), 201);
    }

    public function updatePromotion(Request $request, int $promotion): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:500'],
            'coupon_code' => ['nullable', 'alpha_dash:ascii', 'max:40'],
            'discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'minimum_subtotal' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'maximum_redemptions' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['required', Rule::in(['draft', 'active', 'ended'])],
            'show_publicly' => ['required', 'boolean'],
        ]);
        abort_unless(($data['coupon_code'] === null) === ($data['discount_type'] === null), 422, 'Coupon code and discount type must be configured together.');
        abort_unless(($data['coupon_code'] === null) === ($data['discount_value'] === null), 422, 'Coupon code and discount value must be configured together.');
        if (($data['discount_type'] ?? null) === 'percent') {
            abort_if((float) $data['discount_value'] > 100, 422, 'Percentage discounts cannot exceed 100%.');
        }
        $record = DB::table('marketing_promotions')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $promotion)
            ->first();
        abort_if($record === null, 404);
        DB::table('marketing_promotions')->where('id', $promotion)->update([
            ...$data,
            'coupon_code' => isset($data['coupon_code']) ? Str::upper($data['coupon_code']) : null,
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'website.promotion.update', 'marketing_promotion', (string) $promotion);

        return response()->json(DB::table('marketing_promotions')->find($promotion));
    }

    public function staffSocialLinks(Request $request): JsonResponse
    {
        return response()->json(DB::table('website_social_links')
            ->where('organization_id', $request->user()->organization_id)->orderBy('platform')->get());
    }

    public function saveSocialLinks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'links' => ['required', 'array', 'max:8'],
            'links.*.platform' => ['required', Rule::in(['instagram', 'facebook', 'tiktok', 'whatsapp', 'youtube'])],
            'links.*.url' => ['required', 'url', 'max:500'],
            'links.*.is_active' => ['required', 'boolean'],
        ]);
        $organizationId = (int) $request->user()->organization_id;
        DB::transaction(function () use ($data, $organizationId): void {
            DB::table('website_social_links')->where('organization_id', $organizationId)->delete();
            foreach ($data['links'] as $link) {
                abort_unless(parse_url($link['url'], PHP_URL_SCHEME) === 'https', 422, 'Social links must use HTTPS.');
                DB::table('website_social_links')->insert([
                    'organization_id' => $organizationId,
                    'platform' => $link['platform'],
                    'url' => $link['url'],
                    'is_active' => $link['is_active'],
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }
        });
        $this->audit($request, 'website.social-links.update', 'website_social_links', (string) $organizationId);

        return response()->json(['message' => 'Social links saved.']);
    }

    private function publicOrganization(Request $request): Organization
    {
        $data = $request->validate(['organization_slug' => ['required', 'string', 'max:160']]);

        return Organization::query()->where('slug', $data['organization_slug'])->where('is_active', true)->firstOrFail();
    }

    private function audit(Request $request, string $action, string $subjectType, string $subjectId): void
    {
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'status_code' => 200,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => [],
            'occurred_at' => now('UTC'),
        ]);
    }
}
