<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Support\Authorization\FoundationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $departments = $scope->departments($request->user())
            ->with(['branch', 'organization']);

        if (isset($filters['organization_id'])) {
            $departments->where('organization_id', $filters['organization_id']);
        }

        if (array_key_exists('branch_id', $filters)) {
            $departments->where('branch_id', $filters['branch_id']);
        }

        if (isset($filters['is_active'])) {
            $departments->where('is_active', $filters['is_active']);
        }

        return response()->json($departments->latest()->get());
    }

    public function store(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => [
                'required',
                'integer',
                Rule::exists('organizations', 'id')->where('id', $request->user()->organization_id),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('organization_id', $request->input('organization_id')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', $this->codeRule((int) $request->input('organization_id'))],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:20'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($data['branch_id'] !== null) {
            $scope->assertBranch($request->user(), Branch::findOrFail($data['branch_id']));
        } elseif (! $request->user()->can('branch.view_all')) {
            abort(403);
        }

        $department = Department::create($data);

        return response()->json($department, 201);
    }

    public function show(Request $request, Department $department, FoundationScope $scope): JsonResponse
    {
        $scope->assertDepartment($request->user(), $department);

        return response()->json($department->load(['branch', 'organization']));
    }

    public function update(Request $request, Department $department, FoundationScope $scope): JsonResponse
    {
        $scope->assertDepartment($request->user(), $department);

        $organizationId = (int) $request->input('organization_id', $department->organization_id);
        $data = $request->validate([
            'organization_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('organizations', 'id')->where('id', $request->user()->organization_id),
            ],
            'branch_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('organization_id', $organizationId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:80',
                $this->codeRule($organizationId, $department->id),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $effectiveOrganizationId = (int) ($data['organization_id'] ?? $department->organization_id);
        $effectiveBranchId = array_key_exists('branch_id', $data)
            ? $data['branch_id']
            : $department->branch_id;
        $this->ensureBranchBelongsToOrganization($effectiveBranchId, $effectiveOrganizationId);
        if ($effectiveBranchId !== null) {
            $scope->assertBranch($request->user(), Branch::findOrFail($effectiveBranchId));
        } elseif (! $request->user()->can('branch.view_all')) {
            abort(403);
        }

        $department->update($data);

        return response()->json($department->refresh()->load(['branch', 'organization']));
    }

    public function destroy(Request $request, Department $department, FoundationScope $scope): JsonResponse
    {
        $scope->assertDepartment($request->user(), $department);

        $department->delete();

        return response()->json(null, 204);
    }

    private function codeRule(int $organizationId, ?int $ignoreId = null)
    {
        $rule = Rule::unique('departments', 'code')
            ->where('organization_id', $organizationId);

        if ($ignoreId !== null) {
            $rule->ignore($ignoreId);
        }

        return $rule;
    }

    private function ensureBranchBelongsToOrganization(?int $branchId, int $organizationId): void
    {
        if (
            $branchId !== null
            && ! Branch::query()
                ->whereKey($branchId)
                ->where('organization_id', $organizationId)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'branch_id' => 'The selected branch must belong to the selected organization.',
            ]);
        }
    }
}
