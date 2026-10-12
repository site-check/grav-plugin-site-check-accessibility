<?php
/**
 * Behavioural tests for ForwardedBaseUrl, the diagnosis behind the pairing hint for Grav
 * sites behind a reverse proxy. Self-contained; exit 0 = pass, 1 = fail (CI-gate friendly).
 *
 * Run: php tests/forwarded-base-url-test.php   (from the plugin root)
 */

require __DIR__ . '/../lib/ForwardedBaseUrl.php';

use Grav\Plugin\SiteCheckAccessibility\ForwardedBaseUrl;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s\n", $ok ? 'OK' : 'XX', $label);
}
function differs(array $server, string $gravBaseUrl): ?string
{
    return ForwardedBaseUrl::differingFrom($server, $gravBaseUrl);
}

// 1. No proxy headers: nothing to diagnose, even if Host and Grav disagree.
check('no X-Forwarded-* headers -> null', differs(['HTTP_HOST' => 'example.com:8080'], 'http://example.com') === null);

// 2. Grav 2 default behind a TLS-terminating proxy: Grav ignores X-Forwarded-Proto.
check(
    'Grav 2 reports http, proxy says https -> https URL',
    differs(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'example.com'], 'http://example.com') === 'https://example.com'
);

// 3. Grav 1.7 default applies the header, so both agree.
check(
    'Grav already reports the forwarded https URL -> null',
    differs(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'example.com'], 'https://example.com') === null
);

// 4. Backend on a non-standard port, public site on 443 without X-Forwarded-Port.
check(
    'backend port :8080 reported, proxy on the default port -> URL without port',
    differs(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'example.com'], 'http://example.com:8080') === 'https://example.com'
);

// 5. X-Forwarded-Port overrides the port from the Host header.
check(
    'X-Forwarded-Port names the public port',
    differs(['HTTP_X_FORWARDED_PORT' => '8622', 'HTTP_HOST' => '127.0.0.1:8622'], 'http://127.0.0.1:8631') === 'http://127.0.0.1:8622'
);
check(
    'X-Forwarded-Port equal to the default port of the scheme is left out',
    differs(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443', 'HTTP_HOST' => 'example.com'], 'https://example.com') === null
);

// 6. X-Forwarded-Host with a port, comma lists from chained proxies: the first entry wins.
check(
    'first entry of chained proxy headers, host with port',
    differs(
        ['HTTP_X_FORWARDED_PROTO' => 'https, http', 'HTTP_X_FORWARDED_HOST' => 'www.example.com:8443, backend.internal', 'HTTP_HOST' => 'backend.internal'],
        'http://backend.internal'
    ) === 'https://www.example.com:8443'
);

// 7. Grav in a subfolder keeps its path.
check(
    'subfolder install keeps Grav path',
    differs(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => 'example.com'], 'http://example.com/grav') === 'https://example.com/grav'
);

// 8. Case does not matter.
check(
    'upper-case header values and host -> null',
    differs(['HTTP_X_FORWARDED_PROTO' => 'HTTPS', 'HTTP_HOST' => 'Example.COM'], 'https://example.com') === null
);

// 9. Malformed values are not echoed into the hint.
check('unknown scheme -> null', differs(['HTTP_X_FORWARDED_PROTO' => 'javascript', 'HTTP_HOST' => 'example.com'], 'http://example.com') === null);
check('markup in X-Forwarded-Host -> null', differs(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => '<img src=x>'], 'http://example.com') === null);
check('non-numeric X-Forwarded-Port -> null', differs(['HTTP_X_FORWARDED_PORT' => '80a', 'HTTP_HOST' => 'example.com'], 'http://example.com:8080') === null);
check('IPv6 literal is not diagnosed -> null', differs(['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_HOST' => '[::1]:8080'], 'http://[::1]:8080') === null);
check('non-string header value -> null', differs(['HTTP_X_FORWARDED_PROTO' => ['https']], 'http://example.com') === null);

// 10. No Host header at all: Grav's host is the fallback.
check(
    'missing Host header falls back to Grav host',
    differs(['HTTP_X_FORWARDED_PROTO' => 'https'], 'http://example.com') === 'https://example.com'
);

// 11. Grav's own URL unusable -> null.
check('empty Grav base URL -> null', differs(['HTTP_X_FORWARDED_PROTO' => 'https'], '') === null);

if ($failures === 0) {
    echo "PASS — ForwardedBaseUrl finds the public URL a proxy announces and only reports a difference.\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed.\n";
exit(1);
