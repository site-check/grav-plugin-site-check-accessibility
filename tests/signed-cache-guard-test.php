<?php
/**
 * Behavioural tests for SignedCacheGuard, the check a verified sync payload has to pass
 * before it becomes the site's cache (1.0.4). Self-contained; exit 0 = pass, 1 = fail.
 *
 * Run: php tests/signed-cache-guard-test.php   (from the plugin root)
 */

require __DIR__ . '/../lib/SignedCacheGuard.php';

use Grav\Plugin\SiteCheckAccessibility\SignedCacheGuard;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s\n", $ok ? 'OK' : 'XX', $label);
}
function payload(string $issuedAt, ?string $websiteId = 'site-a'): array
{
    $payload = ['issuedAt' => $issuedAt, 'overrides' => []];
    if ($websiteId !== null) {
        $payload['websiteId'] = $websiteId;
    }
    return $payload;
}

$stored = '2026-10-08T17:00:00.500Z';

// 1. The normal case: a newer payload for the connected site.
check('newer payload for the connected site -> stored', SignedCacheGuard::rejectReason(payload('2026-10-08T17:05:00.000Z'), $stored, 'site-a') === null);
check('first payload, nothing stored yet -> stored', SignedCacheGuard::rejectReason(payload('2026-10-08T17:05:00.000Z'), null, 'site-a') === null);
check('the same payload again (re-push) -> stored', SignedCacheGuard::rejectReason(payload($stored), $stored, 'site-a') === null);

// 2. S1: an older, validly signed envelope must not replace the current one.
check('older payload -> stale', SignedCacheGuard::rejectReason(payload('2026-10-08T16:00:00.000Z'), $stored, 'site-a') === SignedCacheGuard::REASON_STALE);
check('older by 200 ms in the same second -> stale', SignedCacheGuard::rejectReason(payload('2026-10-08T17:00:00.300Z'), $stored, 'site-a') === SignedCacheGuard::REASON_STALE);
check('payload without issuedAt -> rejected', SignedCacheGuard::rejectReason(['websiteId' => 'site-a'], $stored, 'site-a') === SignedCacheGuard::REASON_ISSUED_AT_MISSING);
check('unparseable issuedAt -> rejected', SignedCacheGuard::rejectReason(payload('gestern'), $stored, 'site-a') === SignedCacheGuard::REASON_ISSUED_AT_MISSING);
check('signedIssuedAt reads the signed value', SignedCacheGuard::signedIssuedAt(payload(' 2026-10-08T17:05:00.000Z ')) === '2026-10-08T17:05:00.000Z');

// 3. S2: an envelope signed for another website must not be stored.
check('foreign websiteId -> mismatch', SignedCacheGuard::rejectReason(payload('2026-10-08T17:05:00.000Z', 'site-b'), $stored, 'site-a') === SignedCacheGuard::REASON_WEBSITE_MISMATCH);
check('payload without websiteId on a connected site -> rejected', SignedCacheGuard::rejectReason(payload('2026-10-08T17:05:00.000Z', null), $stored, 'site-a') === SignedCacheGuard::REASON_WEBSITE_MISSING);
check('legacy connection without websiteId -> not checkable, stored', SignedCacheGuard::rejectReason(payload('2026-10-08T17:05:00.000Z', 'site-b'), $stored, '') === null);
check('stale wins over the site check', SignedCacheGuard::rejectReason(payload('2026-10-08T16:00:00.000Z', 'site-b'), $stored, 'site-a') === SignedCacheGuard::REASON_STALE);

if ($failures > 0) {
    printf("\n%d check(s) failed\n", $failures);
    exit(1);
}
echo "\nall checks passed\n";
exit(0);
