<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Auth\EmailVerificationOtp;
use App\Support\Authorization\FoundationScope;
use App\Support\Notifications\Notifier;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EcommerceController extends Controller
{
    private const RESERVATION_MINUTES = 15;

    public function storefront(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_slug' => ['sometimes', 'string', 'max:160'],
            'branch_id' => ['sometimes', 'integer'],
        ]);
        $organizations = Organization::query()
            ->where('is_active', true)
            ->when(isset($data['organization_slug']), fn ($query) => $query->where('slug', $data['organization_slug']))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'currency_code']);
        abort_if(isset($data['organization_slug']) && $organizations->isEmpty(), 404, 'Store not found.');

        if (! isset($data['organization_slug'])) {
            return response()->json([
                'organizations' => $organizations->map(fn (Organization $organization): array => [
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ]),
            ]);
        }

        $organization = $organizations->firstOrFail();
        $branches = Branch::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        if (! isset($data['branch_id'])) {
            return response()->json([
                'organization' => ['name' => $organization->name, 'slug' => $organization->slug, 'currency_code' => $organization->currency_code],
                'branches' => $branches,
            ]);
        }

        $branch = $branches->firstWhere('id', (int) $data['branch_id']);
        abort_if($branch === null, 404, 'Branch not found in this store.');
        $this->expireReservations((int) $organization->id, (int) $branch->id);

        $onHand = DB::table('stock_batch_balances')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_balances.batch_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_batch_balances.location_id')
            ->where('stock_locations.branch_id', $branch->id)
            ->where('stock_batch_balances.quantity', '>', 0)
            ->where(static function (Builder $query): void {
                $query->whereNull('stock_batches.expires_on')
                    ->orWhere('stock_batches.expires_on', '>=', now('UTC')->toDateString());
            })
            ->groupBy('stock_batch_balances.product_id')
            ->selectRaw('stock_batch_balances.product_id, SUM(stock_batch_balances.quantity) as quantity');
        $reserved = DB::table('ecommerce_order_lines')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_lines.order_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'ecommerce_order_lines.location_id')
            ->where('stock_locations.branch_id', $branch->id)
            ->whereNull('ecommerce_orders.stock_issued_at')
            ->where(static function (Builder $query): void {
                $query->whereIn('ecommerce_orders.status', ['confirmed', 'processing', 'ready_for_collection', 'dispatched'])
                    ->orWhere(static function (Builder $pending): void {
                        $pending->where('ecommerce_orders.status', 'awaiting_payment')
                            ->where('ecommerce_orders.reservation_expires_at', '>', now('UTC'));
                    });
            })
            ->groupBy('ecommerce_order_lines.product_id')
            ->selectRaw('ecommerce_order_lines.product_id, SUM(ecommerce_order_lines.quantity) as quantity');
        $products = DB::table('inventory_products')
            ->leftJoin('inventory_categories', 'inventory_categories.id', '=', 'inventory_products.category_id')
            ->leftJoinSub($onHand, 'store_stock', 'store_stock.product_id', '=', 'inventory_products.id')
            ->leftJoinSub($reserved, 'store_reserved', 'store_reserved.product_id', '=', 'inventory_products.id')
            ->where('inventory_products.organization_id', $organization->id)
            ->where('inventory_products.is_active', true)
            ->where('inventory_products.is_public', true)
            ->whereRaw('COALESCE(store_stock.quantity, 0) > COALESCE(store_reserved.quantity, 0)')
            ->orderBy('inventory_products.name')
            ->get([
                'inventory_products.id',
                'inventory_products.sku',
                'inventory_products.slug',
                'inventory_products.name',
                'inventory_products.brand',
                'inventory_products.description',
                'inventory_products.unit_of_measure',
                'inventory_products.selling_price',
                'inventory_categories.name as category_name',
                DB::raw('COALESCE(store_stock.quantity, 0) - COALESCE(store_reserved.quantity, 0) as available_quantity'),
            ]);

        $imageRows = ProductImage::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->orderBy('sort_order')
            ->get();
        $products = $products->map(function (object $product) use ($imageRows): array {
            $images = $imageRows
                ->where('product_id', $product->id)
                ->map(static fn (ProductImage $image): array => [
                    'id' => $image->id,
                    'url' => $image->url,
                    'is_primary' => $image->is_primary,
                ])
                ->values()
                ->all();
            $primary = collect($images)->firstWhere('is_primary', true) ?? ($images[0] ?? null);

            return [
                ...(array) $product,
                'images' => $images,
                'primary_image_url' => $primary['url'] ?? null,
            ];
        });

        return response()->json([
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug, 'currency_code' => $organization->currency_code],
            'branches' => $branches,
            'branch_id' => $branch->id,
            'products' => $products,
        ]);
    }

    public function publicProduct(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'organization_slug' => ['required', 'string', 'max:160'],
            'branch_id' => ['required', 'integer'],
        ]);
        $organization = Organization::query()
            ->where('slug', $data['organization_slug'])
            ->where('is_active', true)
            ->first();
        abort_if($organization === null, 404, 'Store not found.');
        $branch = Branch::query()
            ->where('organization_id', $organization->id)
            ->where('id', $data['branch_id'])
            ->where('is_active', true)
            ->first();
        abort_if($branch === null, 404, 'Branch not found in this store.');

        $onHand = DB::table('stock_batch_balances')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_balances.batch_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_batch_balances.location_id')
            ->where('stock_locations.branch_id', $branch->id)
            ->where('stock_batch_balances.quantity', '>', 0)
            ->where(static function (Builder $query): void {
                $query->whereNull('stock_batches.expires_on')
                    ->orWhere('stock_batches.expires_on', '>=', now('UTC')->toDateString());
            })
            ->groupBy('stock_batch_balances.product_id')
            ->selectRaw('stock_batch_balances.product_id, SUM(stock_batch_balances.quantity) as quantity');
        $reserved = DB::table('ecommerce_order_lines')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_lines.order_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'ecommerce_order_lines.location_id')
            ->where('stock_locations.branch_id', $branch->id)
            ->whereNull('ecommerce_orders.stock_issued_at')
            ->where(static function (Builder $query): void {
                $query->whereIn('ecommerce_orders.status', ['confirmed', 'processing', 'ready_for_collection', 'dispatched'])
                    ->orWhere(static function (Builder $pending): void {
                        $pending->where('ecommerce_orders.status', 'awaiting_payment')
                            ->where('ecommerce_orders.reservation_expires_at', '>', now('UTC'));
                    });
            })
            ->groupBy('ecommerce_order_lines.product_id')
            ->selectRaw('ecommerce_order_lines.product_id, SUM(ecommerce_order_lines.quantity) as quantity');

        $product = DB::table('inventory_products')
            ->leftJoin('inventory_categories', 'inventory_categories.id', '=', 'inventory_products.category_id')
            ->leftJoinSub($onHand, 'store_stock', 'store_stock.product_id', '=', 'inventory_products.id')
            ->leftJoinSub($reserved, 'store_reserved', 'store_reserved.product_id', '=', 'inventory_products.id')
            ->where('inventory_products.organization_id', $organization->id)
            ->where('inventory_products.slug', $slug)
            ->where('inventory_products.is_active', true)
            ->where('inventory_products.is_public', true)
            ->first([
                'inventory_products.id',
                'inventory_products.sku',
                'inventory_products.slug',
                'inventory_products.name',
                'inventory_products.brand',
                'inventory_products.description',
                'inventory_products.unit_of_measure',
                'inventory_products.selling_price',
                'inventory_categories.name as category_name',
                DB::raw('CASE WHEN COALESCE(store_stock.quantity, 0) > COALESCE(store_reserved.quantity, 0) THEN COALESCE(store_stock.quantity, 0) - COALESCE(store_reserved.quantity, 0) ELSE 0 END as available_quantity'),
            ]);
        abort_if($product === null, 404, 'Product not found.');

        $images = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (ProductImage $image): array => [
                'id' => $image->id,
                'url' => $image->url,
                'is_primary' => $image->is_primary,
            ])->values()->all();
        $primary = collect($images)->firstWhere('is_primary', true) ?? ($images[0] ?? null);
        $available = (float) $product->available_quantity;
        $payload = [
            ...(array) $product,
            'images' => $images,
            'primary_image_url' => $primary['url'] ?? null,
            'availability_status' => $available > 5 ? 'in_stock' : ($available > 0 ? 'low_stock' : 'out_of_stock'),
            'available' => $available > 0,
        ];

        return response()->json([
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug, 'currency_code' => $organization->currency_code],
            'branch_id' => $branch->id,
            'product' => $payload,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_slug' => ['required', 'string', 'max:160'],
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', Password::defaults()],
        ]);
        $organization = Organization::query()
            ->where('slug', $data['organization_slug'])
            ->where('is_active', true)
            ->firstOrFail();
        $branch = Branch::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->findOrFail($data['branch_id']);
        $email = mb_strtolower(trim($data['email']));
        abort_if(User::query()->where('email', $email)->exists(), 409, 'An account already uses this email address.');
        abort_if(Customer::query()->where('organization_id', $organization->id)->where('normalized_email', $email)->exists(), 409, 'A customer profile already uses this email. Contact the clinic to link your account.');

        $user = DB::transaction(function () use ($data, $organization, $branch, $email): User {
            $name = trim(preg_replace('/\s+/', ' ', $data['name']) ?? $data['name']);
            $customer = Customer::create([
                'organization_id' => $organization->id,
                'registered_branch_id' => $branch->id,
                'name' => $name,
                'normalized_name' => mb_strtolower($name),
                'email' => $email,
                'normalized_email' => $email,
                'phone' => $data['phone'] ?? null,
                'normalized_phone' => isset($data['phone']) ? preg_replace('/\D+/', '', $data['phone']) : null,
                'preferred_channel' => 'email',
                'status' => 'active',
                'registration_source' => 'website',
            ]);
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($data['password']),
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'is_active' => true,
                'password_reset_required' => false,
            ]);
            $user->assignRole('Customer / Patient');

            DB::table('customer_branch_history')->insert([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'activity' => 'registered',
                'occurred_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return $user;
        });

        app(EmailVerificationOtp::class)->issue($email);
        if ($user->customer !== null) {
            app(Notifier::class)->customerRegistered($user->customer);
        }

        return response()->json([
            'message' => 'We emailed a six-digit verification code to '.$email.'. Enter it to activate your account.',
            'verification_required' => true,
            'email' => $email,
        ], 201);
    }

    public function verifyRegistrationOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);

        abort_unless(
            app(EmailVerificationOtp::class)->verify($data['email'], $data['code']),
            422,
            'The verification code is invalid or has expired. Request a new code and try again.',
        );

        return response()->json([
            'message' => 'Your email address is verified. You can now sign in.',
            'verified' => true,
        ]);
    }

    public function resendRegistrationOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        if ($user !== null && $user->email_verified_at === null) {
            app(EmailVerificationOtp::class)->issue($user->email);
        }

        return response()->json([
            'message' => 'If that address belongs to an unverified account, a new verification code has been sent.',
        ], 202);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->customer_id !== null, 403, 'A customer account is required to place an online order.');
        abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email address before placing an order.');
        $data = $request->validate([
            'organization_slug' => ['required', 'string', 'max:160'],
            'branch_id' => ['required', 'integer'],
            'fulfillment_type' => ['required', Rule::in(['collection', 'delivery'])],
            'delivery_recipient' => ['required_if:fulfillment_type,delivery', 'nullable', 'string', 'max:180'],
            'delivery_phone' => ['required_if:fulfillment_type,delivery', 'nullable', 'string', 'max:40'],
            'delivery_address_line1' => ['required_if:fulfillment_type,delivery', 'nullable', 'string', 'max:255'],
            'delivery_address_line2' => ['nullable', 'string', 'max:255'],
            'delivery_city' => ['required_if:fulfillment_type,delivery', 'nullable', 'string', 'max:120'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999', 'decimal:0,3'],
        ]);
        $organization = Organization::query()
            ->where('slug', $data['organization_slug'])
            ->where('is_active', true)
            ->firstOrFail();
        abort_unless((int) $user->organization_id === (int) $organization->id, 403, 'This customer account belongs to another organization.');
        $customer = Customer::query()->whereKey($user->customer_id)->where('organization_id', $organization->id)->firstOrFail();
        abort_if($customer->status !== 'active' || $customer->merged_into_id !== null, 403, 'This customer account cannot place orders.');
        $branch = Branch::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->findOrFail($data['branch_id']);

        $normalized = [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'fulfillment_type' => $data['fulfillment_type'],
            'delivery_address' => $data['fulfillment_type'] === 'delivery' ? [
                'recipient' => trim($data['delivery_recipient'] ?? ''),
                'phone' => trim($data['delivery_phone'] ?? ''),
                'address_line1' => trim($data['delivery_address_line1'] ?? ''),
                'address_line2' => trim($data['delivery_address_line2'] ?? ''),
                'city' => trim($data['delivery_city'] ?? ''),
                'instructions' => trim($data['delivery_instructions'] ?? ''),
            ] : null,
            'customer_note' => trim($data['customer_note'] ?? ''),
            'coupon_code' => isset($data['coupon_code']) ? Str::upper(trim($data['coupon_code'])) : null,
            'lines' => collect($data['lines'])->sortBy('product_id')->values()->all(),
        ];
        $payloadHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        $existing = DB::table('ecommerce_orders')
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing !== null) {
            abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This idempotency key was already used for a different order.');

            return response()->json($this->orderPayload((int) $existing->id, true));
        }

        try {
            $orderId = DB::transaction(function () use ($data, $normalized, $payloadHash, $organization, $branch, $customer, $user): int {
                $locations = DB::table('stock_locations')
                    ->where('organization_id', $organization->id)
                    ->where('branch_id', $branch->id)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                abort_if($locations->isEmpty(), 422, 'Online ordering is unavailable because this branch has no active stock location.');

                $now = now('UTC');
                $this->expireReservationsInTransaction((int) $organization->id, (int) $branch->id, $now);

                $allocations = [];
                $subtotalCents = 0;
                foreach ($normalized['lines'] as $line) {
                    $product = DB::table('inventory_products')
                        ->where('organization_id', $organization->id)
                        ->where('id', $line['product_id'])
                        ->where('is_active', true)
                        ->where('is_public', true)
                        ->first();
                    abort_if($product === null, 422, 'One or more selected products are no longer available.');
                    $quantityMilli = $this->quantityMilli((float) $line['quantity']);
                    $productTotalCents = (int) round((float) $product->selling_price * 100 * $quantityMilli / 1000);
                    $subtotalCents += $productTotalCents;
                    $remaining = $quantityMilli;
                    $splitLines = [];
                    foreach ($locations as $location) {
                        $batches = DB::table('stock_batch_balances')
                            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_balances.batch_id')
                            ->where('stock_batch_balances.location_id', $location->id)
                            ->where('stock_batch_balances.product_id', $product->id)
                            ->where('stock_batch_balances.quantity', '>', 0)
                            ->where(static function (Builder $query) use ($now): void {
                                $query->whereNull('stock_batches.expires_on')
                                    ->orWhere('stock_batches.expires_on', '>=', $now->toDateString());
                            })
                            ->orderByRaw('stock_batches.expires_on is null')
                            ->orderBy('stock_batches.expires_on')
                            ->orderBy('stock_batches.id')
                            ->select('stock_batches.id as batch_id', 'stock_batch_balances.quantity')
                            ->get();
                        foreach ($batches as $batch) {
                            $held = DB::table('ecommerce_order_lines')
                                ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_lines.order_id')
                                ->whereNull('ecommerce_orders.stock_issued_at')
                                ->where(static function (Builder $query) use ($now): void {
                                    $query->whereIn('ecommerce_orders.status', ['confirmed', 'processing', 'ready_for_collection', 'dispatched'])
                                        ->orWhere(static function (Builder $pending) use ($now): void {
                                            $pending->where('ecommerce_orders.status', 'awaiting_payment')
                                                ->where('ecommerce_orders.reservation_expires_at', '>', $now);
                                        });
                                })
                                ->where('ecommerce_order_lines.location_id', $location->id)
                                ->where('ecommerce_order_lines.batch_id', $batch->batch_id)
                                ->sum('ecommerce_order_lines.quantity');
                            $available = max(0, $this->quantityMilli((float) $batch->quantity) - $this->quantityMilli((float) $held));
                            if ($available === 0) {
                                continue;
                            }
                            $take = min($remaining, $available);
                            $splitLines[] = [
                                'product_id' => (int) $product->id,
                                'location_id' => (int) $location->id,
                                'batch_id' => (int) $batch->batch_id,
                                'sku_snapshot' => $product->sku,
                                'name_snapshot' => $product->name,
                                'quantity_milli' => $take,
                                'unit_price' => $product->selling_price,
                            ];
                            $remaining -= $take;
                            if ($remaining === 0) {
                                break 2;
                            }
                        }
                    }
                    abort_if($remaining > 0, 409, "Insufficient available stock for {$product->name}.");
                    $lineCentsAssigned = 0;
                    foreach ($splitLines as $index => &$splitLine) {
                        $lineCents = $index === array_key_last($splitLines)
                            ? $productTotalCents - $lineCentsAssigned
                            : (int) round((float) $product->selling_price * 100 * $splitLine['quantity_milli'] / 1000);
                        $splitLine['line_total'] = number_format($lineCents / 100, 2, '.', '');
                        $lineCentsAssigned += $lineCents;
                    }
                    unset($splitLine);
                    array_push($allocations, ...$splitLines);
                }

                $subtotal = number_format($subtotalCents / 100, 2, '.', '');
                $discountCents = 0;
                $promotionId = null;
                if ($normalized['coupon_code'] !== null) {
                    $promotion = DB::table('marketing_promotions')
                        ->where('organization_id', $organization->id)
                        ->where('coupon_code', $normalized['coupon_code'])
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->first();
                    abort_if($promotion === null, 422, 'This coupon code is not available.');
                    abort_if($promotion->starts_at !== null && now('UTC')->lt($promotion->starts_at), 422, 'This coupon code is not available yet.');
                    abort_if($promotion->ends_at !== null && now('UTC')->gte($promotion->ends_at), 422, 'This coupon code has expired.');
                    abort_if($promotion->maximum_redemptions !== null && (int) $promotion->redemption_count >= (int) $promotion->maximum_redemptions, 422, 'This coupon has reached its redemption limit.');
                    abort_if($subtotalCents < (int) round((float) $promotion->minimum_subtotal * 100), 422, 'The order does not meet the minimum subtotal for this coupon.');
                    if ($promotion->discount_type === 'percent') {
                        $discountCents = (int) floor($subtotalCents * (float) $promotion->discount_value / 100);
                    } else {
                        $discountCents = (int) round((float) $promotion->discount_value * 100);
                    }
                    $discountCents = min($subtotalCents, $discountCents);
                    $promotionId = (int) $promotion->id;
                    DB::table('marketing_promotions')->where('id', $promotion->id)->increment('redemption_count');
                }
                $reservationExpiresAt = $now->copy()->addMinutes(self::RESERVATION_MINUTES);
                $orderId = DB::table('ecommerce_orders')->insertGetId([
                    'organization_id' => $organization->id,
                    'branch_id' => $branch->id,
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                    'order_number' => 'WEB-'.$branch->code.'-'.Str::upper((string) Str::ulid()),
                    'idempotency_key' => $data['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'status' => 'awaiting_payment',
                    'payment_status' => 'pending',
                    'fulfillment_type' => $normalized['fulfillment_type'],
                    'delivery_address' => $normalized['delivery_address'] === null ? null : json_encode($normalized['delivery_address'], JSON_THROW_ON_ERROR),
                    'customer_note' => $normalized['customer_note'] ?: null,
                    'currency_code' => 'KES',
                    'subtotal' => $subtotal,
                    'delivery_fee' => '0.00',
                    'promotion_id' => $promotionId,
                    'coupon_code' => $promotionId === null ? null : $normalized['coupon_code'],
                    'discount_amount' => number_format($discountCents / 100, 2, '.', ''),
                    'total_amount' => number_format(($subtotalCents - $discountCents) / 100, 2, '.', ''),
                    'reservation_expires_at' => $reservationExpiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                foreach ($allocations as $allocation) {
                    DB::table('ecommerce_order_lines')->insert([
                        'order_id' => $orderId,
                        'product_id' => $allocation['product_id'],
                        'location_id' => $allocation['location_id'],
                        'batch_id' => $allocation['batch_id'],
                        'sku_snapshot' => $allocation['sku_snapshot'],
                        'name_snapshot' => $allocation['name_snapshot'],
                        'quantity' => number_format($allocation['quantity_milli'] / 1000, 3, '.', ''),
                        'unit_price' => $allocation['unit_price'],
                        'line_total' => $allocation['line_total'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return $orderId;
            }, 3);
        } catch (QueryException $exception) {
            $existing = DB::table('ecommerce_orders')
                ->where('organization_id', $organization->id)
                ->where('user_id', $user->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This idempotency key was already used for a different order.');

            return response()->json($this->orderPayload((int) $existing->id, true));
        }

        $orderRecord = DB::table('ecommerce_orders')->find($orderId);
        if ($orderRecord !== null) {
            app(Notifier::class)->orderPlaced($orderRecord);
        }

        $order = $this->orderPayload($orderId);

        return response()->json([
            ...$order,
            'payment_handoff' => [
                'status' => 'payment_required',
                'provider' => null,
                'amount' => $order['total_amount'],
                'currency_code' => $order['currency_code'],
                'reservation_expires_at' => $order['reservation_expires_at'],
                'message' => 'Your order is reserved. Choose an available payment method to continue; fulfilment starts only after payment is verified.',
            ],
        ], 201);
    }

    public function orders(Request $request): JsonResponse
    {
        $this->expireReservations((int) $request->user()->organization_id);
        $query = DB::table('ecommerce_orders')
            ->where('user_id', $request->user()->id)
            ->where('customer_id', $request->user()->customer_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'order_number', 'branch_id', 'status', 'payment_status', 'fulfillment_type', 'currency_code', 'subtotal', 'delivery_fee', 'total_amount', 'reservation_expires_at', 'return_status', 'created_at']);

        return response()->json($query->map(fn (object $order): array => $this->orderPayload((int) $order->id)));
    }

    public function cancelOrder(Request $request, int $orderId): JsonResponse
    {
        DB::transaction(function () use ($orderId, $request): void {
            $order = DB::table('ecommerce_orders')
                ->where('id', $orderId)
                ->where('user_id', $request->user()->id)
                ->where('customer_id', $request->user()->customer_id)
                ->lockForUpdate()
                ->first();
            abort_if($order === null, 404);
            abort_unless($order->status === 'awaiting_payment' && $order->payment_status === 'pending', 409, 'Only unpaid orders awaiting payment can be cancelled by the customer.');
            DB::table('ecommerce_orders')->where('id', $order->id)->update([
                'status' => 'cancelled',
                'cancelled_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $this->releasePromotionReservation($order->promotion_id);
        });

        $cancelled = DB::table('ecommerce_orders')->find($orderId);
        if ($cancelled !== null) {
            app(Notifier::class)->orderStatusChanged($cancelled, 'cancelled');
        }

        return response()->json($this->orderPayload($orderId));
    }

    public function requestReturn(Request $request, int $orderId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $order = DB::table('ecommerce_orders')
            ->where('id', $orderId)
            ->where('user_id', $request->user()->id)
            ->where('customer_id', $request->user()->customer_id)
            ->first();
        abort_if($order === null, 404);
        abort_unless($order->status === 'completed' && $order->payment_status === 'paid', 409, 'Only completed, paid orders can be submitted for return.');
        abort_unless($order->return_status === 'none', 409, 'A return request has already been submitted for this order.');
        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'return_status' => 'requested',
            'return_reason' => $data['reason'],
            'updated_at' => now('UTC'),
        ]);

        $requested = DB::table('ecommerce_orders')->find($order->id);
        if ($requested !== null) {
            app(Notifier::class)->returnRequested($requested);
        }

        return response()->json($this->orderPayload((int) $order->id), 202);
    }

    public function staffOrders(Request $request, FoundationScope $scope): JsonResponse
    {
        $this->expireReservations((int) $request->user()->organization_id);
        $branchIds = $scope->branchIds($request->user());
        $orders = DB::table('ecommerce_orders')
            ->join('branches', 'branches.id', '=', 'ecommerce_orders.branch_id')
            ->join('customers', 'customers.id', '=', 'ecommerce_orders.customer_id')
            ->where('ecommerce_orders.organization_id', $request->user()->organization_id)
            ->whereIn('ecommerce_orders.branch_id', $branchIds)
            ->orderByDesc('ecommerce_orders.created_at')
            ->limit(100)
            ->get([
                'ecommerce_orders.id',
                'ecommerce_orders.order_number',
                'ecommerce_orders.branch_id',
                'branches.name as branch_name',
                'customers.name as customer_name',
                'ecommerce_orders.status',
                'ecommerce_orders.payment_status',
                'ecommerce_orders.fulfillment_type',
                'ecommerce_orders.total_amount',
                'ecommerce_orders.currency_code',
                'ecommerce_orders.return_status',
                'ecommerce_orders.return_reason',
                'ecommerce_orders.created_at',
            ]);

        return response()->json($orders->map(fn (object $order): array => [
            ...(array) $order,
            'lines' => DB::table('ecommerce_order_lines')
                ->where('order_id', $order->id)
                ->select('name_snapshot as name', 'sku_snapshot as sku', 'quantity', 'line_total')
                ->get(),
        ]));
    }

    public function updateFulfillment(Request $request, int $orderId, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['processing', 'ready_for_collection', 'dispatched', 'completed', 'cancelled'])],
        ]);
        $order = DB::table('ecommerce_orders')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $orderId)
            ->first();
        abort_if($order === null, 404);
        $scope->assertBranch($request->user(), Branch::query()->findOrFail($order->branch_id));
        $allowedTransitions = [
            'confirmed' => ['processing'],
            'processing' => $order->fulfillment_type === 'collection' ? ['ready_for_collection'] : ['dispatched'],
            'ready_for_collection' => ['completed'],
            'dispatched' => ['completed'],
            'awaiting_payment' => ['cancelled'],
        ];
        abort_unless(in_array($data['status'], $allowedTransitions[$order->status] ?? [], true), 409, 'This order cannot transition to the requested status.');
        if ($data['status'] !== 'cancelled') {
            abort_unless($order->payment_status === 'paid', 409, 'An order cannot be fulfilled until payment is confirmed by the payment provider.');
        }
        DB::transaction(function () use ($data, $order, $request): void {
            $lockedOrder = DB::table('ecommerce_orders')->where('id', $order->id)->lockForUpdate()->first();
            abort_unless($lockedOrder !== null && $lockedOrder->status === $order->status, 409, 'The order status changed. Refresh and try again.');
            if ($data['status'] === 'processing' && $lockedOrder->stock_issued_at === null) {
                app(InventoryProcurementController::class)->issueForEcommerceOrder($request->user(), (int) $lockedOrder->id);
            }
            DB::table('ecommerce_orders')->where('id', $order->id)->update([
                'status' => $data['status'],
                'cancelled_at' => $data['status'] === 'cancelled' ? now('UTC') : null,
                'stock_issued_at' => $data['status'] === 'processing' ? now('UTC') : $lockedOrder->stock_issued_at,
                'updated_at' => now('UTC'),
            ]);
            if ($data['status'] === 'cancelled') {
                $this->releasePromotionReservation($lockedOrder->promotion_id);
            }
        });

        $updatedOrder = DB::table('ecommerce_orders')->find($order->id);
        if ($updatedOrder !== null) {
            app(Notifier::class)->orderStatusChanged($updatedOrder, $data['status']);
        }

        return response()->json($this->orderPayload((int) $order->id));
    }

    public function decideReturn(Request $request, int $orderId, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $order = DB::table('ecommerce_orders')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $orderId)
            ->first();
        abort_if($order === null, 404);
        $scope->assertBranch($request->user(), Branch::query()->findOrFail($order->branch_id));
        abort_unless($order->return_status === 'requested', 409, 'There is no pending return request for this order.');
        DB::table('ecommerce_orders')->where('id', $order->id)->update([
            'return_status' => $data['decision'] === 'approve' ? 'refund_pending' : 'rejected',
            'updated_at' => now('UTC'),
        ]);

        $decided = DB::table('ecommerce_orders')->find($order->id);
        if ($decided !== null) {
            app(Notifier::class)->returnDecision($decided, $data['decision']);
        }

        return response()->json([
            ...$this->orderPayload((int) $order->id),
            'message' => $data['decision'] === 'approve'
                ? 'Return approved. Payment-provider refund must be completed and reconciled separately.'
                : 'Return request rejected.',
        ]);
    }

    private function expireReservations(int $organizationId, ?int $branchId = null): void
    {
        DB::transaction(fn () => $this->expireReservationsInTransaction($organizationId, $branchId, now('UTC')));
    }

    private function expireReservationsInTransaction(int $organizationId, ?int $branchId, $now): void
    {
        $expiredOrders = DB::table('ecommerce_orders')
            ->where('organization_id', $organizationId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', 'awaiting_payment')
            ->where('reservation_expires_at', '<=', $now)
            ->lockForUpdate()
            ->get(['id', 'promotion_id']);
        foreach ($expiredOrders as $expiredOrder) {
            $updated = DB::table('ecommerce_orders')
                ->where('id', $expiredOrder->id)
                ->where('status', 'awaiting_payment')
                ->update(['status' => 'expired', 'updated_at' => $now]);
            if ($updated === 1) {
                $this->releasePromotionReservation($expiredOrder->promotion_id);
            }
        }
    }

    private function releasePromotionReservation(?int $promotionId): void
    {
        if ($promotionId !== null) {
            DB::table('marketing_promotions')
                ->where('id', $promotionId)
                ->where('redemption_count', '>', 0)
                ->decrement('redemption_count');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(int $orderId, bool $replayed = false): array
    {
        $order = DB::table('ecommerce_orders')->where('id', $orderId)->first();
        abort_if($order === null, 404);
        $order->delivery_address = $order->delivery_address === null ? null : json_decode($order->delivery_address, true, flags: JSON_THROW_ON_ERROR);

        return [
            ...(array) $order,
            'lines' => DB::table('ecommerce_order_lines')
                ->where('order_id', $orderId)
                ->select('sku_snapshot as sku', 'name_snapshot as name', 'quantity', 'unit_price', 'line_total')
                ->get(),
            'replayed' => $replayed,
        ];
    }

    private function quantityMilli(float $quantity): int
    {
        return (int) round($quantity * 1000);
    }
}
