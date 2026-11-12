<?php

namespace App\Http\Middleware;

use App\ServiceAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureServiceIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $service = ServiceAvailability::forRoute($request->route()?->getName());
        if ($service !== null && ! ServiceAvailability::isEnabled($service)) {
            if ($request->expectsJson()) {
                abort(503, 'This service is temporarily unavailable. Please try again later.');
            }

            return redirect()->route('dashboard')->with('status', 'This service is temporarily unavailable. Please try again later.');
        }

        return $next($request);
    }
}
