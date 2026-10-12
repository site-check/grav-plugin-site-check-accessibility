<?php

namespace Grav\Plugin\SiteCheckAccessibility;

/**
 * The public base URL a reverse proxy announces in its X-Forwarded-* headers.
 *
 * Grav builds its own root URL from those headers only where
 * system.http_x_forwarded.* allows it, and Grav 2.0 switched all of those
 * switches off by default (1.7 has protocol, port and ip on). Behind a
 * TLS-terminating proxy a Grav 2 site therefore reports http:// (and a backend
 * port such as :8080) while visitors reach it via https://. The web app compares
 * that pluginBaseUrl with the website URL by origin and rejects the pairing.
 *
 * Diagnosis only: the plugin keeps sending Grav's own URL. The headers are
 * client-controlled unless the proxy overwrites them, which is why Grav 2 stopped
 * trusting them by default.
 *
 * PURE & Grav-independent: input = ($_SERVER, Grav's base URL) -> URL or null.
 */
final class ForwardedBaseUrl
{
    /** Dot-separated DNS labels (IPv4 included); IPv6 literals are not diagnosed. */
    private const HOSTNAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    private const PORT_PATTERN = '/^\d{1,5}$/';

    /**
     * The base URL the X-Forwarded-Proto/-Host/-Port headers describe, if it
     * differs from the one Grav reports. Null when the request carries none of
     * these headers, when a value is malformed, or when both URLs agree.
     *
     * Host and port fall back to the Host header the proxy passed on. The path
     * (Grav installed in a subfolder) is always Grav's own.
     *
     * @param array<string, mixed> $server $_SERVER of the current request
     */
    public static function differingFrom(array $server, string $gravBaseUrl): ?string
    {
        $grav = parse_url($gravBaseUrl);
        if (!is_array($grav) || !isset($grav['scheme'], $grav['host'])) {
            return null;
        }
        $gravScheme = strtolower($grav['scheme']);
        $gravHost = strtolower($grav['host']);
        $path = rtrim((string) ($grav['path'] ?? ''), '/');

        $proto = self::firstValue($server, 'HTTP_X_FORWARDED_PROTO');
        $forwardedHost = self::firstValue($server, 'HTTP_X_FORWARDED_HOST');
        $forwardedPort = self::firstValue($server, 'HTTP_X_FORWARDED_PORT');
        if ($proto === '' && $forwardedHost === '' && $forwardedPort === '') {
            return null;
        }

        $scheme = $proto !== '' ? $proto : $gravScheme;
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $authority = self::splitAuthority($forwardedHost !== '' ? $forwardedHost : self::firstValue($server, 'HTTP_HOST'));
        if ($authority === null) {
            return null;
        }
        [$host, $port] = $authority;
        if ($host === '') {
            $host = $gravHost;
        }
        if ($forwardedPort !== '') {
            if (!preg_match(self::PORT_PATTERN, $forwardedPort)) {
                return null;
            }
            $port = (int) $forwardedPort;
        }

        $forwarded = self::format($scheme, $host, $port, $path);
        $reported = self::format($gravScheme, $gravHost, isset($grav['port']) ? (int) $grav['port'] : null, $path);

        return $forwarded === $reported ? null : $forwarded;
    }

    /**
     * Host and port of a Host-style header value: ['', null] for an empty value,
     * null when it is malformed.
     *
     * @return array{0: string, 1: int|null}|null
     */
    private static function splitAuthority(string $value): ?array
    {
        if ($value === '') {
            return ['', null];
        }
        if (!preg_match('/^([^:]+)(?::(\d{1,5}))?$/', $value, $match) || !preg_match(self::HOSTNAME_PATTERN, $match[1])) {
            return null;
        }

        return [$match[1], isset($match[2]) ? (int) $match[2] : null];
    }

    /**
     * Lower-cased first entry of a comma-separated header: with chained proxies
     * the one facing the client comes first.
     *
     * @param array<string, mixed> $server
     */
    private static function firstValue(array $server, string $key): string
    {
        $value = $server[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return strtolower(trim(explode(',', $value)[0]));
    }

    /** Leaves out the scheme's default port, as a URL origin does. */
    private static function format(string $scheme, string $host, ?int $port, string $path): string
    {
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $portSuffix = ($port !== null && $port !== $defaultPort) ? ':' . $port : '';

        return $scheme . '://' . $host . $portSuffix . $path;
    }
}
