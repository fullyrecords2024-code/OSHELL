<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChangeComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->password_reset_required) {
            return response()->json([
                'message' => 'Change the temporary password before continuing.',
                'code' => 'password_reset_required',
            ], 403);
        }

        return $next($request);
    }
}
