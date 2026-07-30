<?php

namespace Tests;

use App\Services\OutboundUrlPolicy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            OutboundUrlPolicy::class,
            fn (): OutboundUrlPolicy => new OutboundUrlPolicy(
                fn (): array => ['93.184.216.34'],
            ),
        );
    }
}
