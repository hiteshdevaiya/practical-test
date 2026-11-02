<?php

declare(strict_types=1);

use App\Http\Middleware\AddServerTiming;
use App\Http\Middleware\AuthenticateApiClient;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.client' => AuthenticateApiClient::class,
            'server.timing' => AddServerTiming::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients always receive JSON, even without an Accept header.
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
