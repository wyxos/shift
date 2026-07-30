<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

class OutboundUrlPolicy
{
    private const DEVELOPMENT_ENVIRONMENTS = [
        'dev',
        'development',
        'local',
        'test',
        'testing',
    ];

    public function __construct(
        private readonly ?Closure $hostResolver = null,
    ) {}

    public function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $parts = parse_url($value);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $this->normalizeHost($parts['host'] ?? null);
        $port = $parts['port'] ?? null;

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $host === null
            || ($port !== null && ($port < 1 || $port > 65535))
        ) {
            return null;
        }

        $formattedPort = $port !== null ? ':'.$port : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        $formattedHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$host.']'
            : $host;

        return rtrim($scheme.'://'.$formattedHost.$formattedPort.$path.$query.$fragment, '/');
    }

    public function normalizeBaseUrl(?string $value): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        $parts = parse_url($normalized);

        if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $normalized;
    }

    public function approveRegistration(?string $value, string $environment): string
    {
        $destination = $this->inspect(
            $value,
            in_array(strtolower($environment), self::DEVELOPMENT_ENVIRONMENTS, true),
        );

        return $destination['url'];
    }

    /**
     * @return array{url: string, host: string, port: int, addresses: list<string>, disable_tls_verification: bool}
     */
    public function approveRequest(?string $value): array
    {
        return $this->inspect($value, false);
    }

    /**
     * @param  array{url: string, host: string, port: int, addresses: list<string>, disable_tls_verification: bool}  $destination
     * @return array<string|int, mixed>
     */
    public function requestOptions(array $destination): array
    {
        $options = [
            'allow_redirects' => false,
        ];

        if ($destination['disable_tls_verification']) {
            $options['verify'] = false;
        }

        if (filter_var($destination['host'], FILTER_VALIDATE_IP) !== false) {
            return $options;
        }

        if (! defined('CURLOPT_RESOLVE') || $destination['addresses'] === []) {
            throw new InvalidArgumentException('The callback destination could not be securely pinned.');
        }

        $address = $destination['addresses'][0];
        $formattedAddress = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$address.']'
            : $address;

        $options['curl'] = [
            CURLOPT_RESOLVE => [
                $destination['host'].':'.$destination['port'].':'.$formattedAddress,
            ],
        ];

        return $options;
    }

    public function responseWasRedirected(Response $response, string $expectedUrl): bool
    {
        $statuses = array_filter([
            $response->status(),
            $response->transferStats?->getResponse()?->getStatusCode(),
        ], fn (mixed $status): bool => is_int($status));

        if (collect($statuses)->contains(fn (int $status): bool => $status >= 300 && $status < 400)) {
            return true;
        }

        $effectiveUrl = $response->effectiveUri();

        if ($effectiveUrl === null) {
            return false;
        }

        return $this->endpointIdentity((string) $effectiveUrl) !== $this->endpointIdentity($expectedUrl);
    }

    /**
     * @return array{url: string, host: string, port: int, addresses: list<string>, disable_tls_verification: bool}
     */
    private function inspect(?string $value, bool $allowDevelopmentDestination): array
    {
        $url = $this->normalizeBaseUrl($value);

        if ($url === null) {
            throw new InvalidArgumentException('The callback URL must be a valid HTTP(S) base URL without credentials, a query, or a fragment.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $this->normalizeHost($parts['host'] ?? null);

        if ($host === null) {
            throw new InvalidArgumentException('The callback URL must include a valid host.');
        }

        $developmentAllowed = app()->environment(['local', 'testing']) || $allowDevelopmentDestination;

        if ($scheme !== 'https' && ! $developmentAllowed) {
            throw new InvalidArgumentException('The callback URL must use HTTPS outside local development.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolveHost($host);
        $developmentHost = $this->isDevelopmentHost($host)
            || collect($addresses)->contains(fn (string $address): bool => ! $this->isPublicAddress($address));

        if ($addresses === [] && ! ($developmentAllowed && $this->isDevelopmentHost($host))) {
            throw new InvalidArgumentException('The callback URL host could not be resolved.');
        }

        if ($developmentHost && ! $developmentAllowed) {
            throw new InvalidArgumentException('Private and reserved callback destinations are not allowed outside local development.');
        }

        if (! $developmentAllowed && collect($addresses)->contains(fn (string $address): bool => ! $this->isPublicAddress($address))) {
            throw new InvalidArgumentException('The callback URL must resolve only to public IP addresses.');
        }

        return [
            'url' => $url,
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'addresses' => $addresses,
            'disable_tls_verification' => app()->environment(['local', 'testing']) && $developmentHost,
        ];
    }

    private function normalizeHost(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $host = strtolower(rtrim(trim($value, '[]'), '.'));

        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost') {
            return $host;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            ? $host
            : null;
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if ($this->hostResolver instanceof Closure) {
            $addresses = ($this->hostResolver)($host);

            return $this->normalizeAddresses(is_array($addresses) ? $addresses : []);
        }

        $addresses = gethostbynamel($host) ?: [];

        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } catch (Throwable) {
            $records = false;
        }

        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        return $this->normalizeAddresses($addresses);
    }

    /**
     * @param  array<mixed>  $addresses
     * @return list<string>
     */
    private function normalizeAddresses(array $addresses): array
    {
        return collect($addresses)
            ->filter(fn (mixed $address): bool => is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false)
            ->map(fn (string $address): string => strtolower($address))
            ->unique()
            ->values()
            ->all();
    }

    private function isDevelopmentHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false && ! $this->isPublicAddress($host);
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE,
        ) !== false;
    }

    private function endpointIdentity(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return implode('|', [
            $scheme,
            $this->normalizeHost($parts['host'] ?? null) ?? '',
            (string) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            (string) ($parts['path'] ?? ''),
        ]);
    }
}
