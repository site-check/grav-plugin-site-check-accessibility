<?php
/**
 * Standalone PSR-4 autoloader for the vendored masterminds/html5 (MIT).
 *
 * Vendored (not a live composer runtime dependency) — same pattern this plugin uses for
 * sodium_compat. Provides an HTML5-spec-compliant parser/serializer so the server-side
 * mutation engine can round-trip modern HTML5 without the lossy libxml2 corruption that
 * DOMDocument::saveHTML() causes (mangled inline <script> template literals, lowercased
 * SVG viewBox, invalid void-element close tags, entity/encoding drift).
 *
 * PSR-4: prefix "Masterminds\" => __DIR__ . "/src".
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Masterminds\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return; // not ours
    }
    $relative = substr($class, $len);
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
