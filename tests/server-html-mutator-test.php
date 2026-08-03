<?php
/**
 * Behavioural tests for the server-side mutation engine (ServerHtmlMutator) and, through it,
 * the CSS-selector -> XPath converter. Self-contained; exit 0 = pass, 1 = fail (CI-gate friendly).
 *
 * Run: php tests/server-html-mutator-test.php   (from the plugin root)
 */

require __DIR__ . '/../vendor/html5/autoload.php';
require __DIR__ . '/../lib/CssSelectorToXPath.php';
require __DIR__ . '/../lib/ServerHtmlMutator.php';

use Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s\n", $ok ? 'OK' : 'XX', $label);
}
function contains(string $haystack, string $needle): bool
{
    return strpos($haystack, $needle) !== false;
}

$engine = new ServerHtmlMutator();

// 1. alt_text_override by selector on a server-rendered <img>.
$r = $engine->apply(
    '<html><body><figure><img src="/img/cat.png"></figure></body></html>',
    [['selector' => 'figure > img', 'operation' => 'attribute', 'attribute' => 'alt', 'value' => 'A cat']]
);
check('alt set on matched img', contains($r['html'], 'alt="A cat"'));
check('alt: managed marking present', contains($r['html'], 'data-sc-acc-managed="1"') && contains($r['html'], 'data-sc-acc-managed-attribute="alt"'));
check('alt: report applied=1 unmatched=0', $r['report']['applied'] === 1 && $r['report']['unmatched'] === []);

// 2. aria_label_set.
$r = $engine->apply(
    '<html><body><button class="close"></button></body></html>',
    [['selector' => 'button.close', 'operation' => 'attribute', 'attribute' => 'aria-label', 'value' => 'Schließen']]
);
check('aria-label set', contains($r['html'], 'aria-label="Schließen"'));

// 3. attribute_override (generic).
$r = $engine->apply(
    '<html><body><a class="x" href="#">l</a></body></html>',
    [['selector' => 'a.x', 'operation' => 'attribute', 'attribute' => 'href', 'value' => '/real']]
);
check('generic attribute overwritten', contains($r['html'], 'href="/real"'));

// 4. text_content_override.
$r = $engine->apply(
    '<html><body><h2 class="t">old</h2></body></html>',
    [['selector' => 'h2.t', 'operation' => 'text', 'value' => 'new']]
);
check('text content replaced', contains($r['html'], '>new</h2>') && !contains($r['html'], 'old'));

// 5. attribute_remove + set together (un-hide informative image).
$r = $engine->apply(
    '<html><body><img class="deco" src="/i.png" aria-hidden="true" role="presentation" alt=""></body></html>',
    [['selector' => 'img.deco', 'operation' => 'attribute', 'attribute' => 'alt', 'value' => 'Chart', 'removeAttributes' => ['aria-hidden', 'role']]]
);
check('un-hide: alt written', contains($r['html'], 'alt="Chart"'));
check('un-hide: aria-hidden removed', !contains($r['html'], 'aria-hidden="true"'));
check('un-hide: role removed', !contains($r['html'], 'role="presentation"'));
check('un-hide: removed-marking present', contains($r['html'], 'data-sc-acc-managed-removed="aria-hidden role"'));

// 6. :nth-of-type targets the correct sibling only.
$r = $engine->apply(
    '<html><body><ul><li>a</li><li>b</li><li>c</li></ul></body></html>',
    [['selector' => 'ul > li:nth-of-type(2)', 'operation' => 'attribute', 'attribute' => 'data-x', 'value' => 'Y']]
);
check('nth-of-type(2): only 2nd li marked', substr_count($r['html'], 'data-x="Y"') === 1 && contains($r['html'], 'data-sc-acc-managed-attribute="data-x">b</li>'));

// 7. relax fallback: drifted nth-of-type misses, unique relaxed match applies.
$r = $engine->apply(
    '<html><body><section><p class="lead">hi</p></section></body></html>',
    [['selector' => 'section > p.lead:nth-of-type(9)', 'operation' => 'attribute', 'attribute' => 'data-r', 'value' => '1']]
);
check('relax: unique last-segment match applies', contains($r['html'], 'data-r="1"'));

// 7b. relax fallback stays safe when ambiguous (>1 relaxed match -> unmatched, nothing changed).
$r = $engine->apply(
    '<html><body><p class="lead">a</p><p class="lead">b</p></body></html>',
    [['selector' => 'body > p.lead:nth-of-type(9)', 'operation' => 'attribute', 'attribute' => 'data-r', 'value' => '1']]
);
check('relax: ambiguous -> not applied, reported unmatched', !contains($r['html'], 'data-r="1"') && count($r['report']['unmatched']) === 1);

// 8. imageKey guard: only the image whose src matches gets alt.
$r = $engine->apply(
    '<html><body><img class="p" src="/a.png"><img class="p" src="/b.png"></body></html>',
    [['selector' => 'img.p', 'operation' => 'attribute', 'attribute' => 'alt', 'value' => 'B image', 'imageKey' => '/b.png']]
);
check('imageKey guard: only matching src gets alt', contains($r['html'], '<img class="p" src="/b.png" alt="B image"') && !contains($r['html'], '/a.png" alt='));

// 9. unmatched selector reported, html otherwise untouched.
$r = $engine->apply(
    '<html><body><div>x</div></body></html>',
    [['selector' => 'span.missing', 'operation' => 'attribute', 'attribute' => 'alt', 'value' => 'z']]
);
check('unmatched selector reported', $r['report']['applied'] === 0 && count($r['report']['unmatched']) === 1);
check('unmatched: full op carried for fallback', count($r['report']['unappliedOps']) === 1 && ($r['report']['unappliedOps'][0]['selector'] ?? '') === 'span.missing');

// 10. no operations -> html returned verbatim (no parse/serialize normalisation).
$src = '<html><body><img src=/x.png ></body></html>';
$r = $engine->apply($src, []);
check('no-ops: html returned verbatim', $r['html'] === $src);

// 11. HTML5-no-corruption during a real mutation elsewhere on the page.
$page = '<html><head><script>const t=`hi ${x} <b>keep</b> a<b`;if(a<b&&c>d){}</script></head>'
      . '<body><svg viewBox="0 0 10 10"><path d="M0 0"/></svg>'
      . '<img class="hero" src="/h.png"></body></html>';
$r = $engine->apply($page, [
    ['selector' => 'img.hero', 'operation' => 'attribute', 'attribute' => 'alt', 'value' => 'Hero'],
]);
check('corruption-safe: alt applied', contains($r['html'], 'alt="Hero"'));
check('corruption-safe: inline script body intact', contains($r['html'], '<b>keep</b>') && contains($r['html'], 'if(a<b&&c>d)'));
check('corruption-safe: SVG viewBox casing intact', contains($r['html'], 'viewBox='));

// 12. doctype survives round-trip.
$r = $engine->apply(
    "<!DOCTYPE html>\n<html><body><p class=\"t\">x</p></body></html>",
    [['selector' => 'p.t', 'operation' => 'text', 'value' => 'y']]
);
check('doctype preserved', stripos($r['html'], '<!DOCTYPE html>') !== false);

// 13. document_override: set existing <title>.
$r = $engine->applyDocumentOverrides(
    '<html><head><title>old</title></head><body>x</body></html>',
    [['documentTarget' => 'title', 'value' => 'New & Better']]
);
check('doc: existing title replaced', contains($r['html'], '<title>New &amp; Better</title>') && !contains($r['html'], '>old<'));

// 14. document_override: create <title> when missing (head present).
$r = $engine->applyDocumentOverrides(
    '<html><head><meta charset="utf-8"></head><body>x</body></html>',
    [['documentTarget' => 'title', 'value' => 'Made']]
);
check('doc: title created in head when missing', contains($r['html'], '<title>Made</title>'));

// 15. document_override: set lang on <html>.
$r = $engine->applyDocumentOverrides(
    '<html><head><title>t</title></head><body>x</body></html>',
    [['documentTarget' => 'lang', 'value' => 'de']]
);
check('doc: html lang set', contains($r['html'], '<html lang="de">') && $r['report']['applied'] === 1);

// 16. document_override: title + lang together, and no-ops verbatim.
$r = $engine->applyDocumentOverrides(
    '<html lang="en"><head><title>a</title></head><body>x</body></html>',
    [['documentTarget' => 'title', 'value' => 'b'], ['documentTarget' => 'lang', 'value' => 'fr']]
);
check('doc: both applied', contains($r['html'], '<title>b</title>') && contains($r['html'], 'lang="fr"') && $r['report']['applied'] === 2);
$src2 = '<html lang="en"><head><title>a</title></head></html>';
$r = $engine->applyDocumentOverrides($src2, []);
check('doc: no-ops returned verbatim', $r['html'] === $src2);

// --- Tier C: structural ops ---

// 17. element_remove: all exact matches removed; unmatched carried for fallback.
$r = $engine->applyElementRemove(
    '<html><body><div class="ad">x</div><p>keep</p><div class="ad">y</div></body></html>',
    [['selector' => 'div.ad'], ['selector' => 'aside.gone']]
);
check('remove: all matches deleted', !contains($r['html'], 'class="ad"') && contains($r['html'], '<p>keep</p>'));
check('remove: unmatched carried for fallback', count($r['unappliedOps']) === 1 && ($r['unappliedOps'][0]['selector'] ?? '') === 'aside.gone');

// 17b. element_remove: nothing matched -> html untouched (no re-normalisation).
$src3 = '<html><body><span>a</span></body></html>';
$r = $engine->applyElementRemove($src3, [['selector' => 'div.nope']]);
check('remove: no match -> html verbatim', $r['html'] === $src3 && count($r['unappliedOps']) === 1);

// 18. tag_replace: promote first eligible, carry children + attributes + overrides.
$r = $engine->applyTagReplace(
    '<html><body><section><span class="hd" data-k="v">Title</span></section></body></html>',
    [['selector' => 'section > span.hd', 'fromTag' => 'span', 'toTag' => 'h2',
      'attributeOverrides' => [['attribute' => 'role', 'value' => 'heading']], 'inlineStyle' => 'color:red']]
);
check('tag_replace: element promoted to h2', contains($r['html'], '<h2') && contains($r['html'], '>Title</h2>') && !contains($r['html'], '<span'));
check('tag_replace: children + attr carried', contains($r['html'], 'data-k="v"'));
check('tag_replace: override + inlineStyle applied', contains($r['html'], 'role="heading"') && contains($r['html'], 'style="color:red"'));
check('tag_replace: marker set', contains($r['html'], 'data-sc-acc-tag-replaced="1"'));

// 18b. tag_replace multi-cell (td->th) survives nth-of-type drift (two-phase).
$r = $engine->applyTagReplace(
    '<html><body><table><tr><td>a</td><td>b</td><td>c</td></tr></table></body></html>',
    [
        ['selector' => 'tr > td:nth-of-type(1)', 'fromTag' => 'td', 'toTag' => 'th'],
        ['selector' => 'tr > td:nth-of-type(2)', 'fromTag' => 'td', 'toTag' => 'th'],
        ['selector' => 'tr > td:nth-of-type(3)', 'fromTag' => 'td', 'toTag' => 'th'],
    ]
);
check('tag_replace: all 3 cells promoted (no drift)', substr_count($r['html'], '<th') === 3 && !contains($r['html'], '<td'));

// 18c. tag_replace: fromTag mismatch -> not applied, carried for fallback.
$r = $engine->applyTagReplace(
    '<html><body><div class="x">y</div></body></html>',
    [['selector' => 'div.x', 'fromTag' => 'span', 'toTag' => 'h2']]
);
check('tag_replace: fromTag mismatch -> fallback', contains($r['html'], '<div class="x">') && count($r['unappliedOps']) === 1);

// 19. sr_only_text_insert: hidden span inserted + style ensured once.
$r = $engine->applySrOnlyInsert(
    '<html><head></head><body><a class="ic" href="/x">go</a></body></html>',
    [['id' => 'sr1', 'selector' => 'a.ic', 'text' => 'Öffnet extern', 'position' => 'first_child']]
);
check('sr_only: hidden span inserted with id', contains($r['html'], 'class="sc-acc-sr-only"') && contains($r['html'], 'data-sc-acc-sr-only-id="sr1"') && contains($r['html'], '>Öffnet extern</span>'));
check('sr_only: visually-hidden style ensured once', substr_count($r['html'], 'data-sc-acc-sr-only-style') === 1);

// 19b. sr_only: unmatched anchor carried for fallback, style NOT added.
$r = $engine->applySrOnlyInsert(
    '<html><head></head><body>x</body></html>',
    [['id' => 'sr2', 'selector' => 'a.missing', 'text' => 't']]
);
check('sr_only: unmatched -> fallback, no style, html verbatim', count($r['unappliedOps']) === 1 && !contains($r['html'], 'sr-only-style'));

// 20. sibling_text_insert: default span after target with id + class.
$r = $engine->applySiblingInsert(
    '<html><body><label class="l">Name</label></body></html>',
    [['selector' => 'label.l', 'text' => 'Pflichtfeld', 'position' => 'after', 'insertId' => 'sib1']]
);
check('sibling: span inserted after with id/class/text', contains($r['html'], '<span id="sib1" class="sc-acc-inserted">Pflichtfeld</span>'));
check('sibling: inserted as sibling (after label)', contains($r['html'], '</label><span id="sib1"'));

// 20b. sibling: legend default for first_child on fieldset.
$r = $engine->applySiblingInsert(
    '<html><body><fieldset class="fs"><input></fieldset></body></html>',
    [['selector' => 'fieldset.fs', 'text' => 'Gruppe', 'position' => 'first_child', 'insertId' => 'sib2']]
);
check('sibling: fieldset first_child -> <legend>', contains($r['html'], '<fieldset class="fs"><legend id="sib2" class="sc-acc-inserted">Gruppe</legend>'));

// 20c. sibling: wrapTag whitelist wins; dedup by existing id skips.
$r = $engine->applySiblingInsert(
    '<html><body><div class="d">x</div></body></html>',
    [['selector' => 'div.d', 'text' => 'H', 'position' => 'before', 'insertId' => 'sib3', 'wrapTag' => 'h2']]
);
check('sibling: wrapTag h2 honored', contains($r['html'], '<h2 id="sib3" class="sc-acc-inserted">H</h2>'));
$r = $engine->applySiblingInsert(
    '<html><body><p id="dup">exists</p><div class="d">x</div></body></html>',
    [['selector' => 'div.d', 'text' => 'H', 'insertId' => 'dup']]
);
check('sibling: existing id -> skipped (no dup)', substr_count($r['html'], 'id="dup"') === 1);

// 21. element_insert: snippet parsed + inserted at position, stamped with insert id.
$r = $engine->applyElementInsert(
    '<html><body><main class="m"><h1>Hi</h1></main></body></html>',
    [['id' => 'ins1', 'selector' => 'main.m', 'html' => '<a class="skip" href="#c">Zum Inhalt</a>', 'position' => 'first_child']]
);
check('element_insert: snippet inserted', contains($r['html'], '<a class="skip" href="#c" data-sc-acc-insert-id="ins1">Zum Inhalt</a>'));
check('element_insert: placed as first child of main', contains($r['html'], '<main class="m"><a class="skip"'));

// 21b. element_insert: dedup by existing insert id.
$r = $engine->applyElementInsert(
    '<html><body><span data-sc-acc-insert-id="dupi">old</span><main class="m">x</main></body></html>',
    [['id' => 'dupi', 'selector' => 'main.m', 'html' => '<b>y</b>']]
);
check('element_insert: existing insert-id -> skipped', substr_count($r['html'], 'data-sc-acc-insert-id="dupi"') === 1);

// 21c. element_insert: unmatched anchor -> fallback.
$r = $engine->applyElementInsert(
    '<html><body>x</body></html>',
    [['id' => 'ins2', 'selector' => 'main.absent', 'html' => '<a>y</a>']]
);
check('element_insert: unmatched -> fallback', count($r['unappliedOps']) === 1);

// 22. element_replace: target replaced by sanitized snippet root, marked.
$r = $engine->applyElementReplace(
    '<html><body><p class="list">a, b, c</p></body></html>',
    [['selector' => 'p.list', 'html' => '<ul><li>a</li><li>b</li><li>c</li></ul>']]
);
check('element_replace: p replaced by ul', contains($r['html'], '<ul data-sc-acc-element-replaced="1"><li>a</li>') && !contains($r['html'], '<p class="list">'));

// 22b. element_replace: HTML5 corruption-safety on surrounding markup.
$page = '<html><head><script>if(a<b&&c>d){var t=`<b>x</b>`;}</script></head>'
      . '<body><svg viewBox="0 0 4 4"></svg><p class="q">old</p></body></html>';
$r = $engine->applyElementReplace($page, [['selector' => 'p.q', 'html' => '<h2>new</h2>']]);
check('element_replace: applied + script/svg intact', contains($r['html'], '<h2 data-sc-acc-element-replaced="1">new</h2>') && contains($r['html'], '<b>x</b>') && contains($r['html'], 'viewBox='));

// 22c. element_replace: unmatched -> fallback, html verbatim.
$src4 = '<html><body><div>keep</div></body></html>';
$r = $engine->applyElementReplace($src4, [['selector' => 'p.gone', 'html' => '<ul><li>x</li></ul>']]);
check('element_replace: unmatched -> fallback, verbatim', $r['html'] === $src4 && count($r['unappliedOps']) === 1);

// 22d. S6 anchor guard: a drifted selector that resolves to an unrelated element
// (list text absent from the target) must NOT be overwritten -> unapplied.
$src5 = '<html><body><p class="x">Impressum und Kontakt</p></body></html>';
$r = $engine->applyElementReplace($src5, [['selector' => 'p.x', 'html' => '<ul><li>Milch</li><li>Eier</li></ul>']]);
check('element_replace: anchor mismatch -> unapplied, verbatim', $r['html'] === $src5 && count($r['unappliedOps']) === 1);

// 22e. S6 anchor guard: the correct pseudo-list target (list text present) still
// applies normally.
$src6 = '<html><body><p class="x">• Milch • Eier</p></body></html>';
$r = $engine->applyElementReplace($src6, [['selector' => 'p.x', 'html' => '<ul><li>Milch</li><li>Eier</li></ul>']]);
check('element_replace: anchor match -> applied', contains($r['html'], '<ul data-sc-acc-element-replaced="1"><li>Milch</li>') && count($r['unappliedOps']) === 0);

// --- Resource-scoped alt (imageKey, no selector) ---

// 23. resource-alt: alt set on the <img> whose src matches; others untouched.
$r = $engine->applyResourceAltOverrides(
    '<html><body><img src="/img/cat.png"><img src="/img/dog.png"></body></html>',
    [['imageKey' => '/img/cat.png', 'value' => 'Eine Katze', 'scope' => 'shared-component-legacy-resource']]
);
check('resource-alt: alt set on matching img', contains($r['html'], 'alt="Eine Katze"'));
check('resource-alt: managed + scope marking', contains($r['html'], 'data-sc-acc-alt-managed="1"') && contains($r['html'], 'data-sc-acc-alt-scope="shared-component-legacy-resource"'));
check('resource-alt: only the matching img got alt', substr_count($r['html'], 'alt="') === 1);
check('resource-alt: fully applied -> no fallback', count($r['unappliedOps']) === 0);

// 23b. resource-alt: page-resource wins over shared-component for the same key (last write).
$r = $engine->applyResourceAltOverrides(
    '<html><body><img src="/img/logo.svg"></body></html>',
    [
        ['imageKey' => '/img/logo.svg', 'value' => 'Shared', 'scope' => 'shared-component-legacy-resource'],
        ['imageKey' => '/img/logo.svg', 'value' => 'Page', 'scope' => 'occurrence-legacy-resource'],
    ]
);
check('resource-alt: page wins over shared', contains($r['html'], 'alt="Page"') && !contains($r['html'], 'Shared') && contains($r['html'], 'data-sc-acc-alt-scope="occurrence-legacy-resource"'));

// 23c. resource-alt: every occurrence of the same src gets the alt.
$r = $engine->applyResourceAltOverrides(
    '<html><body><img src="/a.png"><div><img src="/a.png"></div></body></html>',
    [['imageKey' => '/a.png', 'value' => 'Wiederholt', 'scope' => 'shared-component-legacy-resource']]
);
check('resource-alt: all occurrences of same src get alt', substr_count($r['html'], 'alt="Wiederholt"') === 2);

// 23d. resource-alt: query string ignored in match; unmatched key carried for fallback.
$r = $engine->applyResourceAltOverrides(
    '<html><body><img src="/photo.jpg?v=2"></body></html>',
    [
        ['imageKey' => '/photo.jpg', 'value' => 'Foto', 'scope' => 'occurrence-legacy-resource'],
        ['imageKey' => '/only-post-js.png', 'value' => 'Lazy', 'scope' => 'shared-component-legacy-resource'],
    ]
);
check('resource-alt: query string ignored in match', contains($r['html'], 'alt="Foto"'));
check('resource-alt: unmatched key carried for fallback', count($r['unappliedOps']) === 1 && ($r['unappliedOps'][0]['imageKey'] ?? '') === '/only-post-js.png');

// 23e. resource-alt: nothing matched -> html verbatim (no re-normalisation), all carried.
$src5 = '<html><body><img src="/present.png"></body></html>';
$r = $engine->applyResourceAltOverrides($src5, [['imageKey' => '/absent.png', 'value' => 'x', 'scope' => 'shared-component-legacy-resource']]);
check('resource-alt: no match -> html verbatim + carried', $r['html'] === $src5 && count($r['unappliedOps']) === 1);

// --- label_append (WCAG 3.3.2 required indicator) ---

// 24. explicit label[for]: " *" appended to the associated <label>, field untouched.
$r = $engine->applyLabelAppend(
    '<html><body><label for="email">E-Mail</label><input id="email" type="email"></body></html>',
    [['selector' => '#email', 'text' => ' *']]
);
check('label_append: marker span appended to label[for]', contains($r['html'], '<span class="sc-acc-required-indicator" aria-hidden="true"> *</span></label>'));
check('label_append: label marked idempotent', contains($r['html'], 'data-sc-acc-required-marked="1"'));
check('label_append: applied -> no fallback', count($r['unappliedOps']) === 0);

// 24b. wrapping <label> (no for): marker appended inside the wrapping label.
$r = $engine->applyLabelAppend(
    '<html><body><label class="w">Name <input class="n" type="text"></label></body></html>',
    [['selector' => 'input.n', 'text' => ' *']]
);
check('label_append: wrapping label gets marker', contains($r['html'], '<span class="sc-acc-required-indicator" aria-hidden="true"> *</span></label>'));

// 24c. no label at all: safe no-op, reported unapplied, html verbatim (no error).
$src7 = '<html><body><input id="lonely" type="text"></body></html>';
$r = $engine->applyLabelAppend($src7, [['selector' => '#lonely', 'text' => ' *']]);
check('label_append: no label -> unapplied, verbatim', $r['html'] === $src7 && count($r['unappliedOps']) === 1);

// 24d. idempotent re-apply: an already-marked label is not double-starred.
$already = '<html><body><label for="e2" data-sc-acc-required-marked="1">E-Mail<span class="sc-acc-required-indicator" aria-hidden="true"> *</span></label><input id="e2"></body></html>';
$r = $engine->applyLabelAppend($already, [['selector' => '#e2', 'text' => ' *']]);
check('label_append: already-marked -> no second marker', substr_count($r['html'], 'sc-acc-required-indicator') === 1);

// 24e. missing field selector: reported unapplied for client fallback.
$r = $engine->applyLabelAppend(
    '<html><body><label for="x">X</label></body></html>',
    [['selector' => '#not-here', 'text' => ' *']]
);
check('label_append: missing field -> fallback', count($r['unappliedOps']) === 1 && ($r['unappliedOps'][0]['selector'] ?? '') === '#not-here');

// 24f. sibling <label> (no for, not wrapping) in the field's parent: resolved.
$r = $engine->applyLabelAppend(
    '<html><body><div><label>Nachricht</label><textarea name="message"></textarea></div></body></html>',
    [['selector' => 'div > textarea', 'text' => ' *']]
);
check('label_append: sibling label (no for) gets marker', contains($r['html'], 'Nachricht<span class="sc-acc-required-indicator" aria-hidden="true"> *</span></label>') && count($r['unappliedOps']) === 0);

// 24g. ambiguous parent (2 unassociated labels) -> no-op, reported unapplied.
$r = $engine->applyLabelAppend(
    '<html><body><div><label>A</label><label>B</label><input class="amb"></div></body></html>',
    [['selector' => 'input.amb', 'text' => ' *']]
);
check('label_append: ambiguous sibling labels -> unapplied', count($r['unappliedOps']) === 1);

echo "\n" . str_repeat('=', 64) . "\n";
if ($failures === 0) {
    echo "PASS — ServerHtmlMutator behaves at parity with the client bootstrap.\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed.\n";
exit(1);
