<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResearchRole
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user() !== null && in_array(
                (string) $request->user()->ii_research_role,
                ['analyst', 'reviewer', 'admin'],
                true
            ),
            403,
            'II research access not assigned'
        );

        return $next($request);
    }
}
