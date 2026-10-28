<?php

namespace App\Http\Controllers\Mcp;

use App\Mcp\OAuth\RefreshRecovery;
use Laravel\Passport\Http\Controllers\AccessTokenController as PassportAccessTokenController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AccessTokenController extends PassportAccessTokenController
{
    public function issueToken(ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        return app(RefreshRecovery::class)->respond(
            $psrRequest,
            fn (): Response => parent::issueToken($psrRequest, $psrResponse),
        );
    }
}
