<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AccessMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccessMode
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('access.*')) {
            return $next($request);
        }

        if (! AccessMode::isSet()) {
            return redirect()->route('access.choose');
        }

        if (AccessMode::isDataEntry() && ! AccessMode::allowsRoute($request)) {
            return redirect()
                ->route('data-entry.dashboard')
                ->with('warning', 'Use the data entry dashboard to open catalogue or sellable product tools.');
        }

        return $next($request);
    }
}
