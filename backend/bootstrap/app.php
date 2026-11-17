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
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->call(function (): void {
            \Illuminate\Support\Facades\Cache::put('operations:scheduler-heartbeat', now()->timestamp, now()->addDay());
            \App\Jobs\RecordQueueHeartbeat::dispatch();
        })->name('notifications-heartbeat')->everyMinute()->withoutOverlapping(5);
        $schedule->command('inventory:refresh-alerts')->everyMinute()->withoutOverlapping(30);
        $schedule->command('telegram:remind-inventory')->everyMinute()->withoutOverlapping(30);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA authenticates API requests with Sanctum bearer tokens.
        // Do not enable stateful cookie authentication here: doing so applies
        // CSRF validation to same-origin API requests such as login/register.
        $middleware->alias([
            'password.changed' => \App\Http\Middleware\EnsurePasswordChanged::class,
            'workspace.context' => \App\Http\Middleware\WorkspaceContext::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
