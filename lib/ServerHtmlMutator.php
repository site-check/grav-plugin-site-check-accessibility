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
    // element_wrap (FA-138): fixed by the wire contract, never taken from the payload.
    private const WRAP_TAG = 'div';
    private const WRAP_ROLE = 'list';
    private const WRAP_ITEM_TAG = 'span';
    private const WRAP_ITEM_ROLE = 'listitem';
    private const WRAP_STYLE = 'display:contents';
    private const WRAP_MARKER = 'data-sc-acc-wrapped';

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
                    $titleEl = $xpath->query('//head/title')->item(0);
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
     * FA-143: two-phase like applyTagReplace (resolve every op against the
     * unmutated DOM, then remove), so a positional selector (p:nth-of-type(n))
     * does not drift onto the next sibling because an earlier op of the same
     * batch already removed an element in front of it.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyElementRemove(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $claimed = [];
            $resolved = $this->resolveElementRemoveTargets($xpath, $ops, $claimed);
            return ['applied' => $this->removeElements($resolved['targets']), 'unapplied' => $resolved['unapplied']];
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
            // One op at a time against the current DOM (behaviour unchanged for this
            // single-family entry point). The plugins' page pass uses
            // applyElementReplaceAndRemove, which resolves every target up front (FA-143).
            foreach ($ops as $op) {
                $claimed = [];
                $resolved = $this->resolveElementReplaceTargets($doc, $xpath, [$op], $claimed);
                foreach ($resolved['unapplied'] as $unappliedOp) {
                    $unapplied[] = $unappliedOp;
                }
                foreach ($resolved['pending'] as $entry) {
                    if ($this->commitElementReplace($entry)) {
                        $applied++;
                    } else {
                        $unapplied[] = $entry['op'];
                    }
                }
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /**
     * element_replace + element_remove of one page in a single pass (FA-143).
     *
     * The product rebuilds a pseudo-list as one element_replace (the list takes the
     * place of the LAST row) plus one element_remove per earlier row, all addressed
     * by positional selectors (`… > p:nth-of-type(n)`) taken from the DOM the
     * scanner saw. Applied one family after the other on separate documents, the
     * removes resolved against a document the replace had already changed: one <p>
     * fewer, so every nth-of-type pointed one paragraph further, up to a customer
     * paragraph after the list. Here every target of both lists is resolved against
     * the unmutated document first (replace before remove, `claimed` across both),
     * and only then are the replacements and removals carried out.
     *
     * Fail-open like withDocument: on any error (or without ext-dom) the original
     * HTML comes back and every op of both lists is reported unapplied.
     *
     * @param array<int,array<string,mixed>> $replaceOps
     * @param array<int,array<string,mixed>> $removeOps
     * @return array{html:string,unappliedReplaceOps:array<int,array<string,mixed>>,unappliedRemoveOps:array<int,array<string,mixed>>}
     */
    public function applyElementReplaceAndRemove(string $html, array $replaceOps, array $removeOps): array
    {
        // withDocument handles one op list: tag each op with its family so every
        // path (applied, fail-open, no ext-dom) hands it back to the right list.
        $tagged = [];
        foreach ($replaceOps as $op) {
            $tagged[] = ['family' => 'replace', 'op' => $op];
        }
        foreach ($removeOps as $op) {
            $tagged[] = ['family' => 'remove', 'op' => $op];
        }
        $result = $this->withDocument($html, $tagged, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $claimed = [];
            // Phase 1: resolve both families against the unmutated DOM. Replace first,
            // so a node addressed by both lists keeps its content (replaced, not removed).
            $replace = $this->resolveElementReplaceTargets($doc, $xpath, $this->opsOfFamily($ops, 'replace'), $claimed);
            $remove = $this->resolveElementRemoveTargets($xpath, $this->opsOfFamily($ops, 'remove'), $claimed);
            $unapplied = [];
            foreach ($replace['unapplied'] as $op) {
                $unapplied[] = ['family' => 'replace', 'op' => $op];
            }
            foreach ($remove['unapplied'] as $op) {
                $unapplied[] = ['family' => 'remove', 'op' => $op];
            }

            // Phase 2: mutate, immune to nth-of-type drift.
            $applied = 0;
            foreach ($replace['pending'] as $entry) {
                if ($this->commitElementReplace($entry)) {
                    $applied++;
                } else {
                    $unapplied[] = ['family' => 'replace', 'op' => $entry['op']];
                }
            }
            $applied += $this->removeElements($remove['targets']);
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
        return [
            'html' => $result['html'],
            'unappliedReplaceOps' => $this->opsOfFamily($result['unappliedOps'], 'replace'),
            'unappliedRemoveOps' => $this->opsOfFamily($result['unappliedOps'], 'remove'),
        ];
    }

    /**
     * Phase 1 of element_replace: pick each op's target without mutating the DOM.
     * First match only; an already-replaced target (idempotent re-run) or one an
     * earlier op of the batch claimed is skipped; the snippet must parse to an
     * element and pass the anchor guard, otherwise the op stays unapplied.
     *
     * @param array<int,array<string,mixed>> $ops
     * @param array<int,DOMElement> $claimed
     * @return array{pending:array<int,array{op:array<string,mixed>,target:DOMElement,replacement:DOMElement}>,unapplied:array<int,array<string,mixed>>}
     */
    private function resolveElementReplaceTargets(DOMDocument $doc, DOMXPath $xpath, array $ops, array &$claimed): array
    {
        $pending = [];
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
            if ($this->alreadyClaimed($target, $claimed)) {
                continue;
            }
            $newElement = $this->importFirstElement($doc, $snippet);
            if ($newElement === null) {
                $unapplied[] = $op;
                continue;
            }
            // Anchor guard: a positional selector (e.g. p:nth-of-type(3)) can drift
            // to a DIFFERENT element server-side because the delivered DOM may differ
            // from the DOM the scanner saw. applyTagReplace guards this via fromTag.
            // element_replace is currently used only to rebuild pseudo-lists, where
            // the replacement's item text is derived from the target's own text, so
            // require a text overlap between replacement and target before replacing;
            // otherwise skip (unapplied) instead of overwriting an unrelated element.
            // A replacement without comparable text leaves the guard a no-op.
            if (!$this->elementReplaceAnchorMatches($newElement, $target)) {
                $unapplied[] = $op;
                continue;
            }
            $claimed[] = $target;
            $pending[] = ['op' => $op, 'target' => $target, 'replacement' => $newElement];
        }
        return ['pending' => $pending, 'unapplied' => $unapplied];
    }

    /**
     * Phase 2 of element_replace: swap the resolved target for its marked replacement.
     *
     * @param array{op:array<string,mixed>,target:DOMElement,replacement:DOMElement} $entry
     */
    private function commitElementReplace(array $entry): bool
    {
        $target = $entry['target'];
        $replacement = $entry['replacement'];
        if ($target->parentNode === null) {
            return false;
        }
        $replacement->setAttribute('data-sc-acc-element-replaced', '1');
        $target->parentNode->replaceChild($replacement, $target);
        return true;
    }

    /**
     * Phase 1 of element_remove: collect every match of every op without mutating
     * the DOM. A node already claimed (by an element_replace of the same batch or an
     * earlier remove op) is left to that op, and an op whose matches are all claimed
     * counts as handled rather than unapplied: the client fallback would resolve its
     * positional selector against the already-mutated document and hit the next
     * sibling (FA-143).
     *
     * @param array<int,array<string,mixed>> $ops
     * @param array<int,DOMElement> $claimed
     * @return array{targets:array<int,DOMElement>,unapplied:array<int,array<string,mixed>>}
     */
    private function resolveElementRemoveTargets(DOMXPath $xpath, array $ops, array &$claimed): array
    {
        $targets = [];
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
                if ($this->alreadyClaimed($node, $claimed)) {
                    continue;
                }
                $claimed[] = $node;
                $targets[] = $node;
            }
        }
        return ['targets' => $targets, 'unapplied' => $unapplied];
    }

    /**
     * Phase 2 of element_remove: detach the resolved targets.
     *
     * @param array<int,DOMElement> $targets
     * @return int How many elements were removed.
     */
    private function removeElements(array $targets): int
    {
        $removed = 0;
        foreach ($targets as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * @param array<int,array{family:string,op:array<string,mixed>}> $tagged
     * @return array<int,array<string,mixed>>
     */
    private function opsOfFamily(array $tagged, string $family): array
    {
        $ops = [];
        foreach ($tagged as $entry) {
            if (is_array($entry) && ($entry['family'] ?? '') === $family) {
                $ops[] = $entry['op'];
            }
        }
        return $ops;
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

    /**
     * element_wrap (FA-138, 1.3.0): an invisible list around entries that share one
     * parent (menu links, li directly in a nav). Wire contract:
     * src/lib/fix-engine/element-wrap-contract.ts and _shared-decisions.md §1.1.
     *
     * Two-phase like applyElementReplaceAndRemove: every itemSelector of every op is
     * resolved against the UNMUTATED document first (the selectors are positional,
     * `nav > a:nth-of-type(2)`, and stop matching once the entries move), then the
     * wraps are committed. An op applies only when each selector has exactly one
     * match, all entries share one parent and appear in the given order; otherwise
     * it is reported unapplied and nothing moves.
     *
     * Idempotent: an op whose first entry already sits in a marked wrapper, or whose
     * parent already holds a marked list wrapper, is handled without a change, so a
     * second pass over wrapped HTML never nests a second wrapper. An op overlapping
     * the range of an earlier op of the batch is dropped (not unapplied): the client
     * fallback would resolve it against the already-wrapped document.
     *
     * Op shape: ['selector' => first entry, 'itemSelectors' => string[] (>= 2),
     *            'itemWrapTag' => 'span'|null]. Tag, roles and style are fixed here.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyElementWrap(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $claimed = [];
            $pending = [];
            $unapplied = [];
            foreach ($ops as $op) {
                $resolved = $this->resolveElementWrap($xpath, $op, $claimed);
                if ($resolved === 'unapplied') {
                    $unapplied[] = $op;
                } elseif (is_array($resolved)) {
                    $pending[] = $resolved;
                }
            }
            $applied = 0;
            foreach ($pending as $entry) {
                $this->commitElementWrap($doc, $entry);
                $applied++;
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /**
     * Phase 1 of element_wrap: resolve one op without touching the DOM.
     * Returns the entries plus the node range to move, 'unapplied' when the op does
     * not fit the document, or 'handled' when there is nothing (left) to do.
     *
     * @param array<string,mixed> $op
     * @param array<int,DOMNode> $claimed Nodes inside the range of an earlier op of the batch.
     * @return array{entries:array<int,DOMElement>,range:array<int,DOMNode>,itemWrapTag:?string}|string
     */
    private function resolveElementWrap(DOMXPath $xpath, array $op, array &$claimed)
    {
        $selectors = [];
        foreach (is_array($op['itemSelectors'] ?? null) ? $op['itemSelectors'] : [] as $selector) {
            $selector = trim((string) $selector);
            if ($selector !== '') {
                $selectors[] = $selector;
            }
        }
        $itemWrapTag = $op['itemWrapTag'] ?? null;
        if ($itemWrapTag !== null && $itemWrapTag !== self::WRAP_ITEM_TAG) {
            return 'handled';
        }
        if (count($selectors) < 2) {
            return 'handled';
        }

        $entries = [];
        foreach ($selectors as $index => $selector) {
            $nodes = $this->query($xpath, $selector);
            if ($index === 0 && count($nodes) === 1 && $this->isInsideElementWrap($nodes[0])) {
                return 'handled';
            }
            if (count($nodes) !== 1 || $this->alreadyClaimed($nodes[0], $entries)) {
                return 'unapplied';
            }
            $entries[] = $nodes[0];
        }

        $parent = $entries[0]->parentNode;
        if ($parent === null) {
            return 'unapplied';
        }
        foreach ($entries as $entry) {
            if ($entry->parentNode === null || !$entry->parentNode->isSameNode($parent)) {
                return 'unapplied';
            }
        }
        if ($this->hasElementWrapChild($parent)) {
            return 'handled';
        }

        // The range from the first to the last entry, in document order; the entries
        // must come up in the order the scanner reported them.
        $range = [];
        $next = 0;
        $last = $entries[count($entries) - 1];
        for ($node = $entries[0]; $node !== null; $node = $node->nextSibling) {
            $range[] = $node;
            if ($next < count($entries) && $node->isSameNode($entries[$next])) {
                $next++;
            }
            if ($node->isSameNode($last)) {
                break;
            }
        }
        if ($next !== count($entries)) {
            return 'unapplied';
        }

        foreach ($range as $node) {
            foreach ($claimed as $other) {
                if ($node->isSameNode($other)) {
                    return 'handled';
                }
            }
        }
        foreach ($range as $node) {
            $claimed[] = $node;
        }

        return ['entries' => $entries, 'range' => $range, 'itemWrapTag' => $itemWrapTag];
    }

    /**
     * Phase 2 of element_wrap: insert the list wrapper before the first entry and move
     * the range into it. With an item tag every entry gets its own listitem wrapper,
     * and the nodes after an entry (separators, whitespace, <br>) follow it into that
     * wrapper; without one the entry itself takes the listitem role.
     *
     * @param array{entries:array<int,DOMElement>,range:array<int,DOMNode>,itemWrapTag:?string} $entry
     */
    private function commitElementWrap(DOMDocument $doc, array $entry): void
    {
        $entries = $entry['entries'];
        $first = $entries[0];
        $wrapper = $this->createElementWrapNode($doc, self::WRAP_TAG, self::WRAP_ROLE);
        $first->parentNode->insertBefore($wrapper, $first);

        $current = null;
        foreach ($entry['range'] as $node) {
            $isEntry = $node instanceof DOMElement && $this->alreadyClaimed($node, $entries);
            if ($entry['itemWrapTag'] === null) {
                if ($isEntry) {
                    $node->setAttribute('role', self::WRAP_ITEM_ROLE);
                }
                $wrapper->appendChild($node);
                continue;
            }
            if ($isEntry) {
                $current = $this->createElementWrapNode($doc, self::WRAP_ITEM_TAG, self::WRAP_ITEM_ROLE);
                $wrapper->appendChild($current);
            }
            $current->appendChild($node);
        }
    }

    private function createElementWrapNode(DOMDocument $doc, string $tag, string $role): DOMElement
    {
        $node = $doc->createElement($tag);
        $node->setAttribute('role', $role);
        $node->setAttribute('style', self::WRAP_STYLE);
        $node->setAttribute(self::WRAP_MARKER, '1');
        return $node;
    }

    /** The entry already sits in a marked wrapper (directly, or in a marked item wrapper). */
    private function isInsideElementWrap(DOMElement $node): bool
    {
        $parent = $node->parentNode;
        return $parent instanceof DOMElement && $parent->getAttribute(self::WRAP_MARKER) === '1';
    }

    /** The parent already holds a marked list wrapper as a direct child. */
    private function hasElementWrapChild(DOMNode $parent): bool
    {
        for ($child = $parent->firstChild; $child !== null; $child = $child->nextSibling) {
            if ($child instanceof DOMElement
                && $child->getAttribute(self::WRAP_MARKER) === '1'
                && $child->getAttribute('role') === self::WRAP_ROLE
            ) {
                return true;
            }
        }
        return false;
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

    /**
     * media_track: track-Elemente in ein Medienelement setzen und es für die
     * Player-Runtime markieren.
     *
     * Serverseitig, weil ein <track> im Markup stehen muss, wenn der Browser
     * das Medienelement aufbaut. Damit ist die Seite nach der Auslieferung auch
     * ohne unsere Runtime untertitelt: der Browser zeigt seine eigenen
     * Bedienelemente mit derselben Spur.
     *
     * Idempotent über data-sc-track-id am track-Element: ein zweiter Durchlauf
     * findet die Spur vor und legt sie nicht erneut an.
     *
     * @param array<int,array<string,mixed>> $operations
     * @return array{html:string,unappliedOps:array<int,array<string,mixed>>}
     */
    public function applyMediaTracks(string $html, array $operations): array
    {
        return $this->withDocument($html, $operations, function (DOMDocument $doc, DOMXPath $xpath, array $ops): array {
            $applied = 0;
            $unapplied = [];
            foreach ($ops as $op) {
                $selector = trim((string) ($op['selector'] ?? ''));
                $src = trim((string) ($op['src'] ?? ''));
                $kind = (string) ($op['trackKind'] ?? '');
                $id = trim((string) ($op['id'] ?? ''));
                if ($selector === '' || $src === '' || $id === '') {
                    continue;
                }
                if ($kind !== 'captions' && $kind !== 'descriptions') {
                    continue;
                }
                if ($this->idExists($xpath, 'data-sc-track-id', $id)) {
                    continue;
                }

                $nodes = $this->query($xpath, $selector);
                if ($nodes === []) {
                    $unapplied[] = $op;
                    continue;
                }
                $media = $nodes[0];
                $tagName = strtolower($media->nodeName);
                // Nur an echte Medienelemente. Ein track in einem div hat keine
                // Wirkung, und ein falscher Selektor soll auffallen statt
                // stillschweigend nichts zu tun.
                if ($tagName !== 'video' && $tagName !== 'audio') {
                    $unapplied[] = $op;
                    continue;
                }

                $track = $doc->createElement('track');
                $track->setAttribute('kind', $kind);
                $track->setAttribute('src', $src);
                $language = (string) ($op['language'] ?? 'de');
                $track->setAttribute('srclang', $language);
                $label = trim((string) ($op['label'] ?? ''));
                if ($label !== '') {
                    $track->setAttribute('label', $label);
                }
                // Untertitel als Voreinstellung an, Beschreibungen nicht: eine
                // Beschreibung, die ungefragt losspricht, überrascht jeden, der
                // sie nicht braucht. Der Schalter dafür ist im Player.
                if ($kind === 'captions' && !$this->hasDefaultTextTrack($media)) {
                    $track->setAttribute('default', 'default');
                }
                $track->setAttribute('data-sc-track-id', $id);

                // Ein track gehört vor die Kindelemente, die keine sind: nach
                // source-Elementen, aber vor dem Ausweichtext.
                $this->appendTrackNode($media, $track);
                // Marke für die Player-Runtime. Sie wertet nur Medien mit
                // diesem Attribut auf, damit sie fremde Player nicht anfasst.
                if ($media instanceof DOMElement && !$media->hasAttribute('data-sc-media')) {
                    $media->setAttribute('data-sc-media', '');
                }
                $applied++;
            }
            return ['applied' => $applied, 'unapplied' => $unapplied];
        });
    }

    /** Hat das Medium schon eine Untertitelspur mit default? */
    private function hasDefaultTextTrack(DOMNode $media): bool
    {
        foreach ($media->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if (strtolower($child->nodeName) !== 'track') {
                continue;
            }
            if ($child->hasAttribute('default')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hängt einen track hinter die vorhandenen source- und track-Elemente.
     *
     * Die Reihenfolge zählt: nach der HTML-Spezifikation stehen source-Elemente
     * vor track-Elementen, und was danach kommt, ist der Ausweichtext für
     * Browser ohne Medienunterstützung. Ein track dahinter wäre ungültig.
     */
    private function appendTrackNode(DOMNode $media, DOMElement $track): void
    {
        $anchor = null;
        foreach ($media->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if ($name === 'source' || $name === 'track') {
                $anchor = $child;
            }
        }

        if ($anchor !== null && $anchor->nextSibling !== null) {
            $media->insertBefore($track, $anchor->nextSibling);
            return;
        }
        if ($anchor !== null) {
            $media->appendChild($track);
            return;
        }
        // Kein source und kein track: an den Anfang, damit der Ausweichtext
        // dahinter bleibt.
        if ($media->firstChild !== null) {
            $media->insertBefore($track, $media->firstChild);
            return;
        }
        $media->appendChild($track);
    }
}
