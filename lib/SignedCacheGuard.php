<?php

namespace Grav\Plugin\SiteCheckAccessibility;

/**
 * Whether a verified, signed sync payload may become this site's cache (1.0.4).
 *
 * The signature proves the payload comes from the web app. It does not prove that the
 * payload is the current state, or that it is meant for this site. Both gaps were found
 * in the test lab (criterion 1.1.1, K10):
 *
 * - Stale state: an older, validly signed envelope replaced the current one. The push
 *   stored the unsigned issuedAt from the request body as lastIssuedAt, so afterwards
 *   the reader accepted the old payload as well. Rule: the signed issuedAt must not be
 *   older than the stored one (equal is a re-push and allowed).
 * - Foreign site: an envelope signed for another website was stored and delivered.
 *   Rule: the signed websiteId must be the connected one.
 *
 * PURE & Grav-independent: input = (payload, stored lastIssuedAt, connected websiteId)
 * -> null or the reason.
 */
final class SignedCacheGuard
{
    public const REASON_ISSUED_AT_MISSING = 'issued_at_missing';
    public const REASON_STALE = 'stale';
    public const REASON_WEBSITE_MISSING = 'website_missing';
    public const REASON_WEBSITE_MISMATCH = 'website_mismatch';

    /**
     * Null when the payload may be stored, else one of the REASON_* constants.
     *
     * A site paired before the connection state carried a websiteId cannot be checked;
     * it keeps working (the reader logs it), everything else fails closed.
     */
    public static function rejectReason(array $payload, ?string $lastIssuedAt, ?string $connectedWebsiteId): ?string
    {
        $issuedAt = self::instant($payload['issuedAt'] ?? null);
        if ($issuedAt === null) {
            return self::REASON_ISSUED_AT_MISSING;
        }
        $last = self::instant($lastIssuedAt);
        if ($last !== null && $issuedAt < $last) {
            return self::REASON_STALE;
        }

        $connected = is_string($connectedWebsiteId) ? trim($connectedWebsiteId) : '';
        if ($connected === '') {
            return null;
        }
        $signed = is_string($payload['websiteId'] ?? null) ? trim($payload['websiteId']) : '';
        if ($signed === '') {
            return self::REASON_WEBSITE_MISSING;
        }
        return $signed === $connected ? null : self::REASON_WEBSITE_MISMATCH;
    }

    /** The signed issuedAt as stored in cache-state.json, or '' when it is missing. */
    public static function signedIssuedAt(array $payload): string
    {
        return self::instant($payload['issuedAt'] ?? null) === null ? '' : trim((string) $payload['issuedAt']);
    }

    /** Microsecond instant of an ISO timestamp, or null; strtotime would drop the milliseconds. */
    private static function instant($value): ?float
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (float) (new \DateTimeImmutable(trim($value)))->format('U.u');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
