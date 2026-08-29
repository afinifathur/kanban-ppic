<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventQcMutation
{
    /**
     * Handle an incoming request.
     * Enforce strict read-only access for admin_qc_fitting role.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Allow authentication session termination (logout)
        if ($request->routeIs('logout') || $request->is('logout')) {
            return $next($request);
        }

        if (auth()->check() && auth()->user()->hasRole('admin_qc_fitting')) {
            if (! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
                abort(403, 'Akses ditolak. Akun QC bersifat Read-Only dan tidak memiliki hak mutasi data.');
            }
        }

        return $next($request);
    }
}
