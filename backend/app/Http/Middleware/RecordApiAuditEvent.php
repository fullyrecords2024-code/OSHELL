<?php

namespace App\Http\Middleware;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordApiAuditEvent
{
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        $user = $request->user();
        $subject = collect($request->route()?->parameters() ?? [])
            ->first(static fn ($value): bool => $value instanceof Model);
        $routeName = $request->route()?->getName() ?? 'api.unknown';
        $organizationId = $user?->organization_id;
        $branchId = $user?->branch_id;

        if ($subject instanceof Branch) {
            $organizationId = $subject->organization_id;
            $branchId = $subject->id;
        } elseif ($subject instanceof Department) {
            $organizationId = $subject->organization_id;
            $branchId = $subject->branch_id;
        } elseif ($subject instanceof Organization) {
            $organizationId = $subject->id;
            $branchId = null;
        }

        AuditEvent::create([
            'user_id' => $user?->getAuthIdentifier(),
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'action' => strtolower($request->method()).':'.$routeName,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'status_code' => $response->getStatusCode(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => ['outcome' => $response->isSuccessful() ? 'success' : 'failure'],
            'occurred_at' => now('UTC'),
        ]);

        return $response;
    }
}
