<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Authorization\FoundationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $request, FoundationScope $scope): JsonResponse
    {
        return response()->json(
            $scope->organizations($request->user())
                ->latest()
                ->get()
                ->map(fn (Organization $organization): Organization => $this->withScopedCounts($organization, $request, $scope))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $organization = Organization::create($request->validate($this->rules()));

        return response()->json($organization, 201);
    }

    public function show(Request $request, Organization $organization, FoundationScope $scope): JsonResponse
    {
        $scope->assertOrganization($request->user(), $organization);

        return response()->json($this->withScopedCounts($organization, $request, $scope));
    }

    public function update(Request $request, Organization $organization, FoundationScope $scope): JsonResponse
    {
        $scope->assertOrganization($request->user(), $organization);
        $organization->update($request->validate($this->rules($organization)));

        return response()->json($this->withScopedCounts($organization->refresh(), $request, $scope));
    }

    public function destroy(Request $request, Organization $organization, FoundationScope $scope): JsonResponse
    {
        $scope->assertOrganization($request->user(), $organization);

        if (
            $organization->branches()->exists()
            || $organization->departments()->exists()
            || $organization->users()->whereKeyNot($request->user()->getAuthIdentifier())->exists()
        ) {
            return response()->json([
                'message' => 'An organization with branches, departments, or users cannot be deleted.',
            ], 409);
        }

        $organization->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Organization $organization = null): array
    {
        $required = $organization === null ? ['required'] : ['sometimes', 'required'];

        return [
            'name' => [...$required, 'string', 'max:255'],
            'slug' => [...$required, 'string', 'max:120', Rule::unique('organizations', 'slug')->ignore($organization?->id)],
            'business_type' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'timezone'],
            'status' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:120'],
            'registration_number' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function withScopedCounts(
        Organization $organization,
        Request $request,
        FoundationScope $scope,
    ): Organization {
        $organization->setAttribute('branches_count', $scope->branches($request->user())->count());
        $organization->setAttribute('departments_count', $scope->departments($request->user())->count());

        return $organization;
    }
}
