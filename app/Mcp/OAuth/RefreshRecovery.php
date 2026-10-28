<?php

namespace App\Mcp\OAuth;

use App\Models\McpRefreshRecovery;
use Closure;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptTrait;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class RefreshRecovery
{
    use CryptTrait;

    private const WINDOW_SECONDS = 60;

    /**
     * Retry delivery of an issued pair, never rotate a consumed token again.
     *
     * @param  Closure(): Response  $issue
     */
    public function respond(ServerRequestInterface $request, Closure $issue): Response
    {
        $body = $request->getParsedBody();
        if (! is_array($body)
            || ($body['grant_type'] ?? null) !== 'refresh_token'
            || ! is_string($body['client_id'] ?? null)
            || ! is_string($body['refresh_token'] ?? null)
            || isset($body['client_secret'])
            || $request->hasHeader('Authorization')) {
            return $issue();
        }

        $original = $this->decode($body['refresh_token']);
        if ($original === null || $original['client_id'] !== $body['client_id']) {
            return $issue();
        }

        ksort($body);
        $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));

        // A shared database lock serializes refreshes across PHP workers. Rotation
        // and the encrypted delivery receipt must commit or roll back together.
        return Passport::client()->getConnection()->transaction(function () use ($body, $original, $hash, $issue): Response {
            $client = Passport::client()->newQuery()->lockForUpdate()->find($body['client_id']);
            if (! $this->eligible($client)) {
                return $issue();
            }

            $token = Passport::refreshToken()->newQuery()->lockForUpdate()->find($original['refresh_token_id']);
            if ($token === null || $token->access_token_id !== $original['access_token_id']
                || $token->expires_at === null || $token->expires_at->lte(now())
                || $original['expire_time'] <= now()->getTimestamp()) {
                return $issue();
            }

            $recovery = McpRefreshRecovery::find($hash);
            if ($recovery !== null) {
                $response = $this->recover($recovery, $client);

                return $response ?? $issue();
            }

            $response = $issue();
            if (! $response->isSuccessful()) {
                return $response;
            }

            $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $replacement = $this->decode($payload['refresh_token']);
            if ($replacement === null) {
                throw new \RuntimeException('Unable to record the MCP refresh response.');
            }

            McpRefreshRecovery::create([
                'request_hash' => $hash,
                'access_token_id' => $replacement['access_token_id'],
                'refresh_token_id' => $replacement['refresh_token_id'],
                'payload' => $payload,
                'expires_at' => now()->addSeconds(self::WINDOW_SECONDS)->min(
                    Carbon::createFromTimestamp($original['expire_time']),
                )->min($token->expires_at),
            ]);

            return $response;
        });
    }

    private function eligible(?Client $client): bool
    {
        return $client !== null && ! $client->revoked && ! $client->confidential()
            && $client->hasGrantType('refresh_token');
    }

    private function recover(McpRefreshRecovery $recovery, Client $client): ?Response
    {
        if ($recovery->expires_at->lte(now())) {
            return null;
        }

        $access = Passport::token()->newQuery()->find($recovery->access_token_id);
        $refresh = Passport::refreshToken()->newQuery()->find($recovery->refresh_token_id);
        if ($access === null || $refresh === null
            || $access->client_id !== $client->getKey()
            || $refresh->access_token_id !== $access->getKey()
            || $access->revoked || $refresh->revoked
            || $access->expires_at === null || $refresh->expires_at === null
            || $access->expires_at->lte(now()) || $refresh->expires_at->lte(now())
            || collect($access->scopes)->contains(fn (string $scope): bool => ! $client->hasScope($scope) || ! Passport::hasScope($scope))) {
            return null;
        }

        $payload = $recovery->payload;
        $payload['expires_in'] = max(0, $access->expires_at->getTimestamp() - now()->getTimestamp());

        return response()->json($payload)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    /** @return array{client_id: string, refresh_token_id: string, access_token_id: string, expire_time: int}|null */
    private function decode(string $token): ?array
    {
        $this->setEncryptionKey(Passport::tokenEncryptionKey(app('encrypter')));
        try {
            $payload = json_decode($this->decrypt($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\InvalidArgumentException|\LogicException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ! is_string($payload['client_id'] ?? null)
            || ! is_string($payload['refresh_token_id'] ?? null)
            || ! is_string($payload['access_token_id'] ?? null)
            || ! is_int($payload['expire_time'] ?? null)) {
            return null;
        }

        return $payload;
    }
}
