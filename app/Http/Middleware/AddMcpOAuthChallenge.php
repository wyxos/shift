<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddMcpOAuthChallenge
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (AuthenticationException) {
            $response = response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($response->getStatusCode() === 401) {
            $response->headers->set('WWW-Authenticate', sprintf(
                'Bearer resource_metadata="%s", scope="%s"',
                route('mcp.oauth.protected-resource'),
                implode(' ', array_keys(config('shift_mcp.scopes'))),
            ));
        }

        return $response;
    }
}
