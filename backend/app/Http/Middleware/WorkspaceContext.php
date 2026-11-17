<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WorkspaceContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $primary = (int) $user->getRawOriginal('business_id');
        $abilities = $user->currentAccessToken()?->abilities ?? [];
        $workspace = collect($abilities)->first(fn ($ability) => str_starts_with($ability, 'workspace:'));
        $id = $workspace ? (int) substr($workspace, 10) : $primary;
        abort_unless($user->useWorkspace($id), 403);
        $response = $next($request);
        if ($response instanceof \Illuminate\Http\JsonResponse && $response->isSuccessful()) {
            $response->setData(app(\App\Services\WorkspaceDataVisibility::class)->filter($response->getData(true), $user));
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        return $response;
    }
}
