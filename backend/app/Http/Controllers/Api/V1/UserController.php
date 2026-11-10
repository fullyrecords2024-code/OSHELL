<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\StaffRoleDefinitions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->organizationUsers($request)
                ->with(['roles', 'branchAssignments'])
                ->orderBy('name')
                ->paginate(50)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate($this->rules($actor));

        $this->validateAssignments($data['branch_assignments'] ?? [], (int) $actor->organization_id);

        $user = DB::transaction(function () use ($data, $actor): User {
            $primaryBranchId = $this->primaryBranchId($data['branch_assignments'] ?? []);
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'organization_id' => $actor->organization_id,
                'branch_id' => $primaryBranchId,
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
                'password_reset_required' => true,
            ]);

            $user->syncRoles($data['roles']);
            $this->syncAssignments($user, $data['branch_assignments'] ?? [], $actor->id, (int) $actor->organization_id);

            return $user;
        });

        $user->sendEmailVerificationNotification();
        $user->sendPasswordResetNotification(Password::broker()->createToken($user));

        return response()->json(
            $user->load(['roles', 'branchAssignments']),
            201
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->assertSameOrganization($request, $user);

        return response()->json($user->load(['roles', 'branchAssignments']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->assertSameOrganization($request, $user);

        if (! $actor->hasRole(StaffRoleDefinitions::SUPER_ADMIN) && $user->hasRole(StaffRoleDefinitions::SUPER_ADMIN)) {
            abort(403);
        }

        $data = $request->validate($this->rules($actor, $user));
        if (isset($data['branch_assignments'])) {
            $this->validateAssignments($data['branch_assignments'], (int) $actor->organization_id);
        }
        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;

        if (
            $user->is($actor)
            && array_key_exists('is_active', $data)
            && ! $data['is_active']
        ) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        if (
            $user->is($actor)
            && isset($data['roles'])
            && ! in_array('Super Admin', $data['roles'], true)
            && $user->hasRole('Super Admin')
        ) {
            throw ValidationException::withMessages([
                'roles' => ['You cannot remove your own Super Admin role.'],
            ]);
        }

        if (
            $user->hasRole('Super Admin')
            && isset($data['roles'])
            && ! in_array('Super Admin', $data['roles'], true)
            && User::query()
                ->where('organization_id', $user->organization_id)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->whereHas('roles', static fn ($query) => $query->where('name', 'Super Admin'))
                ->doesntExist()
        ) {
            throw ValidationException::withMessages([
                'roles' => ['The organization must retain at least one active Super Admin.'],
            ]);
        }

        DB::transaction(function () use ($data, $user, $actor): void {
            $user->fill(collect($data)->only(['name', 'email', 'is_active'])->all());

            if (array_key_exists('branch_assignments', $data)) {
                $user->branch_id = $this->primaryBranchId($data['branch_assignments']);
                $this->syncAssignments(
                    $user,
                    $data['branch_assignments'],
                    $actor->id,
                    (int) $actor->organization_id,
                );
            }

            if (isset($data['email']) && $data['email'] !== $user->getOriginal('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }
        });

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            $user->tokens()->delete();
        }

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json($user->refresh()->load(['roles', 'branchAssignments']));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(User $actor, ?User $user = null): array
    {
        return [
            'name' => [$user === null ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'email' => [
                $user === null ? 'required' : 'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'roles' => [$user === null ? 'required' : 'sometimes', 'required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in($this->assignableRoleNames($actor))],
            'branch_assignments' => ['sometimes', 'array'],
            'branch_assignments.*.branch_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->where('organization_id', $actor->organization_id),
            ],
            'branch_assignments.*.effective_from' => ['required', 'date'],
            'branch_assignments.*.effective_until' => ['nullable', 'date', 'after_or_equal:branch_assignments.*.effective_from'],
            'branch_assignments.*.is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function assignableRoleNames(User $actor): array
    {
        $roles = StaffRoleDefinitions::staffRoleNames();

        if ($actor->hasRole(StaffRoleDefinitions::SUPER_ADMIN)) {
            $roles[] = StaffRoleDefinitions::SUPER_ADMIN;
        }

        return $roles;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     */
    private function validateAssignments(array $assignments, int $organizationId): void
    {
        $primaryAssignments = collect($assignments)
            ->filter(static fn (array $assignment): bool => (bool) ($assignment['is_primary'] ?? false));

        if ($primaryAssignments->count() > 1) {
            throw ValidationException::withMessages([
                'branch_assignments' => ['Only one branch assignment can be primary.'],
            ]);
        }

        foreach ($assignments as $assignment) {
            if (isset($assignment['effective_until']) && $assignment['effective_until'] < $assignment['effective_from']) {
                throw ValidationException::withMessages([
                    'branch_assignments' => ['An assignment end date cannot be before its start date.'],
                ]);
            }

            $branch = Branch::query()->find($assignment['branch_id']);
            if ($branch === null || (int) $branch->organization_id !== $organizationId) {
                throw ValidationException::withMessages([
                    'branch_assignments' => ['Every assigned branch must belong to the user organization.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     */
    private function syncAssignments(User $user, array $assignments, int $actorId, int $organizationId): void
    {
        $sync = [];

        foreach ($assignments as $assignment) {
            $sync[$assignment['branch_id']] = [
                'organization_id' => $organizationId,
                'assigned_by' => $actorId,
                'effective_from' => $assignment['effective_from'],
                'effective_until' => $assignment['effective_until'] ?? null,
                'is_primary' => (bool) ($assignment['is_primary'] ?? false),
            ];
        }

        $user->branchAssignments()->sync($sync);
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     */
    private function primaryBranchId(array $assignments): ?int
    {
        foreach ($assignments as $assignment) {
            if ((bool) ($assignment['is_primary'] ?? false)) {
                return (int) $assignment['branch_id'];
            }
        }

        return isset($assignments[0]) ? (int) $assignments[0]['branch_id'] : null;
    }

    private function assertSameOrganization(Request $request, User $user): void
    {
        if ((int) $user->organization_id !== (int) $request->user()->organization_id) {
            abort(404);
        }
    }

    private function organizationUsers(Request $request): Builder
    {
        return User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->whereNull('customer_id');
    }
}
