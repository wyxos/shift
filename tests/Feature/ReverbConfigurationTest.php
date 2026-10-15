<?php

test('the page exposes the serving application public Reverb configuration', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'production-public-key',
        'broadcasting.connections.reverb.secret' => 'private-server-secret',
        'broadcasting.connections.reverb.options.host' => 'portal.example.com',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
    ]);

    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('<meta name="reverb-key" content="production-public-key">', false)
        ->assertSee('<meta name="reverb-host" content="portal.example.com">', false)
        ->assertSee('<meta name="reverb-port" content="443">', false)
        ->assertSee('<meta name="reverb-scheme" content="https">', false)
        ->assertDontSee('private-server-secret');
});

test('the page omits Reverb configuration when broadcasting uses another driver', function () {
    config(['broadcasting.default' => 'log']);

    $this->get('/login')
        ->assertSuccessful()
        ->assertDontSee('name="reverb-key"', false);
});
