<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\Authorization\FoundationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditEventController extends Controller
{
    public function index(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'action' => ['sometimes', 'string', 'max:160'],
            'from' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date'],
        ]);

        $query = AuditEvent::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with(['user:id,name,email', 'branch:id,name,code'])
            ->latest('occurred_at');

        if (! $request->user()->can('branch.view_all')) {
            $query->whereIn('branch_id', $scope->branchIds($request->user()));
        }

        if (
            isset($filters['from'], $filters['until'])
            && $filters['until'] < $filters['from']
        ) {
            abort(422, 'The until date must be on or after the from date.');
        }

        foreach (['branch_id', 'user_id', 'action'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }
        if (isset($filters['until'])) {
            $query->where('occurred_at', '<=', $filters['until']);
        }

        return response()->json($query->paginate(50));
    }
}
