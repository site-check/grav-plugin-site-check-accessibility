<?php

namespace Grav\Plugin\SiteCheckAccessibility;

/**
 * Plugin-led connect (protocol v2, since 1.1.0; docs/connector-pairing.md in
 * the webapp repository).
 *
 * „Mit site-check verbinden“ issues a one-time state and sends the browser to
 * site-check.de/verbinden. site-check sends it back with a pairing code and the
 * state; only if the state is the one we issued (and still fresh) is the code
 * exchanged. Without that check a crafted link carrying an attacker's code
 * would connect the site to the attacker's account the moment an admin opens it.
 *
 * Pure functions; storage of the record is up to the caller. Same logic as
 * the TYPO3 extension's Classes/Support/ConnectHandshake.php.
 */
final class ConnectHandshake
{
    // 60 minutes: a customer who registers or logs in on site-check.de on the way
    // (onboarding, payment) must still find the state valid when sent back.
    public const TTL_SECONDS = 3600;
    public const PROTOCOL_VERSION = 2;

    /**
     * @return array{0: string, 1: array{hash: string, expiresAt: int}} [state, record to store]
     */
    public static function issue(int $now): array
    {
        $state = bin2hex(random_bytes(32));
        return [$state, ['hash' => hash('sha256', $state), 'expiresAt' => $now + self::TTL_SECONDS]];
    }

    /** @param mixed $record the stored record, as read back */
    public static function matches($record, string $state): bool
    {
        if (!is_array($record) || !isset($record['hash']) || !is_string($record['hash']) || $state === '') {
            return false;
        }
        return hash_equals($record['hash'], hash('sha256', $state));
    }

    /** @param mixed $record */
    public static function isFresh($record, int $now): bool
    {
        return is_array($record) && (int) ($record['expiresAt'] ?? 0) >= $now;
    }

    public static function buildConnectUrl(string $webappBaseUrl, string $cms, string $site, string $back, string $state): string
    {
        $query = http_build_query([
            'v' => (string) self::PROTOCOL_VERSION,
            'cms' => $cms,
            'site' => $site,
            'back' => $back,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
        return rtrim($webappBaseUrl, '/') . '/verbinden?' . $query;
    }

    /**
     * Same origin as `$siteBase`, path and query from `$relativeUri`. The
     * webapp only returns to the site's own origin, while a backend can be
     * reached under any of the instance's domains.
     */
    public static function onSiteOrigin(string $siteBase, string $relativeUri): string
    {
        $parts = parse_url($siteBase);
        if (!is_array($parts) || empty($parts['host'])) {
            return $relativeUri;
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = parse_url($relativeUri, PHP_URL_PATH) ?: '/';
        $query = parse_url($relativeUri, PHP_URL_QUERY);
        return $origin . $path . ($query ? '?' . $query : '');
    }

    /** @return array{plugin: string, version: string, connectFlow: int} */
    public static function presence(string $version): array
    {
        return ['plugin' => 'site-check-accessibility', 'version' => $version, 'connectFlow' => self::PROTOCOL_VERSION];
    }

    /** The webapp answers `error` as a string or as `{message}`; never print "Array". */
    public static function errorMessage($error, string $fallback): string
    {
        if (is_string($error) && $error !== '') {
            return $error;
        }
        if (is_array($error) && isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
            return $error['message'];
        }
        return $fallback;
    }
}
