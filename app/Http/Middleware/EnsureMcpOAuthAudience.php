<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpOAuthAudience
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $claims = $this->claims($request);
        $audiences = isset($claims['aud']) ? (array) $claims['aud'] : [];

        if (! is_string($claims['iss'] ?? null)
            || ! hash_equals((string) config('shift_mcp.issuer'), $claims['iss'])
            || ! in_array(config('shift_mcp.resource'), $audiences, true)) {
            return response()->json(['message' => 'The OAuth access token is not valid for this MCP server.'], 401);
        }

        return $next($request);
    }

    /**
     * Read claims only after Passport has verified the token signature, expiry, and revocation state.
     *
     * @return array<string, mixed>
     */
    private function claims(Request $request): array
    {
        $segments = explode('.', (string) $request->bearerToken());

        if (count($segments) !== 3) {
            return [];
        }

        $payload = strtr($segments[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);

        if (! is_string($decoded)) {
            return [];
        }

        try {
            $claims = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($claims) ? $claims : [];
    }
}
