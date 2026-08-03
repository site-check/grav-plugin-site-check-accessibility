<?php
namespace Grav\Plugin\SiteCheckAccessibility;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Masterminds\HTML5;

/**
 * Server-side HTML mutation engine.
 *
 * Applies resolved accessibility overrides directly to the server-rendered HTML so the
 * fix is present in the raw HTTP response — no JavaScript required. This replaces the
 * per-injector client-side <script> bootstraps for elements that exist in the pre-JS HTML.
 *
 * Design invariants:
 *   - PURE & Grav-independent: input = (html, operations) -> output = (html, report).
 *     Unit-testable without a running CMS.
 *   - Parse ONCE (only when operations exist), apply all ops, serialize ONCE via the
 *     HTML5-safe serializer (never libxml2 DOMDocument::saveHTML on the whole document).
 *   - Behaviour parity with injectSelectorOverridesBootstrap: same selector resolution,
 *     same relax fallback (exact -> single relaxed match), same imageKey guard for alt,
 *     same data-sc-acc-managed marking.
 *   - Elements NOT present in the server HTML (created later by the target site's own JS)
 *     are reported as unmatched so the caller can fall back (script assist / manual review).
 *
 * Operation shape (normalised — mirrors the plugin's $items for selector overrides):
 *   [
 *     'selector'         => string,                 // required
 *     'operation'        => 'attribute'|'text'|'remove',
 *     'attribute'        => ?string,                // for 'attribute' ops
 *     'value'            => string,                 // set value / text content
 *     'imageKey'         => ?string,                // alt guard: normalised image path
 *     'removeAttributes' => string[],               // attributes to delete after the write
 *   ]
 */
final class ServerHtmlMutator
{
    private const MANAGED_ATTR = 'data-sc-acc-managed';
    private const MANAGED_ATTR_NAME = 'data-sc-acc-managed-attribute';
    private const MANAGED_REMOVED = 'data-sc-acc-managed-removed';

    /**
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,report:array{operations:int,applied:int,unmatched:array<int,string>,unappliedOps:array<int,array<string,mixed>>}}
     */
    public function apply(string $html, array $operations, string $baseUrl = ''): array
    {
        $report = ['operations' => count($operations), 'applied' => 0, 'unmatched' => [], 'unappliedOps' => []];

        // Performance guard: never parse a page that has no overrides.
        if ($operations === [] || trim($html) === '') {
            return ['html' => $html, 'report' => $report];
        }
        // ext-dom guard (B5): without the DOM extension the HTML5 engine cannot
        // run. Report everything unapplied so the caller keeps its client fallback
        // instead of raising a fatal.
        if (!self::domAvailable()) {
            return ['html' => $html, 'report' => $this->allUnapplied($operations)];
        }

        try {
            $html5 = new HTML5(['disable_html_ns' => true]);
            $doc = $html5->loadHTML($html);
            $xpath = new DOMXPath($doc);

            foreach ($operations as $op) {
                $selector = trim((string) ($op['selector'] ?? ''));
                if ($selector === '') {
                    continue;
                }

                $nodes = $this->resolveNodes($xpath, $selector);
                if ($nodes === []) {
                    $report['unmatched'][] = $selector;
                    $report['unappliedOps'][] = $op;
                    continue;
                }

                $applied = false;
                foreach ($nodes as $node) {
                    if ($this->applyToNode($node, $op, $baseUrl)) {
                        $applied = true;
                    }
                }
                if ($applied) {
                    $report['applied']++;
                } else {
                    // Matched an element but the guard (e.g. imageKey) rejected every node.
                    // Left to the client fallback, which re-checks the same guard post-JS.
                    $report['unmatched'][] = $selector;
                    $report['unappliedOps'][] = $op;
                }
            }

            return ['html' => $html5->saveHTML($doc), 'report' => $report];
        } catch (\Throwable $e) {
            // B5 fail-open: a parser/serializer error must never corrupt the
            // delivered response — return the original HTML and fall back client-side.
            return ['html' => $html, 'report' => $this->allUnapplied($operations)];
        }
    }

    /**
     * Apply document-level overrides (title / html lang) directly in the server HTML.
     * These targets are page-global and always present in a normal document, so there is
     * no client fallback. Behaviour parity with the document-overrides bootstrap: set the
     * value only, no data-sc-acc marking.
     *
     * Op shape: ['documentTarget' => 'title'|'lang', 'value' => string]
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,report:array{operations:int,applied:int}}
     */
    public function applyDocumentOverrides(string $html, array $operations): array
    {
        $report = ['operations' => count($operations), 'applied' => 0];
        if ($operations === [] || trim($html) === '') {
            return ['html' => $html, 'report' => $report];
        }
        // ext-dom guard (B5): title/lang are page-global with no client fallback,
        // so on a DOM-less host we simply leave the original document untouched.
        if (!self::domAvailable()) {
            return ['html' => $html, 'report' => $report];
        }

        try {
            $html5 = new HTML5(['disable_html_ns' => true]);
            $doc = $html5->loadHTML($html);
            $xpath = new DOMXPath($doc);

            foreach ($operations as $op) {
                $target = (string) ($op['documentTarget'] ?? '');
                $value = (string) ($op['value'] ?? '');

                if ($target === 'lang') {
                    $htmlEl = $xpath->query('//html')->item(0);
                    if (!($htmlEl instanceof DOMElement)) {
                        $htmlEl = $doc->documentElement;
                    }
                    if ($htmlEl instanceof DOMElement) {
                        $htmlEl->setAttribute('lang', $value);
                        $report['applied']++;
                    }
                } elseif ($target === 'title') {
                    $titleEl = $xpath->query('//title')->item(0);
                    if ($titleEl instanceof DOMElement) {
                        $titleEl->textContent = $value;
                        $report['applied']++;
                    } else {
                        $head = $xpath->query('//head')->item(0);
                        if ($head instanceof DOMElement) {
                            $title = $doc->createElement('title');
                            $title->appendChild($doc->createTextNode($value));
                            $head->appendChild($title);
                            $report['applied']++;
                        }
                    }
                }
            }

            return ['html' => $html5->saveHTML($doc), 'report' => $report];
        } catch (\Throwable $e) {
            // B5 fail-open: never corrupt the response over a mutation error.
            return ['html' => $html, 'report' => ['operations' => count($operations), 'applied' => 0]];
        }
    }

    /**
     * Resource-scoped alt overrides (imageKey, no selector): set alt on every <img> in the
     * delivered HTML whose normalised src matches a recorded imageKey. This is the server-side
     * form of the alt-resource path — shared-asset / page-resource alt fixes carry an image
     * path instead of a CSS selector, so they cannot go through apply()/resolveNodes(). Matching
     * and normalisation reuse normalizePath() (identical to the client bootstrap and to the alt
     * guard in applyToNode). imageKeys that match no server-side <img> (lazy-loaded / injected
     * by the target site's own JS) are returned as unappliedOps so the caller keeps a client
     * fallback for them.
     *
     * Op shape: ['imageKey' => string, 'value' => string (alt), 'scope' => string (marker)]
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyResourceAltOverrides(string $html, array $operations, string $baseUrl = ''): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops) use ($baseUrl): array {
            // Normalised-path => op map. Later ops win, mirroring the client's last-write-wins
            // precedence (page-resource overrides are written after shared-component ones).
            $byPath = [];
            foreach ($ops as $op) {
                $imageKey = trim((string) ($op['imageKey'] ?? ''));
                if ($imageKey === '' || !array_key_exists('value', $op)) {
                    continue;
                }
                $byPath[$this->normalizePath($imageKey, $baseUrl)] = $op;
            }
            if ($byPath === []) {
                return ['applied' => 0, 'unapplied' => []];
            }

            $applied = 0;
            $matched = [];
            foreach ($xpath->query('//img') as $img) {
                if (!($img instanceof DOMElement)) {
                    continue;
                }
                $src = $img->getAttribute('src');
                if ($src === '') {
                    continue;
                }
                $key = $this->normalizePath($src, $baseUrl);
                if (!isset($byPath[$key])) {
                    continue;
                }
                $op = $byPath[$key];
                $img->setAttribute('alt', (string) ($op['value'] ?? ''));
                $img->setAttribute('data-sc-acc-alt-managed', '1');
                $scope = trim((string) ($op['scope'] ?? ''));
                if ($scope !== '') {
                    $img->setAttribute('data-sc-acc-alt-scope', $scope);
                }
                $matched[$key] = true;
                $applied++;
            }

            $unapplied = [];
            foreach ($byPath as $key => $op) {
                if (!isset($matched[$key])) {
                    $unapplied[] = $op;
                }
            }

            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    // ---------------------------------------------------------------------------------
    // Structural operations (Tier C). Unlike the selector-override family, the client
    // bootstraps for these use EXACT selectors (no relax) and idempotency markers, and
    // survive SPA re-renders via a MutationObserver. Server-side we apply what exists in
    // the delivered HTML and return the unapplied ops so the caller keeps the observer
    // script as a fallback for elements that only appear after the target site's own JS.
    // ---------------------------------------------------------------------------------

    /**
     * element_remove: delete every element matching the exact selector.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyElementRemove(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            foreach ($ops as $op) {
                $selector = trim((string) ($op['selector'] ?? ''));
                if ($selector === '') {
                    continue;
                }
                $nodes = $this->query($xpath, $selector);
                if ($nodes === []) {
                    $unapplied[] = $op;
                    continue;
                }
                foreach ($nodes as $node) {
                    if ($node->parentNode !== null) {
                        $node->parentNode->removeChild($node);
                        $applied++;
                    }
                }
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /**
     * tag_replace: promote the first eligible match to a new tag, carrying children,
     * attributes, attributeOverrides and inlineStyle. Two-phase (resolve against the
     * unmutated DOM, then mutate) so :nth-of-type selectors don't drift as siblings change.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyTagReplace(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            $claimed = [];
            $pending = [];

            // Phase 1: pick concrete targets against the unmutated DOM.
            foreach ($ops as $op) {
                $selector = trim((string) ($op['selector'] ?? ''));
                $fromTag = strtolower(trim((string) ($op['fromTag'] ?? '')));
                $toTag = strtolower(trim((string) ($op['toTag'] ?? '')));
                if ($selector === '' || $fromTag === '' || $toTag === '') {
                    continue;
                }
                $target = null;
                foreach ($this->query($xpath, $selector) as $candidate) {
                    if ($candidate->getAttribute('data-sc-acc-tag-replaced') === '1') {
                        continue;
                    }
                    if (strtolower($candidate->tagName) !== $fromTag) {
                        continue;
                    }
                    if ($this->alreadyClaimed($candidate, $claimed)) {
                        continue;
                    }
                    $target = $candidate;
                    break;
                }
                if ($target === null) {
                    $unapplied[] = $op;
                    continue;
                }
                $claimed[] = $target;
                $pending[] = ['op' => $op, 'target' => $target, 'toTag' => $toTag];
            }

            // Phase 2: mutate, immune to nth-of-type drift.
            foreach ($pending as $entry) {
                $target = $entry['target'];
                $op = $entry['op'];
                $replacement = $doc->createElement($entry['toTag']);
                foreach (iterator_to_array($target->attributes) as $attr) {
                    $replacement->setAttribute($attr->name, $attr->value);
                }
                while ($target->firstChild !== null) {
                    $replacement->appendChild($target->firstChild);
                }
                foreach ($this->normalizeAttributeOverrides($op['attributeOverrides'] ?? null) as $override) {
                    $replacement->setAttribute($override['attribute'], $override['value']);
                }
                $inlineStyle = trim((string) ($op['inlineStyle'] ?? ''));
                if ($inlineStyle !== '') {
                    $prev = $replacement->getAttribute('style');
                    $replacement->setAttribute('style', $prev !== '' ? rtrim($prev, '; ') . ';' . $inlineStyle : $inlineStyle);
                }
                $replacement->setAttribute('data-sc-acc-tag-replaced', '1');
                if ($target->parentNode !== null) {
                    $target->parentNode->replaceChild($replacement, $target);
                    $applied++;
                }
            }

            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /** Visually-hidden CSS injected once per page for sr_only inserts (parity with client). */
    private const SR_ONLY_CSS = '.sc-acc-sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}';

    /**
     * sr_only_text_insert: insert a visually-hidden <span class="sc-acc-sr-only"> carrying
     * screen-reader text at the anchor. De-dup by data-sc-acc-sr-only-id; ensure the
     * visually-hidden style exists once. Text is set as a text node, never HTML.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applySrOnlyInsert(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            foreach ($ops as $op) {
                $id = trim((string) ($op['id'] ?? ''));
                $selector = trim((string) ($op['selector'] ?? ''));
                $text = (string) ($op['text'] ?? '');
                if ($id === '' || $selector === '' || $text === '') {
                    continue;
                }
                if ($this->idExists($xpath, 'data-sc-acc-sr-only-id', $id)) {
                    continue; // already present
                }
                $nodes = $this->query($xpath, $selector);
                if ($nodes === []) {
                    $unapplied[] = $op;
                    continue;
                }
                $target = $nodes[0];
                $span = $doc->createElement('span');
                $span->setAttribute('class', 'sc-acc-sr-only');
                $span->setAttribute('data-sc-acc-sr-only-id', $id);
                $span->appendChild($doc->createTextNode($text));

                $rawPosition = (string) ($op['position'] ?? '');
                $position = in_array($rawPosition, ['before', 'after'], true) ? $rawPosition : 'first_child';
                if ($this->insertRelative($target, $span, $position)) {
                    $applied++;
                } else {
                    $unapplied[] = $op;
                }
            }

            if ($applied > 0 && $xpath->query('//style[@data-sc-acc-sr-only-style]')->item(0) === null) {
                $style = $doc->createElement('style');
                $style->setAttribute('data-sc-acc-sr-only-style', '1');
                $style->appendChild($doc->createTextNode(self::SR_ONLY_CSS));
                $head = $xpath->query('//head')->item(0);
                if ($head instanceof DOMElement) {
                    $head->appendChild($style);
                } elseif ($doc->documentElement !== null) {
                    $doc->documentElement->appendChild($style);
                }
            }

            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /**
     * sibling_text_insert: insert a text element (wrapTag, or <legend> for a first_child on
     * a fieldset, else <span>) with id=insertId and class sc-acc-inserted at the anchor.
     * De-dup by the insertId.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applySiblingInsert(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            foreach ($ops as $op) {
                $selector = trim((string) ($op['selector'] ?? ''));
                $text = (string) ($op['text'] ?? '');
                $insertId = trim((string) ($op['insertId'] ?? ''));
                if ($selector === '' || $text === '' || $insertId === '') {
                    continue;
                }
                if ($this->idExists($xpath, 'id', $insertId)) {
                    continue;
                }
                $nodes = $this->query($xpath, $selector);
                if ($nodes === []) {
                    $unapplied[] = $op;
                    continue;
                }
                $target = $nodes[0];

                $rawPosition = (string) ($op['position'] ?? '');
                $position = in_array($rawPosition, ['before', 'first_child'], true) ? $rawPosition : 'after';

                $wrapTag = strtolower(trim((string) ($op['wrapTag'] ?? '')));
                $allowed = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'legend'];
                if (!in_array($wrapTag, $allowed, true)) {
                    $wrapTag = ($position === 'first_child' && strtolower($target->tagName) === 'fieldset') ? 'legend' : 'span';
                }

                $node = $doc->createElement($wrapTag);
                $node->setAttribute('id', $insertId);
                $node->setAttribute('class', 'sc-acc-inserted');
                $node->appendChild($doc->createTextNode($text));

                if ($this->insertRelative($target, $node, $position)) {
                    $applied++;
                } else {
                    $unapplied[] = $op;
                }
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /**
     * label_append: append a short visible suffix (typically ' *') to the
     * <label> that belongs to the FIELD selector. Label resolution mirrors the
     * browser: an explicit label[for="<field id>"] first, then the nearest
     * wrapping <label> ancestor. Idempotent via data-sc-acc-required-marked on the
     * label. Safe no-op (reported unapplied) when the field OR its label is
     * absent — the paired programmatic aria-required write (a separate
     * attribute_override op) still lands, and no error is ever raised.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyLabelAppend(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            foreach ($ops as $op) {
                $selector = trim((string) ($op['selector'] ?? ''));
                $text = (string) ($op['text'] ?? '');
                if ($selector === '' || $text === '') {
                    continue;
                }
                $fields = $this->resolveNodes($xpath, $selector);
                if ($fields === []) {
                    $unapplied[] = $op;
                    continue;
                }
                $label = $this->resolveLabelForField($xpath, $fields[0]);
                if ($label === null) {
                    // No <label> to annotate — never an error; the aria-required
                    // companion op still marks the field programmatically.
                    $unapplied[] = $op;
                    continue;
                }
                if ($label->getAttribute('data-sc-acc-required-marked') === '1') {
                    // Already marked (idempotent re-apply) — count nothing, change nothing.
                    continue;
                }
                $marker = $doc->createElement('span');
                $marker->setAttribute('class', 'sc-acc-required-indicator');
                // Visual reinforcement only — aria-required conveys the state to AT,
                // so the star must not pollute the field's accessible name.
                $marker->setAttribute('aria-hidden', 'true');
                $marker->appendChild($doc->createTextNode($text));
                $label->appendChild($marker);
                $label->setAttribute('data-sc-acc-required-marked', '1');
                $applied++;
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /**
     * Resolve the <label> associated with a form field node:
     *   1. explicit association — <label for="<field id>">
     *   2. wrapping association — nearest ancestor <label>
     * Returns null when neither exists (aria-labelledby-only / no label). Never throws.
     */
    private function resolveLabelForField(DOMXPath $xpath, DOMElement $field): ?DOMElement
    {
        $id = trim((string) $field->getAttribute('id'));
        if ($id !== '') {
            $literal = strpos($id, "'") === false ? "'" . $id . "'" : '"' . $id . '"';
            $list = @$xpath->query('//label[@for=' . $literal . ']');
            if ($list !== false && $list->length > 0) {
                $node = $list->item(0);
                if ($node instanceof DOMElement) {
                    return $node;
                }
            }
        }
        $ancestor = $field->parentNode;
        while ($ancestor instanceof DOMElement) {
            if (strtolower($ancestor->tagName) === 'label') {
                return $ancestor;
            }
            $ancestor = $ancestor->parentNode;
        }
        // Sibling fallback: the common <div><label>X</label><input></div> pattern
        // where the label has no `for` and does not wrap the field. Only when the
        // field's parent holds EXACTLY ONE such unassociated, non-wrapping <label>
        // (else ambiguous → no-op, never mark the wrong label).
        $parent = $field->parentNode;
        if ($parent instanceof DOMElement) {
            $cand = null;
            $count = 0;
            foreach ($parent->childNodes as $child) {
                if (!($child instanceof DOMElement)) {
                    continue;
                }
                if (strtolower($child->tagName) !== 'label') {
                    continue;
                }
                if (trim((string) $child->getAttribute('for')) !== '') {
                    continue;
                }
                if ($child->getElementsByTagName('input')->length > 0
                    || $child->getElementsByTagName('select')->length > 0
                    || $child->getElementsByTagName('textarea')->length > 0) {
                    continue;
                }
                $cand = $child;
                $count++;
            }
            if ($count === 1 && $cand instanceof DOMElement) {
                return $cand;
            }
        }
        return null;
    }

    /**
     * element_insert: parse the (pre-validated) HTML snippet, stamp data-sc-acc-insert-id
     * and insert its first element child at the anchor. De-dup by the insert id.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyElementInsert(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            foreach ($ops as $op) {
                $id = trim((string) ($op['id'] ?? ''));
                $selector = trim((string) ($op['selector'] ?? ''));
                $snippet = trim((string) ($op['html'] ?? ''));
                if ($id === '' || $selector === '' || $snippet === '') {
                    continue;
                }
                if ($this->idExists($xpath, 'data-sc-acc-insert-id', $id)) {
                    continue;
                }
                $nodes = $this->query($xpath, $selector);
                if ($nodes === []) {
                    $unapplied[] = $op;
                    continue;
                }
                $newElement = $this->importFirstElement($doc, $snippet);
                if ($newElement === null) {
                    $unapplied[] = $op;
                    continue;
                }
                $newElement->setAttribute('data-sc-acc-insert-id', $id);
                $rawPosition = (string) ($op['position'] ?? '');
                $position = in_array($rawPosition, ['before', 'after', 'first_child', 'last_child'], true) ? $rawPosition : 'before';
                if ($this->insertRelative($nodes[0], $newElement, $position)) {
                    $applied++;
                } else {
                    $unapplied[] = $op;
                }
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /**
     * element_replace: replace the first matched element with the (pre-sanitized) HTML's
     * first element child, marked data-sc-acc-element-replaced.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyElementReplace(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            foreach ($ops as $op) {
                $selector = trim((string) ($op['selector'] ?? ''));
                $snippet = trim((string) ($op['html'] ?? ''));
                if ($selector === '' || $snippet === '') {
                    continue;
                }
                $nodes = $this->query($xpath, $selector);
                if ($nodes === []) {
                    $unapplied[] = $op;
                    continue;
                }
                $target = $nodes[0];
                if ($target->getAttribute('data-sc-acc-element-replaced') === '1') {
                    continue;
                }
                $newElement = $this->importFirstElement($doc, $snippet);
                if ($newElement === null) {
                    $unapplied[] = $op;
                    continue;
                }
                // Anchor guard (S6): a positional selector (e.g. p:nth-of-type(3))
                // can drift to a DIFFERENT element server-side because the delivered
                // DOM may differ from the DOM the scanner saw. applyTagReplace guards
                // this via fromTag; element_replace had no guard and blindly replaced
                // $nodes[0]. element_replace is currently used only to rebuild pseudo-
                // lists, where the replacement's item text is derived from the
                // target's own text — so require a text overlap between replacement
                // and target before replacing; otherwise skip (unapplied) instead of
                // overwriting an unrelated element. A replacement without comparable
                // text leaves the guard a no-op (future non-text uses are unaffected).
                if (!$this->elementReplaceAnchorMatches($newElement, $target)) {
                    $unapplied[] = $op;
                    continue;
                }
                $newElement->setAttribute('data-sc-acc-element-replaced', '1');
                if ($target->parentNode !== null) {
                    $target->parentNode->replaceChild($newElement, $target);
                    $applied++;
                } else {
                    $unapplied[] = $op;
                }
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /**
     * element_reorder (WCAG Phase 2 meaningful-sequence): reorder the direct
     * children of the parent to match the target order (orderedChildSelectors).
     * Moves EXISTING nodes only — never re-serialises or re-creates markup — and
     * stamps the parent with data-sc-acc-reordered (verifier marker + idempotence).
     * V0: the rendered appearance must not change (fixing DOM-vs-visual order).
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyElementReorder(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            foreach ($ops as $op) {
                $parentSelector = trim((string) ($op['parentSelector'] ?? $op['selector'] ?? ''));
                $childSelectors = is_array($op['orderedChildSelectors'] ?? null) ? $op['orderedChildSelectors'] : [];
                if ($parentSelector === '' || count($childSelectors) < 2) {
                    continue;
                }
                $parentNodes = $this->resolveNodes($xpath, $parentSelector);
                if ($parentNodes === []) {
                    $unapplied[] = $op;
                    continue;
                }
                $parent = $parentNodes[0];
                if ($parent->getAttribute('data-sc-acc-reordered') === '1') {
                    continue; // idempotent
                }
                // Resolve each child selector to a DISTINCT DIRECT child of this parent.
                $ordered = [];
                $ok = true;
                foreach ($childSelectors as $childSelector) {
                    $childSelector = trim((string) $childSelector);
                    if ($childSelector === '') {
                        $ok = false;
                        break;
                    }
                    $match = null;
                    foreach ($this->resolveNodes($xpath, $childSelector) as $node) {
                        if ($node->parentNode !== null && $node->parentNode->isSameNode($parent)) {
                            $match = $node;
                            break;
                        }
                    }
                    if ($match === null || $this->alreadyClaimed($match, $ordered)) {
                        $ok = false;
                        break;
                    }
                    $ordered[] = $match;
                }
                if (!$ok || count($ordered) < 2) {
                    $unapplied[] = $op;
                    continue;
                }
                // Anchor: the sibling right after the latest-in-DOM-order target
                // (a non-target node or null). Reinserting the targets in target
                // order before it keeps every non-target sibling in place.
                $lastTarget = null;
                for ($node = $parent->firstChild; $node !== null; $node = $node->nextSibling) {
                    foreach ($ordered as $t) {
                        if ($node->isSameNode($t)) {
                            $lastTarget = $node;
                            break;
                        }
                    }
                }
                $refAfter = $lastTarget !== null ? $lastTarget->nextSibling : null;
                foreach ($ordered as $t) {
                    if ($t->parentNode !== null) {
                        $t->parentNode->removeChild($t);
                    }
                }
                foreach ($ordered as $t) {
                    $parent->insertBefore($t, $refAfter);
                }
                $parent->setAttribute('data-sc-acc-reordered', '1');
                $applied++;
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /** Parse an HTML fragment and import its first element child into $doc (deep). */
    private function importFirstElement(DOMDocument $doc, string $snippet): ?DOMElement
    {
        $html5 = new HTML5(['disable_html_ns' => true]);
        $fragment = $html5->loadHTMLFragment($snippet);
        foreach ($fragment->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $imported = $doc->importNode($child, true);
                return $imported instanceof DOMElement ? $imported : null;
            }
        }
        return null;
    }

    /** Insert $node relative to $target. Returns false when the position is not possible. */
    private function insertRelative(DOMElement $target, DOMNode $node, string $position): bool
    {
        switch ($position) {
            case 'before':
                if ($target->parentNode !== null) {
                    $target->parentNode->insertBefore($node, $target);
                    return true;
                }
                return false;
            case 'after':
                if ($target->parentNode !== null) {
                    $target->parentNode->insertBefore($node, $target->nextSibling);
                    return true;
                }
                return false;
            case 'first_child':
                if ($target->firstChild !== null) {
                    $target->insertBefore($node, $target->firstChild);
                } else {
                    $target->appendChild($node);
                }
                return true;
            case 'last_child':
                $target->appendChild($node);
                return true;
            default:
                return false;
        }
    }

    /** True when an element with the given attribute == value already exists. */
    private function idExists(DOMXPath $xpath, string $attribute, string $value): bool
    {
        $literal = strpos($value, "'") === false ? "'" . $value . "'" : '"' . $value . '"';
        $list = @$xpath->query('//*[@' . $attribute . '=' . $literal . ']');
        return $list !== false && $list->length > 0;
    }

    /**
     * Parse once (only when ops exist), run the mutation callback, and serialize once —
     * but return the ORIGINAL html untouched when the callback applied nothing, so a page
     * with no server-resolvable targets is never needlessly re-normalised.
     *
     * @param array<int,array<string,mixed>> $operations
     * @param callable(DOMDocument,DOMXPath,array):array{applied:int,unapplied:array} $fn
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    private function withDocument(string $html, array $operations, callable $fn): array
    {
        if ($operations === [] || trim($html) === '') {
            return ['html' => $html, 'unappliedOps' => []];
        }
        // ext-dom guard (B5): no DOM extension → report all ops unapplied so the
        // caller keeps its client fallback instead of raising a fatal.
        if (!self::domAvailable()) {
            return ['html' => $html, 'unappliedOps' => array_values($operations)];
        }
        try {
            $html5 = new HTML5(['disable_html_ns' => true]);
            $doc = $html5->loadHTML($html);
            $xpath = new DOMXPath($doc);
            $result = $fn($doc, $xpath, $operations);
            $applied = (int) ($result['applied'] ?? 0);
            $unapplied = array_values($result['unapplied'] ?? []);
            if ($applied === 0) {
                return ['html' => $html, 'unappliedOps' => $unapplied];
            }
            return ['html' => $html5->saveHTML($doc), 'unappliedOps' => $unapplied];
        } catch (\Throwable $e) {
            // B5 fail-open: a parser/serializer error must never corrupt the
            // response — deliver the original HTML and fall back client-side.
            return ['html' => $html, 'unappliedOps' => array_values($operations)];
        }
    }

    /** True when the DOM extension is present (HTML5 loader + DOMXPath both need ext-dom). */
    private static function domAvailable(): bool
    {
        return extension_loaded('dom');
    }

    /**
     * Build an apply()-shaped report marking every operation unapplied, so the
     * caller falls back entirely to its client-side bootstrap when the server-side
     * engine is unavailable (no ext-dom) or errored.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{operations:int,applied:int,unmatched:array<int,string>,unappliedOps:array<int,array<string,mixed>>}
     */
    private function allUnapplied(array $operations): array
    {
        $unmatched = [];
        foreach ($operations as $op) {
            $unmatched[] = (string) ($op['selector'] ?? '');
        }
        return [
            'operations' => count($operations),
            'applied' => 0,
            'unmatched' => $unmatched,
            'unappliedOps' => array_values($operations),
        ];
    }

    /** Reliable DOM-node identity check (=== on DOM wrappers is unreliable in PHP). */
    private function alreadyClaimed(DOMElement $node, array $claimed): bool
    {
        foreach ($claimed as $other) {
            if ($node->isSameNode($other)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $value
     * @return array<int,array{attribute:string,value:string}>
     */
    private function normalizeAttributeOverrides($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $attribute = trim((string) ($entry['attribute'] ?? ''));
            if ($attribute === '') {
                continue;
            }
            $out[] = ['attribute' => $attribute, 'value' => (string) ($entry['value'] ?? '')];
        }
        return $out;
    }

    /**
     * Resolve target nodes: exact selector first; on miss, the relaxed last-segment
     * selector but ONLY when it yields exactly one element (never overwrite the wrong node).
     *
     * @return array<int,DOMElement>
     */
    private function resolveNodes(DOMXPath $xpath, string $selector): array
    {
        $exact = $this->query($xpath, $selector);
        if (count($exact) > 0) {
            return $exact;
        }
        $relaxed = CssSelectorToXPath::relax($selector);
        if ($relaxed !== '') {
            $relaxedNodes = $this->query($xpath, $relaxed);
            if (count($relaxedNodes) === 1) {
                return $relaxedNodes;
            }
        }
        return [];
    }

    /** @return array<int,DOMElement> */
    private function query(DOMXPath $xpath, string $selector): array
    {
        $expr = CssSelectorToXPath::convert($selector);
        if ($expr === null) {
            return [];
        }
        $list = @$xpath->query($expr);
        if ($list === false) {
            return [];
        }
        $out = [];
        foreach ($list as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * S6 anchor guard for element_replace. Confirms the resolved target actually
     * corresponds to the replacement's content before overwriting it, so a drifted
     * positional selector cannot destroy an unrelated element. Returns true (allow)
     * when the replacement carries no comparable text or the target is empty — the
     * guard only ever BLOCKS a positive mismatch, never a missing signal.
     */
    private function elementReplaceAnchorMatches(DOMElement $newElement, DOMElement $target): bool
    {
        // Scope: only pseudo-list rebuilds (<ul>/<ol>) are anchor-checked — that is
        // the sole element_replace the webapp emits, and its <li> text is derived
        // from the target's own text. Any other replacement keeps the general
        // "replace $nodes[0]" contract untouched.
        $tag = strtolower($newElement->tagName);
        if ($tag !== 'ul' && $tag !== 'ol') {
            return true;
        }
        $targetText = $this->anchorNormalize((string) $target->textContent);
        if ($targetText === '') {
            return true;
        }
        $replacementText = $this->anchorNormalize((string) $newElement->textContent);
        if (mb_strlen($replacementText) < 3) {
            return true;
        }
        // The list's concatenated item text should appear in the target's text.
        if (mb_strpos($targetText, $replacementText) !== false) {
            return true;
        }
        // Fallback: any single item (>=3 chars) present in the target is enough —
        // tolerates minor scan/serve text drift.
        foreach ($newElement->getElementsByTagName('li') as $li) {
            $item = $this->anchorNormalize((string) $li->textContent);
            if (mb_strlen($item) >= 3 && mb_strpos($targetText, $item) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Lowercase, letters/digits only — robust to list-marker / whitespace drift. */
    private function anchorNormalize(string $text): string
    {
        $lower = mb_strtolower($text);
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', '', $lower);
        return $stripped === null ? '' : $stripped;
    }

    /**
     * Apply a single operation to one element. Returns false when a guard skips it.
     *
     * @param array<string,mixed> $op
     */
    private function applyToNode(DOMElement $node, array $op, string $baseUrl): bool
    {
        $operation = (string) ($op['operation'] ?? 'attribute');
        $attribute = isset($op['attribute']) ? (string) $op['attribute'] : '';
        $value = (string) ($op['value'] ?? '');
        $imageKey = isset($op['imageKey']) && $op['imageKey'] !== null ? (string) $op['imageKey'] : '';
        $removeAttributes = $this->normalizeAttributeList($op['removeAttributes'] ?? null);

        // alt guard: only touch the image whose src matches the recorded imageKey.
        if ($attribute === 'alt' && $imageKey !== '' && !$this->imageMatches($node, $imageKey, $baseUrl)) {
            return false;
        }

        if ($operation === 'text') {
            $node->textContent = $value;
        } elseif ($operation !== 'remove' && $attribute !== '') {
            $node->setAttribute($attribute, $value);
        }

        foreach ($removeAttributes as $name) {
            if ($node->hasAttribute($name)) {
                $node->removeAttribute($name);
            }
        }

        // Marking parity with the client bootstrap.
        $node->setAttribute(self::MANAGED_ATTR, '1');
        if ($attribute !== '') {
            $node->setAttribute(self::MANAGED_ATTR_NAME, $attribute);
        }
        if ($removeAttributes !== []) {
            $node->setAttribute(self::MANAGED_REMOVED, implode(' ', $removeAttributes));
        }

        return true;
    }

    private function imageMatches(DOMElement $node, string $imageKey, string $baseUrl): bool
    {
        $src = $node->getAttribute('src');
        if ($src === '') {
            return false;
        }
        return $this->normalizePath($src, $baseUrl) === $this->normalizePath($imageKey, $baseUrl);
    }

    /** Mirror the client normalizePath: resolve to a path, percent-decode, collapse slashes. */
    private function normalizePath(string $value, string $baseUrl): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Strip scheme+host if absolute; keep path only.
        $path = $value;
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $parsed = parse_url($value);
            $path = $parsed['path'] ?? '';
        } elseif ($value[0] !== '/') {
            // Relative path: join against the base URL's directory when available.
            $basePath = $baseUrl !== '' ? (parse_url($baseUrl, PHP_URL_PATH) ?: '') : '';
            $baseDir = $basePath !== '' ? rtrim(preg_replace('#/[^/]*$#', '/', $basePath) ?? '', '') : '/';
            $path = ($baseDir === '' ? '/' : $baseDir) . $value;
        } else {
            $qpos = strpos($path, '?');
            if ($qpos !== false) {
                $path = substr($path, 0, $qpos);
            }
        }
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $path = substr($path, 0, $qpos);
        }
        $hpos = strpos($path, '#');
        if ($hpos !== false) {
            $path = substr($path, 0, $hpos);
        }
        $decoded = rawurldecode($path);
        return preg_replace('#/+#', '/', $decoded) ?? $decoded;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeAttributeList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
