<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Disable Vite asset loading for all tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent Vite from attempting to locate the manifest during tests
        $this->withoutVite();

        // Tell Inertia where our page components live so page existence checks succeed
        config()->set('inertia.testing.page_paths', [resource_path('js/pages')]);
    }
}
