<?php

declare(strict_types=1);

namespace Threadlive\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** With threadlive.enabled off, only the bootstrap answers (saying so). */
final class EnsureEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('threadlive.enabled'), 404);

        return $next($request);
    }
}
