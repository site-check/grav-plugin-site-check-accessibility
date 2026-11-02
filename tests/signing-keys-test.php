<?php
/**
 * Behavioural tests for SigningKeys, the trusted sync signing keys (current + rotation
 * slot) and their ids (1.1.0). Self-contained; exit 0 = pass, 1 = fail.
 *
 * Run: php tests/signing-keys-test.php   (from the plugin root)
 */

require __DIR__ . '/../lib/SigningKeys.php';

use Grav\Plugin\SiteCheckAccessibility\SigningKeys;

if (!function_exists('sodium_crypto_sign_keypair')) {
    echo "sodium extension missing\n";
    exit(1);
}

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s\n", $ok ? 'OK' : 'XX', $label);
}

/** A fresh Ed25519 key pair as [secret key, base64 SPKI DER of the public key]. */
function keyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $spkiPrefix = hex2bin('302a300506032b6570032100');
    return [sodium_crypto_sign_secretkey($pair), base64_encode($spkiPrefix . sodium_crypto_sign_publickey($pair))];
}

/** The plugin's slots: current key first, then the rotation slot. */
function trustedFor(string $current, string $next = ''): array
{
    return SigningKeys::trusted([$current, $next]);
}

$payload = '{"issuedAt":"2026-10-10T10:00:00.000Z","websiteId":"site-a","overrides":[]}';
[$currentSecret, $currentSpki] = keyPair();
[$nextSecret, $nextSpki] = keyPair();
[$foreignSecret, $foreignSpki] = keyPair();
$signedByCurrent = sodium_crypto_sign_detached($payload, $currentSecret);
$signedByNext = sodium_crypto_sign_detached($payload, $nextSecret);
$signedByForeign = sodium_crypto_sign_detached($payload, $foreignSecret);

$currentId = SigningKeys::keyIdFromDer(base64_decode($currentSpki));
$nextId = SigningKeys::keyIdFromDer(base64_decode($nextSpki));

// 1. The current key.
check('current key verifies with an empty rotation slot', SigningKeys::verifyingKeyId($payload, $signedByCurrent, trustedFor($currentSpki)) === $currentId);
check('current key still verifies with a filled rotation slot', SigningKeys::verifyingKeyId($payload, $signedByCurrent, trustedFor($currentSpki, $nextSpki)) === $currentId);
check('a changed payload is rejected', SigningKeys::verifyingKeyId($payload . ' ', $signedByCurrent, trustedFor($currentSpki, $nextSpki)) === null);

// 2. A second key counts only when it sits in the rotation slot.
check('second key without rotation slot -> rejected', SigningKeys::verifyingKeyId($payload, $signedByNext, trustedFor($currentSpki)) === null);
check('second key in the rotation slot -> verifies', SigningKeys::verifyingKeyId($payload, $signedByNext, trustedFor($currentSpki, $nextSpki)) === $nextId);

// 3. A foreign key is never trusted.
check('foreign key -> rejected', SigningKeys::verifyingKeyId($payload, $signedByForeign, trustedFor($currentSpki, $nextSpki)) === null);
check('truncated signature -> rejected', SigningKeys::verifyingKeyId($payload, substr($signedByCurrent, 0, 63), trustedFor($currentSpki)) === null);
check('no trusted key -> rejected', SigningKeys::verifyingKeyId($payload, $signedByCurrent, []) === null);

// 4. The trusted list.
check('empty slots -> no trusted key', trustedFor('', '') === []);
check('invalid entries are skipped', SigningKeys::ids(trustedFor('not base64 !!', $nextSpki)) === [$nextId]);
check('too short entry is skipped', trustedFor(base64_encode('short')) === []);
check('ids keep the slot order', SigningKeys::ids(trustedFor($currentSpki, $nextSpki)) === [$currentId, $nextId]);
check('the same key in both slots counts once', SigningKeys::ids(trustedFor($currentSpki, $currentSpki)) === [$currentId]);
check('raw key is the 32-byte trailer', trustedFor($currentSpki)[0]['raw'] === substr(base64_decode($currentSpki), -32));

// 5. Key ids: 16 lowercase hex chars, stable, distinct.
check('key id is 16 lowercase hex chars', preg_match('/^[0-9a-f]{16}$/', $currentId) === 1);
check('key id is the sha256 prefix of the SPKI DER', $currentId === substr(hash('sha256', base64_decode($currentSpki)), 0, 16));
check('key id is stable', SigningKeys::keyIdFromDer(base64_decode($currentSpki)) === $currentId && trustedFor(' ' . $currentSpki . ' ')[0]['id'] === $currentId);
check('different keys have different ids', $currentId !== $nextId);
// The key the plugin ships (read from the plugin, since release scripts replace it).
$pluginSource = (string) file_get_contents(__DIR__ . '/../site-check-accessibility.php');
$shippedSpki = preg_match("/SYNC_PUBLIC_KEY_B64 = '([^']*)'/", $pluginSource, $match) === 1 ? $match[1] : '';
check('shipped key yields a usable id', preg_match('/^[0-9a-f]{16}$/', SigningKeys::ids(trustedFor($shippedSpki))[0] ?? '') === 1);
check('shipped rotation slot is empty or usable', preg_match("/SYNC_PUBLIC_KEY_NEXT_B64 = '([^']*)'/", $pluginSource, $match) === 1
    && ($match[1] === '' || count(trustedFor($match[1])) === 1));

if ($failures > 0) {
    printf("\n%d check(s) failed\n", $failures);
    exit(1);
}
echo "\nall checks passed\n";
exit(0);
