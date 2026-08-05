<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpOAuthResource
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resource = $request->string('resource')->toString();

        if ($resource === '' || ! hash_equals((string) config('shift_mcp.resource'), $resource)) {
            return response()->json([
                'error' => 'invalid_target',
                'error_description' => 'The OAuth request must target this SHIFT MCP resource.',
            ], 400);
        }

        return $next($request);
    }
}
