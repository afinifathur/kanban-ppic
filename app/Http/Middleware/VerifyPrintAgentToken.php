<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyPrintAgentToken
{
    public function handle(Request $request, Closure $next)
    {
        $expectedToken = config('lost_wax.print_agent_token');
        $token = $request->bearerToken();

        if (empty($expectedToken) || $token !== $expectedToken) {
            return response()->json(['message' => 'Unauthorized. Invalid API Token.'], 401);
        }

        return $next($request);
    }
}
