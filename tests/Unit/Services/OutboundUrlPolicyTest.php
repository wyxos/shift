<?php

use App\Services\OutboundUrlPolicy;

test('normalizes only safe http and https base urls', function () {
    $policy = new OutboundUrlPolicy(fn (): array => ['93.184.216.34']);

    expect($policy->normalize(' HTTPS://Example.COM/path/ '))->toBe('https://example.com/path')
        ->and($policy->normalize('ftp://example.com'))->toBeNull()
        ->and($policy->normalize('https://user:password@example.com'))->toBeNull()
        ->and($policy->normalize('https://example.com:0'))->toBeNull()
        ->and($policy->normalizeBaseUrl('https://example.com?next=/admin'))->toBeNull()
        ->and($policy->normalizeBaseUrl('https://example.com/#fragment'))->toBeNull();
});

test('local portals allow local development callbacks without tls verification', function () {
    $policy = new OutboundUrlPolicy(fn (): array => ['127.0.0.1']);
    $destination = $policy->approveRequest('http://client-app.test:8080');
    $options = $policy->requestOptions($destination);

    expect($destination['url'])->toBe('http://client-app.test:8080')
        ->and($destination['addresses'])->toBe(['127.0.0.1'])
        ->and($options['verify'] ?? null)->toBeFalse()
        ->and($options['allow_redirects'] ?? null)->toBeFalse();
});

test('hosted production rejects insecure and private outbound destinations', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $policy = new OutboundUrlPolicy(
        fn (string $host): array => $host === 'private.example'
            ? ['10.0.0.8']
            : ['93.184.216.34'],
    );

    expect(fn () => $policy->approveRequest('http://public.example'))
        ->toThrow(\InvalidArgumentException::class, 'HTTPS')
        ->and(fn () => $policy->approveRequest('https://127.0.0.1'))
        ->toThrow(\InvalidArgumentException::class, 'Private and reserved')
        ->and(fn () => $policy->approveRequest('https://private.example'))
        ->toThrow(\InvalidArgumentException::class, 'Private and reserved');
});

test('hosted production rejects every non global address returned by dns', function (string $address) {
    $this->app->detectEnvironment(fn (): string => 'production');
    $policy = new OutboundUrlPolicy(fn (): array => [$address]);

    expect(fn () => $policy->approveRequest('https://callback.example'))
        ->toThrow(\InvalidArgumentException::class, 'Private and reserved');
})->with([
    'carrier grade nat' => '100.64.0.1',
    'protocol assignment' => '192.0.0.1',
    'benchmarking' => '198.18.0.1',
    'documentation ipv4' => '203.0.113.1',
    'documentation ipv6' => '2001:db8::1',
    'ipv4 mapped ipv6' => '::ffff:127.0.0.1',
]);

test('hosted production rejects mixed public and private dns answers', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $policy = new OutboundUrlPolicy(fn (): array => ['93.184.216.34', '10.0.0.8']);

    expect(fn () => $policy->approveRequest('https://callback.example'))
        ->toThrow(\InvalidArgumentException::class, 'Private and reserved');
});

test('hosted production accepts resolved public https callbacks and pins dns', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $policy = new OutboundUrlPolicy(fn (): array => ['93.184.216.34']);
    $destination = $policy->approveRequest('https://callback.example');
    $options = $policy->requestOptions($destination);

    expect($destination['url'])->toBe('https://callback.example')
        ->and($destination['addresses'])->toBe(['93.184.216.34'])
        ->and($options['verify'] ?? true)->toBeTrue()
        ->and($options['allow_redirects'] ?? null)->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE] ?? null)
        ->toBe(['callback.example:443:93.184.216.34']);
});

test('hosted production can record local app environments but cannot call them', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $policy = new OutboundUrlPolicy(fn (): array => ['127.0.0.1']);

    expect($policy->approveRegistration('http://client-app.test', 'local'))
        ->toBe('http://client-app.test')
        ->and(fn () => $policy->approveRegistration('http://client-app.test', 'production'))
        ->toThrow(\InvalidArgumentException::class, 'HTTPS')
        ->and(fn () => $policy->approveRequest('http://client-app.test'))
        ->toThrow(\InvalidArgumentException::class, 'HTTPS');
});
