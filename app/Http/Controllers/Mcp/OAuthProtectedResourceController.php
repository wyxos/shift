<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OAuthProtectedResourceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'resource' => config('shift_mcp.resource'),
            'authorization_servers' => [config('shift_mcp.issuer')],
            'scopes_supported' => array_keys(config('shift_mcp.scopes')),
            'bearer_methods_supported' => ['header'],
        ]);
    }
}
