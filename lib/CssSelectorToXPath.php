<?php
namespace Grav\Plugin\SiteCheckAccessibility;

/**
 * Focused CSS-selector -> XPath converter for the server-side mutation engine.
 *
 * Supports exactly the selector grammar the scanner emits (browser-dom-helpers.ts):
 *   - type/tag ("div", "*")
 *   - .class (one or more)
 *   - #id
 *   - [attr] / [attr="value"] / [attr='value']
 *   - :nth-of-type(N)
 *   - combinators ">" (child) and " " (descendant)
 * Any other pseudo (::before, :hover, :nth-child, …) is stripped from the segment
 * rather than failing — matching the client-side relax behaviour.
 *
 * We deliberately do NOT vendor a full CSS engine (e.g. symfony/css-selector): the
 * emitted grammar is small and bounded, and a focused converter keeps :nth-of-type
 * semantics (type-position, not child-position) exactly right.
 *
 * Returns null when a selector cannot be represented, so callers can fall back.
 */
final class CssSelectorToXPath
{
    /** Convert a full CSS selector into an XPath expression, or null if unsupported. */
    public static function convert(string $selector): ?string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return null;
        }

        $segments = self::splitIntoSegments($selector);
        if ($segments === null || count($segments) === 0) {
            return null;
        }

        $xpath = '';
        foreach ($segments as $i => $seg) {
            $axis = ($i === 0)
                ? '//'                                    // match anywhere (querySelectorAll semantics)
                : ($seg['combinator'] === '>' ? '/' : '//');
            $step = self::convertSimpleSelector($seg['simple']);
            if ($step === null) {
                return null;
            }
            $xpath .= $axis . $step;
        }

        return $xpath;
    }

    /**
     * Split "a > b c" into ordered segments each carrying the combinator that
     * PRECEDES it. First segment's combinator is '' (anchor).
     *
     * @return array<int,array{combinator:string,simple:string}>|null
     */
    private static function splitIntoSegments(string $selector): ?array
    {
        // Normalise whitespace around child combinators so we can tokenise on runs
        // of whitespace and explicit '>'.
        $normalised = preg_replace('/\s*>\s*/', ' > ', $selector);
        if (!is_string($normalised)) {
            return null;
        }
        $tokens = preg_split('/\s+/', trim($normalised));
        if ($tokens === false) {
            return null;
        }

        $segments = [];
        $pendingCombinator = '';
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($token === '>') {
                $pendingCombinator = '>';
                continue;
            }
            $segments[] = [
                'combinator' => $segments === [] ? '' : ($pendingCombinator !== '' ? $pendingCombinator : ' '),
                'simple' => $token,
            ];
            $pendingCombinator = '';
        }

        return $segments;
    }

    /** Convert one simple selector (no combinators) into an XPath step. */
    private static function convertSimpleSelector(string $simple): ?string
    {
        $tag = '*';
        $predicates = [];
        $nthOfType = null;

        $offset = 0;
        $len = strlen($simple);

        // Optional leading type selector.
        if (preg_match('/^(\*|[a-zA-Z][\w-]*)/', $simple, $m)) {
            $tag = $m[1];
            $offset = strlen($m[1]);
        }

        while ($offset < $len) {
            $rest = substr($simple, $offset);

            // .class
            if (preg_match('/^\.([\w-]+)/', $rest, $m)) {
                $predicates[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . $m[1] . " ')";
                $offset += strlen($m[0]);
                continue;
            }
            // #id
            if (preg_match('/^#([\w-]+)/', $rest, $m)) {
                $predicates[] = '@id=' . self::xpathLiteral($m[1]);
                $offset += strlen($m[0]);
                continue;
            }
            // [attr] or [attr="value"] / [attr='value'] / [attr=value]
            if (preg_match('/^\[\s*([\w-]+)\s*(?:([~|^$*]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]]*?))\s*)?\]/', $rest, $m)) {
                $attr = $m[1];
                $hasValue = isset($m[2]) && $m[2] !== '';
                if (!$hasValue) {
                    $predicates[] = '@' . $attr;
                } else {
                    $value = $m[3] ?? '';
                    if (($m[4] ?? '') !== '') { $value = $m[4]; }
                    elseif (($m[5] ?? '') !== '') { $value = $m[5]; }
                    // Only exact match "=" is emitted by the scanner; treat others as exact too.
                    $predicates[] = '@' . $attr . '=' . self::xpathLiteral($value);
                }
                $offset += strlen($m[0]);
                continue;
            }
            // :nth-of-type(N)
            if (preg_match('/^:nth-of-type\(\s*(\d+)\s*\)/i', $rest, $m)) {
                $nthOfType = (int) $m[1];
                $offset += strlen($m[0]);
                continue;
            }
            // Any other pseudo (::before, :hover, :nth-child, :not(...)) -> strip it.
            if (preg_match('/^::?[a-zA-Z-]+(\([^)]*\))?/', $rest, $m)) {
                $offset += strlen($m[0]);
                continue;
            }

            // Unrecognised token -> cannot represent safely.
            return null;
        }

        $step = $tag;

        // :nth-of-type(N) == the Nth sibling of this element's type.
        // XPath: element with exactly (N-1) preceding siblings of the same type.
        if ($nthOfType !== null) {
            $typeName = ($tag === '*') ? '*' : $tag;
            $predicates[] = 'count(preceding-sibling::' . $typeName . ') = ' . ($nthOfType - 1);
        }

        foreach ($predicates as $p) {
            $step .= '[' . $p . ']';
        }

        return $step;
    }

    /** Produce a safe XPath string literal, handling embedded quotes via concat(). */
    private static function xpathLiteral(string $value): string
    {
        if (strpos($value, "'") === false) {
            return "'" . $value . "'";
        }
        if (strpos($value, '"') === false) {
            return '"' . $value . '"';
        }
        // Contains both quote types -> build concat('a', "'", 'b', ...).
        $parts = explode("'", $value);
        $pieces = [];
        foreach ($parts as $i => $part) {
            if ($part !== '') {
                $pieces[] = "'" . $part . "'";
            }
            if ($i < count($parts) - 1) {
                $pieces[] = '"\'"';
            }
        }
        if ($pieces === []) {
            return "''";
        }
        return 'concat(' . implode(', ', $pieces) . ')';
    }

    /**
     * Relax a selector to its last segment's tag + classes only (drop nth/id/pseudo),
     * mirroring the client-side relax fallback. Returns '' when relaxing is pointless.
     */
    public static function relax(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return '';
        }
        $parts = preg_split('/\s*>\s*|\s+/', $selector);
        if ($parts === false) {
            return '';
        }
        $last = '';
        for ($k = count($parts) - 1; $k >= 0; $k--) {
            if ($parts[$k] !== '') {
                $last = $parts[$k];
                break;
            }
        }
        if ($last === '') {
            return '';
        }
        $relaxed = preg_replace('/:nth-[a-z-]+\([^)]*\)/i', '', $last);
        $relaxed = preg_replace('/::?[a-z-]+(\([^)]*\))?/i', '', (string) $relaxed);
        $relaxed = preg_replace('/#[^.:\[]+/', '', (string) $relaxed);
        $relaxed = trim((string) $relaxed);
        if ($relaxed === '' || $relaxed === $selector) {
            return '';
        }
        return $relaxed;
    }
}
