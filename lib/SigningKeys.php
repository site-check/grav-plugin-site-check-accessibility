<?php

namespace Grav\Plugin\SiteCheckAccessibility;

/**
 * The Ed25519 keys a signed sync envelope may be signed with (1.1.0).
 *
 * The plugin trusts the current key and, during a rotation, the next one. A rotation
 * ships the new key in the NEXT slot first; once every site runs that release, the web
 * app switches its private key, and a later release moves the new key into the current
 * slot. Envelopes signed with either key verify in between.
 *
 * Keys are given as base64 SPKI DER (the format `npm run crypto:generate-signing-key`
 * prints). SPKI wraps the raw 32-byte key as a trailer after a fixed 12-byte
 * AlgorithmIdentifier prefix, so substr(-32) is the raw key.
 *
 * PURE & Grav-independent.
 */
final class SigningKeys
{
    private const RAW_KEY_BYTES = 32;
    private const SIGNATURE_BYTES = 64;
    private const KEY_ID_HEX_CHARS = 16;

    /**
     * The usable keys in the given order, as ['id' => key id, 'raw' => 32 raw bytes].
     * Empty, undecodable or too short entries and duplicates are skipped.
     *
     * @param string[] $spkiB64List
     * @return array<int, array{id: string, raw: string}>
     */
    public static function trusted(array $spkiB64List): array
    {
        $keys = [];
        $seen = [];
        foreach ($spkiB64List as $spkiB64) {
            $b64 = is_string($spkiB64) ? trim($spkiB64) : '';
            if ($b64 === '') {
                continue;
            }
            $der = base64_decode($b64, true);
            if (!is_string($der) || strlen($der) < self::RAW_KEY_BYTES) {
                continue;
            }
            $id = self::keyIdFromDer($der);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $keys[] = ['id' => $id, 'raw' => substr($der, -self::RAW_KEY_BYTES)];
        }
        return $keys;
    }

    /** Key id: the first 16 lowercase hex chars of sha256 over the SPKI DER bytes. */
    public static function keyIdFromDer(string $der): string
    {
        return substr(hash('sha256', $der), 0, self::KEY_ID_HEX_CHARS);
    }

    /**
     * @param array<int, array{id: string, raw: string}> $trustedKeys
     * @return string[]
     */
    public static function ids(array $trustedKeys): array
    {
        return array_map(static fn (array $key): string => $key['id'], $trustedKeys);
    }

    /**
     * The id of the first trusted key the signature verifies with, or null.
     *
     * @param array<int, array{id: string, raw: string}> $trustedKeys
     */
    public static function verifyingKeyId(string $payloadBytes, string $signature, array $trustedKeys): ?string
    {
        if (strlen($signature) !== self::SIGNATURE_BYTES || !function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        foreach ($trustedKeys as $key) {
            if (strlen($key['raw']) !== self::RAW_KEY_BYTES) {
                continue;
            }
            if (sodium_crypto_sign_verify_detached($signature, $payloadBytes, $key['raw'])) {
                return $key['id'];
            }
        }
        return null;
    }
}
