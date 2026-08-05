<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mcp\RegisterOAuthClientRequest;
use Illuminate\Http\JsonResponse;
use Laravel\Passport\ClientRepository;

class RegisterOAuthClientController extends Controller
{
    public function __invoke(RegisterOAuthClientRequest $request, ClientRepository $clients): JsonResponse
    {
        $validated = $request->validated();
        $client = $clients->createAuthorizationCodeGrantClient(
            name: $validated['client_name'] ?? 'MCP client',
            redirectUris: $validated['redirect_uris'],
            confidential: false,
        );

        return response()->json([
            'client_id' => $client->getKey(),
            'client_id_issued_at' => $client->created_at?->getTimestamp() ?? now()->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => implode(' ', array_keys(config('shift_mcp.scopes'))),
        ], 201);
    }
}
