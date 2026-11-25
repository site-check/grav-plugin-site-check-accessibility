<?php

namespace Grav\Plugin\SiteCheckAccessibility;

/**
 * Which attribute names the connector may write or remove (1.4.0, B8 of test lab 4.1).
 *
 * Until 1.3.0 every attribute op only had to name SOME attribute: a signed payload with
 * `attribute: "onclick"` would have been written. The payload comes from the web app and
 * is signed, so the risk was a compromised signing key, not a customer input. This
 * closes that gap: a name outside this list is never written or removed, whatever the
 * payload says, and the op is rejected with an explicit error instead of being dropped.
 *
 * The list is derived from what the web app produces (one entry per producer, see
 * testlabor/kriterien/4.1/b8-plugin-haertung.md). A new fix that writes another
 * attribute needs a plugin release first.
 *
 * - Written (and removable): the exact names in SET_NAMES and every `aria-*` name.
 * - Removed only: REMOVE_ONLY_NAMES. Removing `onfocus`/`onpaste` takes a script away,
 *   it never adds one; no `on*` name is ever written.
 * - Never: `on*` (event handlers), `style`, `src`, `href`, `class`, anything with
 *   whitespace, quotes or control characters.
 *
 * PURE & CMS-independent; identical in all four connectors (only namespace and header
 * differ). Plugin-owned markers (data-sc-acc-*, class/style of our own wrappers) are
 * set by the connector itself and never come from the payload, so they are not here.
 */
final class AttributeNamePolicy
{
    public const MODE_SET = 'set';
    public const MODE_REMOVE = 'remove';
    public const ERROR_PREFIX = 'attribute_not_allowed: ';

    public const SET_NAMES = [
        'alt',
        'autocomplete',
        'controls',
        'for',
        'form',
        'headers',
        'id',
        'lang',
        'list',
        'muted',
        'role',
        'scope',
        'tabindex',
        'title',
    ];
    public const SET_PREFIX = 'aria-';
    public const REMOVE_ONLY_NAMES = ['autoplay', 'inert', 'novalidate', 'onfocus', 'onpaste'];

    // Item types whose `attribute` field names the attribute that is written.
    private const ATTRIBUTE_WRITE_TYPES = ['alt_text_override', 'aria_label_set', 'attribute_override', 'attr_set'];
    private const ERROR_NAME_MAX_LENGTH = 64;

    public static function normalize(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * @param mixed $name
     */
    public static function isAllowed($name, string $mode): bool
    {
        if (!is_string($name)) {
            return false;
        }
        $normalized = self::normalize($name);
        if (preg_match('/^[a-z][a-z0-9-]*$/', $normalized) !== 1) {
            return false;
        }
        if ($mode === self::MODE_REMOVE && in_array($normalized, self::REMOVE_ONLY_NAMES, true)) {
            return true;
        }
        if (strpos($normalized, 'on') === 0) {
            return false;
        }
        if (in_array($normalized, self::SET_NAMES, true)) {
            return true;
        }
        return strlen($normalized) > strlen(self::SET_PREFIX) && strpos($normalized, self::SET_PREFIX) === 0;
    }

    /**
     * Null when the name may be used in this mode, else the error for the op result.
     *
     * @param mixed $name
     */
    public static function rejection($name, string $mode): ?string
    {
        if (self::isAllowed($name, $mode)) {
            return null;
        }
        if (!is_string($name)) {
            return self::ERROR_PREFIX . '(kein Name)';
        }
        $printable = preg_replace('/[^\x21-\x7e]/', '?', trim($name));
        return self::ERROR_PREFIX . substr(is_string($printable) ? $printable : '', 0, self::ERROR_NAME_MAX_LENGTH);
    }

    /**
     * First rejected entry of a name list, or null. Empty strings are skipped like the
     * list normaliser does; a list that is not an array carries no names.
     *
     * @param mixed $names
     */
    public static function listRejection($names, string $mode): ?string
    {
        if (!is_array($names)) {
            return null;
        }
        foreach ($names as $name) {
            if (is_string($name) && trim($name) === '') {
                continue;
            }
            $rejection = self::rejection($name, $mode);
            if ($rejection !== null) {
                return $rejection;
            }
        }
        return null;
    }

    /**
     * Null when every attribute name an override item writes or removes is allowed, else
     * the error for the first one that is not. Covers the four wire shapes that carry
     * names: `attribute` (written), `removeAttributes` and `attributes` of
     * attribute_remove (removed), `attributeOverrides` of tag_replace (written).
     */
    public static function itemRejection(array $item): ?string
    {
        $type = trim((string) ($item['type'] ?? ''));
        if ($type === '') {
            $type = 'alt_text_override';
        }

        if (in_array($type, self::ATTRIBUTE_WRITE_TYPES, true) && isset($item['attribute'])) {
            $attribute = $item['attribute'];
            if (!is_string($attribute) || trim($attribute) !== '') {
                $rejection = self::rejection($attribute, self::MODE_SET);
                if ($rejection !== null) {
                    return $rejection;
                }
            }
        }

        $rejection = self::listRejection($item['removeAttributes'] ?? null, self::MODE_REMOVE);
        if ($rejection !== null) {
            return $rejection;
        }

        if ($type === 'attribute_remove') {
            $rejection = self::listRejection($item['attributes'] ?? null, self::MODE_REMOVE);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        if ($type === 'tag_replace' && is_array($item['attributeOverrides'] ?? null)) {
            foreach ($item['attributeOverrides'] as $override) {
                if (!is_array($override)) {
                    continue;
                }
                $attribute = $override['attribute'] ?? '';
                if (is_string($attribute) && trim($attribute) === '') {
                    continue;
                }
                $rejection = self::rejection($attribute, self::MODE_SET);
                if ($rejection !== null) {
                    return $rejection;
                }
            }
        }

        return null;
    }

    /**
     * The rejected items of a delivered override list, for the sync answer and the log.
     *
     * @param mixed $items
     * @return array<int,array{index:int,type:string,selector:string,error:string}>
     */
    public static function rejectedItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $rejected = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $rejection = self::itemRejection($item);
            if ($rejection === null) {
                continue;
            }
            $rejected[] = [
                'index' => $index,
                'type' => (string) ($item['type'] ?? ''),
                'selector' => (string) ($item['selector'] ?? ''),
                'error' => $rejection,
            ];
        }
        return $rejected;
    }
}
