<?php
/**
 * Plugin-led connect (1.1.0): the one-time state, the start URL, the return
 * address on the site's own origin and the error text. A crafted link with an
 * attacker's code must not connect anything without the state we issued.
 * Runs without Grav. Self-contained; exit 0 = pass, 1 = fail.
 *
 * Run: php tests/connect-handshake-test.php   (from the plugin root)
 */

require __DIR__ . '/../lib/ConnectHandshake.php';

use Grav\Plugin\SiteCheckAccessibility\ConnectHandshake;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s\n", $ok ? 'OK' : 'XX', $label);
}

$now = 1_800_000_000;
[$state, $record] = ConnectHandshake::issue($now);

check('state is 64 hex characters', (bool) preg_match('/^[0-9a-f]{64}$/', $state));
check('record holds only the hash', $record['hash'] === hash('sha256', $state) && strpos(json_encode($record), $state) === false);
check('record expires after 60 minutes', $record['expiresAt'] === $now + 3600);
check('the issued state matches', ConnectHandshake::matches($record, $state));
check('a foreign state does not', !ConnectHandshake::matches($record, str_repeat('a', 64)));
check('an empty state does not', !ConnectHandshake::matches($record, ''));
check('no record, no match', !ConnectHandshake::matches(null, $state));
check('fresh within the window', ConnectHandshake::isFresh($record, $now + 3599));
check('stale after it', !ConnectHandshake::isFresh($record, $now + 3601));

[$other] = ConnectHandshake::issue($now);
check('two states differ', $other !== $state);

$url = ConnectHandshake::buildConnectUrl('https://site-check.de/', 'grav', 'https://kunde.example', 'https://kunde.example/admin/plugins/site-check-accessibility', 'abc');
$parts = parse_url($url);
parse_str($parts['query'] ?? '', $query);
check('start URL goes to /verbinden', ($parts['host'] ?? '') === 'site-check.de' && ($parts['path'] ?? '') === '/verbinden');
check('start URL carries v, cms, site, back, state', ($query['v'] ?? '') === '2' && ($query['cms'] ?? '') === 'grav'
    && ($query['site'] ?? '') === 'https://kunde.example' && ($query['state'] ?? '') === 'abc'
    && ($query['back'] ?? '') === 'https://kunde.example/admin/plugins/site-check-accessibility');

check('back moves onto the site origin', ConnectHandshake::onSiteOrigin('https://www.kunde.example/', '/admin/plugins/site-check-accessibility')
    === 'https://www.kunde.example/admin/plugins/site-check-accessibility');
check('back keeps a custom port', ConnectHandshake::onSiteOrigin('http://localhost:8770', '/typo3/module/x')
    === 'http://localhost:8770/typo3/module/x');
check('back replaces a foreign backend host', ConnectHandshake::onSiteOrigin('https://kunde.example', 'https://backend.intern/typo3/module/x')
    === 'https://kunde.example/typo3/module/x');

check('presence names plugin, version and protocol', ConnectHandshake::presence('1.1.0') === ['plugin' => 'site-check-accessibility', 'version' => '1.1.0', 'connectFlow' => 2]);

check('string error kept', ConnectHandshake::errorMessage('Code abgelaufen', 'x') === 'Code abgelaufen');
check('object error uses its message', ConnectHandshake::errorMessage(['message' => 'Nicht erlaubt'], 'x') === 'Nicht erlaubt');
check('anything else falls back instead of "Array"', ConnectHandshake::errorMessage(['code' => 1], 'Fehlgeschlagen') === 'Fehlgeschlagen');

exit($failures === 0 ? 0 : 1);
