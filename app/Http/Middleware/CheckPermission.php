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

        // Support multiple permissions separated by pipe '|' (e.g., 'access_execution|access_production_visibility')
        $permissions = explode('|', $permission);
        foreach ($permissions as $perm) {
            $trimmed = trim($perm);
            if ($trimmed !== '' && $user->can($trimmed)) {
                return $next($request);
            }
        }

        abort(403, 'Unauthorized action. Access denied for your role.');
    }
}
