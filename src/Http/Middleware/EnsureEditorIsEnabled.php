<?php

namespace OpenApiStudio\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns 404 unless the editor is enabled and the app runs in an allowed
 * environment (local only by default), so staging and production never
 * expose a page that writes files.
 */
class EnsureEditorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('openapi-studio.environments', ['local']);

        if (! config('openapi-studio.enabled', true) || ! app()->environment($allowed)) {
            abort(404);
        }

        return $next($request);
    }
}
