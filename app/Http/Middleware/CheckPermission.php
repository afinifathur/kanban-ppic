<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Check permission using Spatie Laravel Permission
        if ($user->can($permission)) {
            return $next($request);
        }

        abort(403, 'Unauthorized action. Access denied for your role.');
    }
}
