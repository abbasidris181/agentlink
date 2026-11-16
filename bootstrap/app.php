<?php

use App\Http\Middleware\ApplySiteSettings;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: ['api/payments/monnify/webhook', 'api/payments/monielinks/webhook']);
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->web(append: [ApplySiteSettings::class, EnsureActiveAccount::class, AuthenticateSession::class]);
        $middleware->redirectGuestsTo(fn (): string => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
