<?php

use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\VerifyPixWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Testing\TestResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'verify.pix.webhook' => VerifyPixWebhookSignature::class,
        ]);
    })
    ->booted(function () {
        // Register the role middleware alias on the router directly,
        // so it's available even before the HTTP kernel is resolved
        // (e.g., when tests check $router->getMiddleware() without
        // making an HTTP request first).
        app('router')->aliasMiddleware('role', EnsureUserRole::class);
        app('router')->aliasMiddleware('verify.pix.webhook', VerifyPixWebhookSignature::class);

        // Add a public session() macro on TestResponse so tests can
        // access session data from redirect responses.
        TestResponse::macro('session', function () {
            $session = app('session.store');

            if (! $session->isStarted()) {
                $session->start();
            }

            return $session;
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
