<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // These /api/payments/* routes live in routes/web.php (there's no routes/api.php),
        // so without this they get Laravel's default *web* exception handling: a
        // ValidationException (or any other error) redirects back with flashed errors
        // instead of returning JSON, whenever the caller doesn't send an
        // "Accept: application/json" header — which most non-browser HTTP clients
        // (Postman, curl, server-to-server calls) don't send by default. That redirect
        // has nowhere to go back to on a raw API call, so it falls back to "/", and a
        // client that follows redirects (Postman does by default) ends up looking at
        // the Laravel welcome page with a confusing 200 OK instead of the real error.
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
