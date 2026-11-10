<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\PosPinService;
use App\Support\StaffRoleDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PosPinController extends Controller
{
    public function __construct(private readonly PosPinService $pins) {}

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->pins->status($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/', 'confirmed'],
        ]);
        if (! Hash::check($data['current_password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your account password is incorrect.',
            ]);
        }
        $this->pins->setPin($request->user(), $data['pin'], $request->user(), $request);

        return response()->json($this->pins->status($request->user()->fresh()));
    }

    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', StaffRoleDefinitions::staffRoleNames()))
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active', 'organization_id']);

        return response()->json($users->map(function (User $user): array {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'is_active' => (bool) $user->is_active,
                'pos_eligible' => $user->can('pos.view') || $user->can('pos.sale.create') || $user->can('pos.shift.manage'),
                ...$this->pins->status($user),
            ];
        })->values());
    }

    public function reset(Request $request, User $user): JsonResponse
    {
        $this->assertSameOrganization($request, $user);
        $this->pins->resetPin($user, $request->user(), $request);

        return response()->json($this->pins->status($user->fresh()));
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->assertSameOrganization($request, $user);
        $this->pins->unlock($user, $request->user(), $request);

        return response()->json($this->pins->status($user->fresh()));
    }

    public function audit(Request $request): JsonResponse
    {
        $events = AuditEvent::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('action', 'like', 'pos.pin.%')
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get(['id', 'user_id', 'action', 'subject_id', 'properties', 'ip_address', 'occurred_at'])
            ->map(function (AuditEvent $event): array {
                $properties = $event->properties ?? [];
                unset($properties['pin'], $properties['pin_hash'], $properties['pin_fingerprint']);

                return [
                    'id' => $event->id,
                    'user_id' => $event->user_id,
                    'action' => $event->action,
                    'subject_id' => $event->subject_id,
                    'properties' => $properties,
                    'ip_address' => $event->ip_address,
                    'occurred_at' => $event->occurred_at,
                ];
            });

        return response()->json($events);
    }

    private function assertSameOrganization(Request $request, User $user): void
    {
        abort_unless(
            (int) $user->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }
}
