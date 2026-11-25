<?php
/**
 * Behavioural tests for AttributeNamePolicy (1.4.0, B8 of test lab 4.1): which attribute
 * names an override may write or remove. Self-contained; exit 0 = pass, 1 = fail.
 *
 * Run: php tests/attribute-name-policy-test.php   (from the plugin root)
 */

require __DIR__ . '/../lib/AttributeNamePolicy.php';

use Grav\Plugin\SiteCheckAccessibility\AttributeNamePolicy as Policy;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s\n", $ok ? 'OK' : 'XX', $label);
}

// 1. Every name the web app writes today stays writable (Gegenprobe, b8-plugin-haertung.md).
$written = [
    'alt', 'autocomplete', 'controls', 'for', 'form', 'headers', 'id', 'lang', 'list', 'muted', 'role', 'scope', 'tabindex', 'title',
    'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-hidden', 'aria-level', 'aria-live', 'aria-atomic',
    'aria-required', 'aria-checked', 'aria-expanded', 'aria-pressed', 'aria-selected', 'aria-valuenow',
    'aria-controls', 'aria-owns', 'aria-activedescendant', 'aria-details', 'aria-errormessage', 'aria-flowto',
];
foreach ($written as $name) {
    check("write $name -> allowed", Policy::rejection($name, Policy::MODE_SET) === null);
}

// 2. Every name the web app removes today stays removable.
$removed = ['aria-hidden', 'aria-label', 'aria-labelledby', 'role', 'tabindex', 'autoplay', 'inert', 'novalidate', 'onfocus', 'onpaste'];
foreach ($removed as $name) {
    check("remove $name -> allowed", Policy::rejection($name, Policy::MODE_REMOVE) === null);
}

// 3. Event handlers are never written; only the two the product removes may be removed.
foreach (['onclick', 'onerror', 'onload', 'onfocus', 'onpaste', 'onmouseover', 'ONCLICK', ' onclick '] as $name) {
    check("write '$name' -> rejected", Policy::rejection($name, Policy::MODE_SET) === Policy::ERROR_PREFIX . trim($name));
}
check('remove onclick -> rejected', Policy::rejection('onclick', Policy::MODE_REMOVE) === 'attribute_not_allowed: onclick');

// 4. Execution and navigation attributes the product does not need.
foreach (['style', 'src', 'href', 'class', 'srcdoc', 'action', 'formaction', 'xlink:href', 'data-sc-acc-managed', 'value', 'name', 'type', 'target'] as $name) {
    check("write $name -> rejected", Policy::rejection($name, Policy::MODE_SET) !== null);
    check("remove $name -> rejected", Policy::rejection($name, Policy::MODE_REMOVE) !== null);
}

// 5. Normalisation: trim + lowercase, then the shape check.
check('write " Aria-Label " -> allowed', Policy::rejection(' Aria-Label ', Policy::MODE_SET) === null);
check('write "TITLE" -> allowed', Policy::rejection('TITLE', Policy::MODE_SET) === null);
check('write "aria-" alone -> rejected', Policy::rejection('aria-', Policy::MODE_SET) !== null);
check('write "aria-label onclick" -> rejected', Policy::rejection('aria-label onclick', Policy::MODE_SET) !== null);
check('write "title\x00" (control char inside) -> rejected', Policy::rejection("ti\x00tle", Policy::MODE_SET) === 'attribute_not_allowed: ti?tle');
check('write "aria-label=\"x\"" -> rejected', Policy::rejection('aria-label="x"', Policy::MODE_SET) !== null);
check('write "" -> rejected', Policy::rejection('', Policy::MODE_SET) === 'attribute_not_allowed: ');
check('write non-string -> rejected', Policy::rejection(['title'], Policy::MODE_SET) === 'attribute_not_allowed: (kein Name)');
check('long name is cut in the error', strlen((string) Policy::rejection(str_repeat('x', 200), Policy::MODE_SET)) === strlen(Policy::ERROR_PREFIX) + 64);

// 6. Items: the four wire shapes that carry names.
check('attribute_override title -> ok', Policy::itemRejection(['type' => 'attribute_override', 'selector' => '#a', 'attribute' => 'title', 'value' => 'x']) === null);
check('attribute_override onclick -> rejected', Policy::itemRejection(['type' => 'attribute_override', 'selector' => '#a', 'attribute' => 'onclick', 'value' => 'alert(1)']) === 'attribute_not_allowed: onclick');
check('attr_set style -> rejected', Policy::itemRejection(['type' => 'attr_set', 'attribute' => 'style', 'value' => 'x']) === 'attribute_not_allowed: style');
check('aria_label_set without attribute -> ok (aria-label)', Policy::itemRejection(['type' => 'aria_label_set', 'selector' => '#a', 'value' => 'x']) === null);
check('alt_text_override with attribute src -> rejected', Policy::itemRejection(['type' => 'alt_text_override', 'attribute' => 'src', 'altText' => 'x']) === 'attribute_not_allowed: src');
check('legacy item without type, attribute alt -> ok', Policy::itemRejection(['selector' => 'img', 'attribute' => 'alt', 'altText' => 'x']) === null);
check('text_content_override textContent -> ok', Policy::itemRejection(['type' => 'text_content_override', 'attribute' => 'textContent', 'value' => 'x']) === null);
check('aria_label_set with removeAttributes aria-labelledby -> ok', Policy::itemRejection(['type' => 'aria_label_set', 'attribute' => 'aria-label', 'value' => 'x', 'removeAttributes' => ['aria-labelledby']]) === null);
check('removeAttributes with onclick -> rejected', Policy::itemRejection(['type' => 'attribute_override', 'attribute' => 'aria-hidden', 'value' => 'true', 'removeAttributes' => ['aria-label', 'onclick']]) === 'attribute_not_allowed: onclick');
check('attribute_remove onpaste -> ok', Policy::itemRejection(['type' => 'attribute_remove', 'selector' => '#pw', 'attributes' => ['onpaste']]) === null);
check('attribute_remove href -> rejected', Policy::itemRejection(['type' => 'attribute_remove', 'selector' => '#a', 'attributes' => ['href']]) === 'attribute_not_allowed: href');
check('tag_replace scope -> ok', Policy::itemRejection(['type' => 'tag_replace', 'fromTag' => 'td', 'toTag' => 'th', 'attributeOverrides' => [['attribute' => 'scope', 'value' => 'col']]]) === null);
check('tag_replace without overrides -> ok', Policy::itemRejection(['type' => 'tag_replace', 'fromTag' => 'p', 'toTag' => 'h3', 'attributeOverrides' => []]) === null);
check('tag_replace onmouseover -> rejected', Policy::itemRejection(['type' => 'tag_replace', 'attributeOverrides' => [['attribute' => 'onmouseover', 'value' => 'x']]]) === 'attribute_not_allowed: onmouseover');
check('element_insert (no attribute names on the wire) -> ok', Policy::itemRejection(['type' => 'element_insert', 'html' => '<a href="#main">x</a>']) === null);
check('css_rule_inject -> ok', Policy::itemRejection(['type' => 'css_rule_inject', 'cssRules' => [['selector' => 'a', 'property' => 'outline', 'value' => '2px solid']]]) === null);

// 7. rejectedItems names index, type, selector and error.
$rejected = Policy::rejectedItems([
    ['type' => 'attribute_override', 'selector' => '#ok', 'attribute' => 'title', 'value' => 'x'],
    ['type' => 'attribute_override', 'selector' => '#bad', 'attribute' => 'onclick', 'value' => 'x'],
    'not an item',
]);
check('rejectedItems lists exactly the bad item', $rejected === [['index' => 1, 'type' => 'attribute_override', 'selector' => '#bad', 'error' => 'attribute_not_allowed: onclick']]);
check('rejectedItems of a non-list -> []', Policy::rejectedItems(null) === []);

if ($failures > 0) {
    printf("\n%d check(s) failed\n", $failures);
    exit(1);
}
echo "\nall checks passed\n";
exit(0);
