<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSignupEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->fresh()?->needsEmailVerification()) {
            if ($request->expectsJson()) {
                abort(403, 'Please verify your email address.');
            }

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
