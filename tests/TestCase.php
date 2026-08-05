<?php

namespace Tests;

use App\Services\OutboundUrlPolicy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static ?string $passportPrivateKey = null;

    private static ?string $passportPublicKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();

        $this->app->bind(
            OutboundUrlPolicy::class,
            fn (): OutboundUrlPolicy => new OutboundUrlPolicy(
                fn (): array => ['93.184.216.34'],
            ),
        );
    }

    private function configurePassportKeys(): void
    {
        if (self::$passportPrivateKey === null || self::$passportPublicKey === null) {
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);

            if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
                throw new \RuntimeException('Unable to generate Passport test keys.');
            }

            $details = openssl_pkey_get_details($key);

            if (! is_array($details) || ! is_string($details['key'] ?? null)) {
                throw new \RuntimeException('Unable to read the Passport test public key.');
            }

            self::$passportPrivateKey = $privateKey;
            self::$passportPublicKey = $details['key'];
        }

        config([
            'passport.private_key' => self::$passportPrivateKey,
            'passport.public_key' => self::$passportPublicKey,
        ]);
    }
}
