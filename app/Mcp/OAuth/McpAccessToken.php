<?php

namespace App\Mcp\OAuth;

use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;
use RuntimeException;

class McpAccessToken extends AccessToken
{
    private CryptKeyInterface $mcpPrivateKey;

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->mcpPrivateKey = $privateKey;
    }

    public function toString(): string
    {
        $privateKey = $this->mcpPrivateKey->getKeyContents();

        if ($privateKey === '') {
            throw new RuntimeException('The OAuth private key is empty.');
        }

        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($privateKey, $this->mcpPrivateKey->getPassPhrase() ?? ''),
            InMemory::plainText('unused', 'unused'),
        );
        $now = new DateTimeImmutable;
        $subject = $this->getUserIdentifier() ?? $this->getClient()->getIdentifier();
        $scopes = array_map(
            fn ($scope): string => $scope->getIdentifier(),
            $this->getScopes(),
        );

        return $configuration->builder()
            ->issuedBy((string) config('shift_mcp.issuer'))
            ->permittedFor(
                $this->getClient()->getIdentifier(),
                (string) config('shift_mcp.resource'),
            )
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($subject)
            ->withClaim('scopes', $scopes)
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }
}
