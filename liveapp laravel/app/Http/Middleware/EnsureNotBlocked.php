<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->is_blocked) {
            auth()->logout();
            return redirect()->route('home')->with('err','Your account is blocked.');
        }
        return $next($request);
    }
}
