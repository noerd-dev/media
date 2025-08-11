<?php

namespace Nywerk\Media\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MediaMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        session(['currentApp' => 'MEDIA']);

        $hasAppActive = auth()->user()->selectedTenant()->tenantApps()->where('name', 'MEDIA')->count();
        if ($hasAppActive === 0) {
            return redirect('/');
        }

        return $next($request);
    }
}
