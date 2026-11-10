<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\BranchBusinessHour;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceImage;
use App\Models\User;
use App\Support\Authorization\FoundationScope;
use App\Support\SlugGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ServiceCatalogueController extends Controller
{
    public function index(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
        ]);
        $query = Service::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with(['category:id,name', 'branches', 'images']);

        if (! $request->user()->can('services.manage')) {
            $query->where('is_active', true);
        }

        if (isset($filters['branch_id'])) {
            $branch = Branch::query()->findOrFail($filters['branch_id']);
            $scope->assertBranch($request->user(), $branch);
            $query->whereHas('branches', static fn ($branchQuery) => $branchQuery
                ->where('branches.id', $branch->id)
                ->where('service_branches.is_available', true));
        } elseif (! $request->user()->can('branch.view_all')) {
            $query->whereHas('branches', static fn ($branchQuery) => $branchQuery
                ->whereIn('branches.id', $scope->branchIds($request->user()))
                ->where('service_branches.is_available', true));
        }

        return response()->json($query->orderBy('name')->get()->map(
            fn (Service $service): array => $this->servicePayload($service, false)
        ));
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);
        $branch = Branch::query()
            ->where('is_active', true)
            ->findOrFail($filters['branch_id']);

        return response()->json(
            Service::query()
                ->where('organization_id', $branch->organization_id)
                ->where('is_active', true)
                ->whereHas('branches', static fn ($branchQuery) => $branchQuery
                    ->where('branches.id', $branch->id)
                    ->where('service_branches.is_available', true)
                    ->where('service_branches.is_public', true))
                ->with(['category:id,name', 'branches', 'images'])
                ->orderBy('name')
                ->get()
                ->map(fn (Service $service): array => $this->servicePayload($service, true))
        );
    }

    public function publicService(Request $request, string $slug): JsonResponse
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

        $service = Service::query()
            ->where('organization_id', $organization->id)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('branches', static fn ($branchQuery) => $branchQuery
                ->where('branches.id', $branch->id)
                ->where('service_branches.is_available', true)
                ->where('service_branches.is_public', true))
            ->with(['category:id,name', 'branches', 'images'])
            ->first();
        abort_if($service === null, 404, 'Service not found.');

        $payload = $this->servicePayload($service, true);
        $payload['branches'] = collect($payload['branches'])
            ->where('id', $branch->id)
            ->values()
            ->all();

        return response()->json([
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug, 'currency_code' => $organization->currency_code],
            'branch_id' => $branch->id,
            'service' => $payload,
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        return response()->json(ServiceCategory::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $category = ServiceCategory::create([
            ...$data,
            'organization_id' => $request->user()->organization_id,
        ]);

        return response()->json($category, 201);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', Rule::exists('service_categories', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'classification' => ['required', Rule::in(['beauty', 'clinical', 'aesthetic', 'spa', 'other'])],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'required_qualifications' => ['sometimes', 'array', 'max:20'],
            'required_qualifications.*' => ['string', 'max:80'],
            'required_resource_types' => ['sometimes', 'array', 'max:2'],
            'required_resource_types.*' => ['string', Rule::in(['room', 'equipment'])],
        ]);
        $service = Service::create([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'slug' => SlugGenerator::unique('services', (int) $request->user()->organization_id, $data['name']),
            'buffer_minutes' => $data['buffer_minutes'] ?? 0,
            'is_active' => true,
        ]);

        return response()->json($service->load(['category', 'images']), 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $this->assertServiceOrganization($request, $service);
        $data = $request->validate([
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('service_categories', 'id')
                ->where('organization_id', $request->user()->organization_id)],
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'classification' => ['sometimes', Rule::in(['beauty', 'clinical', 'aesthetic', 'spa', 'other'])],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'required_qualifications' => ['sometimes', 'nullable', 'array', 'max:20'],
            'required_qualifications.*' => ['string', 'max:80'],
            'required_resource_types' => ['sometimes', 'nullable', 'array', 'max:2'],
            'required_resource_types.*' => ['string', Rule::in(['room', 'equipment'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (empty($service->slug)) {
            $data['slug'] = SlugGenerator::unique(
                'services',
                (int) $request->user()->organization_id,
                (string) ($data['name'] ?? $service->name),
                (int) $service->id,
            );
        }
        $service->update($data);
        $service->load(['category:id,name', 'branches', 'images']);

        return response()->json($this->servicePayload($service, false));
    }

    public function storeImages(Request $request, Service $service): JsonResponse
    {
        $this->assertServiceOrganization($request, $service);
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $existing = ServiceImage::query()->where('service_id', $service->id)->count();
        abort_if($existing + count($data['images']) > 12, 422, 'A service can hold at most 12 images.');

        $hasPrimary = ServiceImage::query()->where('service_id', $service->id)->where('is_primary', true)->exists();
        $sortOrder = (int) ServiceImage::query()->where('service_id', $service->id)->max('sort_order');

        foreach ($data['images'] as $index => $file) {
            $path = $file->store("catalogue/services/{$service->organization_id}/{$service->id}", 'public');
            ServiceImage::create([
                'organization_id' => $service->organization_id,
                'service_id' => $service->id,
                'path' => $path,
                'alt_text' => $service->name,
                'sort_order' => ++$sortOrder,
                'is_primary' => ! $hasPrimary && $index === 0,
            ]);
            $hasPrimary = true;
        }

        return response()->json(['images' => $this->serviceImagesPayload((int) $service->id)], 201);
    }

    public function deleteImage(Request $request, Service $service, int $image): JsonResponse
    {
        $this->assertServiceOrganization($request, $service);
        $record = ServiceImage::query()->where('service_id', $service->id)->whereKey($image)->firstOrFail();
        $wasPrimary = $record->is_primary;
        Storage::disk('public')->delete($record->path);
        $record->delete();

        if ($wasPrimary) {
            ServiceImage::query()->where('service_id', $service->id)
                ->orderBy('sort_order')->first()?->update(['is_primary' => true]);
        }

        return response()->json(['images' => $this->serviceImagesPayload((int) $service->id)]);
    }

    public function setPrimaryImage(Request $request, Service $service, int $image): JsonResponse
    {
        $this->assertServiceOrganization($request, $service);
        $record = ServiceImage::query()->where('service_id', $service->id)->whereKey($image)->firstOrFail();
        ServiceImage::query()->where('service_id', $service->id)->update(['is_primary' => false]);
        $record->update(['is_primary' => true]);

        return response()->json(['images' => $this->serviceImagesPayload((int) $service->id)]);
    }

    private function assertServiceOrganization(Request $request, Service $service): void
    {
        abort_unless((int) $service->organization_id === (int) $request->user()->organization_id, 404);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serviceImagesPayload(int $serviceId): array
    {
        return ServiceImage::query()
            ->where('service_id', $serviceId)
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (ServiceImage $image): array => [
                'id' => $image->id,
                'url' => $image->url,
                'is_primary' => $image->is_primary,
            ])->values()->all();
    }

    public function configureBranch(
        Request $request,
        Service $service,
        FoundationScope $scope,
    ): JsonResponse {
        abort_unless((int) $service->organization_id === (int) $request->user()->organization_id, 404);
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency_code' => ['required', 'string', 'size:3'],
            'is_available' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'deposit_policy' => ['required', Rule::in(['pay_at_clinic', 'fixed', 'full'])],
            'deposit_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'booking_lead_hours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
            'cancellation_notice_hours' => ['sometimes', 'integer', 'min:0', 'max:8760'],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);
        abort_unless((int) $branch->organization_id === (int) $service->organization_id, 422);

        $depositAmount = $data['deposit_amount'] ?? 0;
        if ($data['deposit_policy'] === 'fixed' && $depositAmount > $data['price']) {
            return response()->json(['message' => 'A fixed deposit cannot exceed the service price.'], 422);
        }

        $service->branches()->syncWithoutDetaching([
            $branch->id => [
                'organization_id' => $service->organization_id,
                'price' => $data['price'],
                'currency_code' => strtoupper($data['currency_code']),
                'is_available' => $data['is_available'] ?? true,
                'is_public' => $data['is_public'] ?? false,
                'deposit_policy' => $data['deposit_policy'],
                'deposit_amount' => $depositAmount,
                'booking_lead_hours' => $data['booking_lead_hours'] ?? 0,
                'cancellation_notice_hours' => $data['cancellation_notice_hours'] ?? 24,
            ],
        ]);

        return response()->json($service->load('branches'));
    }

    public function setBusinessHours(
        Request $request,
        FoundationScope $scope,
    ): JsonResponse {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'hours.*.is_closed' => ['required', 'boolean'],
            'hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'hours.*.closes_at' => ['nullable', 'date_format:H:i'],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);

        foreach ($data['hours'] as $hours) {
            if (! $hours['is_closed'] && (
                empty($hours['opens_at'])
                || empty($hours['closes_at'])
                || $hours['opens_at'] >= $hours['closes_at']
            )) {
                return response()->json(['message' => 'Open business hours require a valid opening and closing time.'], 422);
            }
        }

        DB::transaction(function () use ($data, $branch): void {
            foreach ($data['hours'] as $hours) {
                BranchBusinessHour::updateOrCreate(
                    ['branch_id' => $branch->id, 'day_of_week' => $hours['day_of_week']],
                    [
                        'organization_id' => $branch->organization_id,
                        'opens_at' => $hours['is_closed'] ? null : $hours['opens_at'],
                        'closes_at' => $hours['is_closed'] ? null : $hours['closes_at'],
                        'is_closed' => $hours['is_closed'],
                    ],
                );
            }
        });

        return response()->json(BranchBusinessHour::query()
            ->where('branch_id', $branch->id)
            ->orderBy('day_of_week')
            ->get());
    }

    public function businessHours(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);

        return response()->json(BranchBusinessHour::query()
            ->where('branch_id', $branch->id)
            ->orderBy('day_of_week')
            ->get());
    }

    public function resources(Request $request, FoundationScope $scope): JsonResponse
    {
        $query = BookingResource::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with('schedules');
        if (! $request->user()->can('branch.view_all')) {
            $query->whereIn('branch_id', $scope->branchIds($request->user()));
        }

        return response()->json($query->orderBy('branch_id')->orderBy('name')->get());
    }

    public function storeResource(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:160'],
            'resource_type' => ['required', Rule::in(['staff', 'room', 'equipment'])],
            'qualifications' => ['sometimes', 'array', 'max:20'],
            'qualifications.*' => ['string', 'max:80'],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);
        if ($data['resource_type'] === 'staff') {
            abort_unless(isset($data['user_id']), 422, 'Staff resources must be linked to a user.');
            $staff = User::query()->findOrFail($data['user_id']);
            abort_unless((int) $staff->organization_id === (int) $branch->organization_id, 422);
            $scope->assertBranch($request->user(), $staff->branch ?? $branch);
        } elseif (isset($data['user_id'])) {
            return response()->json(['message' => 'Only staff resources can be linked to a user.'], 422);
        }

        $resource = BookingResource::create([
            ...$data,
            'organization_id' => $branch->organization_id,
            'is_active' => true,
        ]);

        return response()->json($resource, 201);
    }

    public function setResourceSchedule(
        Request $request,
        BookingResource $resource,
        FoundationScope $scope,
    ): JsonResponse {
        abort_unless((int) $resource->organization_id === (int) $request->user()->organization_id, 404);
        $scope->assertBranch($request->user(), $resource->branch);
        $data = $request->validate([
            'schedules' => ['required', 'array', 'size:7'],
            'schedules.*.day_of_week' => ['required', 'integer', 'between:0,6', 'distinct'],
            'schedules.*.is_unavailable' => ['required', 'boolean'],
            'schedules.*.starts_at' => ['nullable', 'date_format:H:i'],
            'schedules.*.ends_at' => ['nullable', 'date_format:H:i'],
            'schedules.*.effective_from' => ['nullable', 'date'],
            'schedules.*.effective_until' => ['nullable', 'date', 'after_or_equal:schedules.*.effective_from'],
        ]);
        foreach ($data['schedules'] as $schedule) {
            if (! $schedule['is_unavailable'] && (
                empty($schedule['starts_at'])
                || empty($schedule['ends_at'])
                || $schedule['starts_at'] >= $schedule['ends_at']
            )) {
                return response()->json(['message' => 'Available resource schedules require valid start and end times.'], 422);
            }
        }

        DB::transaction(function () use ($data, $resource): void {
            foreach ($data['schedules'] as $schedule) {
                $resource->schedules()->updateOrCreate(
                    ['day_of_week' => $schedule['day_of_week']],
                    [
                        'organization_id' => $resource->organization_id,
                        'starts_at' => $schedule['is_unavailable'] ? null : $schedule['starts_at'],
                        'ends_at' => $schedule['is_unavailable'] ? null : $schedule['ends_at'],
                        'is_unavailable' => $schedule['is_unavailable'],
                        'effective_from' => $schedule['effective_from'] ?? null,
                        'effective_until' => $schedule['effective_until'] ?? null,
                    ],
                );
            }
        });

        return response()->json($resource->load('schedules'));
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePayload(Service $service, bool $public): array
    {
        $images = $service->images
            ->map(static fn (ServiceImage $image): array => [
                'id' => $image->id,
                'url' => $image->url,
                'is_primary' => $image->is_primary,
            ])->values()->all();
        $primary = collect($images)->firstWhere('is_primary', true) ?? ($images[0] ?? null);

        return [
            'id' => $service->id,
            'slug' => $service->slug,
            'name' => $service->name,
            'description' => $service->description,
            'classification' => $service->classification,
            'category' => $service->category,
            'duration_minutes' => $service->duration_minutes,
            'buffer_minutes' => $service->buffer_minutes,
            'is_active' => (bool) $service->is_active,
            'images' => $images,
            'primary_image_url' => $primary['url'] ?? null,
            'branches' => $service->branches
                ->filter(fn (Branch $branch): bool => ! $public
                    || ((bool) $branch->pivot->is_available && (bool) $branch->pivot->is_public))
                ->map(static fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'price' => $branch->pivot->price,
                    'currency_code' => $branch->pivot->currency_code,
                    'is_public' => (bool) $branch->pivot->is_public,
                    'is_available' => (bool) $branch->pivot->is_available,
                    'deposit_policy' => $branch->pivot->deposit_policy,
                    'deposit_amount' => $branch->pivot->deposit_amount,
                    'booking_lead_hours' => $branch->pivot->booking_lead_hours,
                    'cancellation_notice_hours' => $branch->pivot->cancellation_notice_hours,
                ])->values(),
        ];
    }
}
