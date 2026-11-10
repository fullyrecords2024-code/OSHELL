<?php

namespace App\Http\Middleware;

use App\Support\Settings\MailSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApplyMailSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            MailSettings::apply();
        } catch (Throwable) {
            // Fall back to the environment configuration if settings are unavailable.
        }

        return $next($request);
    }
}
