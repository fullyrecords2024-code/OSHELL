<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Authorization\FoundationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $branches = $scope->branches($request->user())
            ->with('organization')
            ->withCount('departments');

        if (isset($filters['organization_id'])) {
            $branches->where('organization_id', $filters['organization_id']);
        }

        if (isset($filters['is_active'])) {
            $branches->where('is_active', $filters['is_active']);
        }

        return response()->json($branches->latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => [
                'required',
                'integer',
                Rule::exists('organizations', 'id')->where('id', $request->user()->organization_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('branches', 'code')->where('organization_id', $request->user()->organization_id)],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'timezone' => ['nullable', 'timezone'],
            'status' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $branch = Branch::create($data);

        return response()->json($branch, 201);
    }

    public function show(Request $request, Branch $branch, FoundationScope $scope): JsonResponse
    {
        $scope->assertBranch($request->user(), $branch);

        return response()->json($branch->load('organization')->loadCount('departments'));
    }

    public function update(Request $request, Branch $branch, FoundationScope $scope): JsonResponse
    {
        $scope->assertBranch($request->user(), $branch);

        $targetOrganizationId = (int) $request->input('organization_id', $branch->organization_id);

        $data = $request->validate([
            'organization_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('organizations', 'id')->where('id', $request->user()->organization_id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'code')
                    ->where('organization_id', $targetOrganizationId)
                    ->ignore($branch->id),
            ],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (
            isset($data['organization_id'])
            && $data['organization_id'] !== $branch->organization_id
            && ($branch->departments()->exists() || $branch->users()->exists())
        ) {
            return response()->json([
                'message' => 'A branch with assigned departments or users cannot be moved to another organization.',
            ], 409);
        }

        $targetCode = $data['code'] ?? $branch->code;
        if (
            $targetOrganizationId !== $branch->organization_id
            && Branch::query()
                ->where('organization_id', $targetOrganizationId)
                ->where('code', $targetCode)
                ->where('id', '!=', $branch->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'code' => 'The branch code is already in use in the selected organization.',
            ]);
        }

        $branch->update($data);

        return response()->json($branch->refresh()->load('organization')->loadCount('departments'));
    }

    public function destroy(Request $request, Branch $branch, FoundationScope $scope): JsonResponse
    {
        $scope->assertBranch($request->user(), $branch);

        if ($branch->departments()->exists() || $branch->users()->exists()) {
            return response()->json([
                'message' => 'A branch with assigned departments or users cannot be deleted.',
            ], 409);
        }

        $branch->delete();

        return response()->json(null, 204);
    }
}
