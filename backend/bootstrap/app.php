<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA authenticates API requests with Sanctum bearer tokens.
        // Do not enable stateful cookie authentication here: doing so applies
        // CSRF validation to same-origin API requests such as login/register.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
