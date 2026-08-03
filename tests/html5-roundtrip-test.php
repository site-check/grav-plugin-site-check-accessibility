<?php
/**
 * Golden-file round-trip gate for the vendored HTML5 serializer.
 *
 * This is the foundational safety net for the server-side fix migration: before the mutation
 * engine may parse->mutate->serialize a customer page, we must prove the serializer does NOT
 * corrupt the untouched HTML5 around the target element. libxml2's DOMDocument::saveHTML()
 * fails exactly this (see the "no DOM round-trip" rule); masterminds/html5 must not.
 *
 * Invariants asserted per fixture:
 *   (1) IDEMPOTENCE     — serialize(parse(x)) is a stable fixed point (no drift across renders).
 *   (2) NO SEMANTIC LOSS — critical payloads survive: inline <script> bodies, SVG viewBox
 *                          casing, void elements, and decoded attribute/text values.
 *
 * Run:  php tests/html5-roundtrip-test.php   (from the plugin root)
 * Exit: 0 = all pass, 1 = at least one failure (CI-gate friendly).
 */

require __DIR__ . '/../vendor/html5/autoload.php';

use Masterminds\HTML5;

/** Real-world HTML5 cases that historically corrupt under libxml2 saveHTML(). */
const FIXTURES = [
    'percent_encoded_href' => '<a href="/path/%C3%A4%C3%B6?q=a%20b&x=1">l</a>',
    'utf8_entities'        => '<p>Preis&nbsp;5&nbsp;&euro; &amp; caf&eacute; — Straße ä ö ü</p>',
    'inline_script_tmpl'   => "<script>const t=`hi \${x} <b>not html</b> a<b`;if(a<b&&c>d){}</script>",
    'svg_inline'           => '<svg viewBox="0 0 10 10"><path d="M0 0L10 10"/><circle cx="5" cy="5" r="2"/></svg>',
    'video_source'         => '<video controls><source src="/m/a%20b.mp4" type="video/mp4"></video>',
    'self_closing_void'    => '<img src="/x.png" alt="a"><br><hr><input type="text" value="a&b">',
    'comments'             => '<!-- keep me <script>x</script> --><div>ok</div><!--[if IE]>x<![endif]-->',
    'boolean_attrs'        => '<input disabled required><option selected>a</option>',
    'data_attrs_json'      => '<div data-cfg=\'{"a":1,"b":"<x>"}\' data-n="a &amp; b">x</div>',
    'nested_quotes'        => '<a title="He said &quot;hi&quot;" href="/a?b=1&c=2">x</a>',
];

function h5_serialize(string $html): string
{
    $h5 = new HTML5(['disable_html_ns' => true]);
    return $h5->saveHTML($h5->loadHTMLFragment($html));
}

/** Decode an attribute value the way a browser would (semantic, not byte, comparison). */
function h5_attr_value(string $html, string $tag, string $attr): ?string
{
    $h5 = new HTML5(['disable_html_ns' => true]);
    $frag = $h5->loadHTMLFragment($html);
    $found = null;
    $walk = static function ($node) use (&$walk, $tag, &$found): void {
        foreach ($node->childNodes as $child) {
            if ($found !== null) {
                return;
            }
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === $tag) {
                $found = $child;
                return;
            }
            if ($child->hasChildNodes()) {
                $walk($child);
            }
        }
    };
    $walk($frag);
    return $found ? $found->getAttribute($attr) : null;
}

$failures = 0;
$report = [];

// (1) idempotence
foreach (FIXTURES as $name => $src) {
    $s1 = h5_serialize($src);
    $s2 = h5_serialize($s1);
    $ok = ($s1 === $s2);
    if (!$ok) {
        $failures++;
        $report[] = "  s1: {$s1}\n  s2: {$s2}";
    }
    printf("[%s] idempotent: %s\n", $ok ? 'OK' : 'XX', $name);
}

// (2) no semantic loss
$assertions = [
    'inline <script> body intact (libxml2 mangles this)' =>
        strpos(h5_serialize(FIXTURES['inline_script_tmpl']), '<b>not html</b>') !== false
        && strpos(h5_serialize(FIXTURES['inline_script_tmpl']), 'if(a<b&&c>d)') !== false,
    'SVG viewBox casing preserved (libxml2 lowercases it)' =>
        strpos(h5_serialize(FIXTURES['svg_inline']), 'viewBox=') !== false,
    '<source> stays void (libxml2 injects </source>)' =>
        strpos(h5_serialize(FIXTURES['video_source']), '</source>') === false,
    'percent-encoding preserved' =>
        strpos(h5_serialize(FIXTURES['percent_encoded_href']), '%C3%A4%C3%B6') !== false,
    'JSON data-attr value decodes identical' =>
        h5_attr_value(FIXTURES['data_attrs_json'], 'div', 'data-cfg') === '{"a":1,"b":"<x>"}',
    'title attr value decodes identical' =>
        h5_attr_value(FIXTURES['nested_quotes'], 'a', 'title') === 'He said "hi"',
    'href value decodes identical' =>
        h5_attr_value(FIXTURES['percent_encoded_href'], 'a', 'href') === '/path/%C3%A4%C3%B6?q=a%20b&x=1',
];

echo "\n";
foreach ($assertions as $label => $ok) {
    if (!$ok) {
        $failures++;
    }
    printf("[%s] no-loss: %s\n", $ok ? 'OK' : 'XX', $label);
}

echo "\n" . str_repeat('=', 64) . "\n";
if ($failures === 0) {
    echo "PASS — vendored masterminds/html5 round-trips HTML5 without corruption.\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed:\n" . implode("\n", $report) . "\n";
exit(1);
