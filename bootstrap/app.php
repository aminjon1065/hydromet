<?php

use App\Http\Api\ApiErrorRenderer;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Register the Artisan command classes in app/Console/Commands. Passing a
    // console routes file to withRouting() only registers that file.
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        // Global rather than group-scoped, and outermost: an unmatched route and
        // an exception raised inside the route both bypass group middleware,
        // and those responses need the headers as much as a successful page.
        $middleware->prepend(SecurityHeaders::class);

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->api(append: [SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $renderer = app(ApiErrorRenderer::class);

            return $renderer->handles($exception)
                ? $renderer->render($exception, $request)
                : null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
