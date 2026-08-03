<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\File\CompiledYamlFile;

// Polyfill Ed25519 if the host PHP lacks the native sodium extension.
// Some shared hosts (e.g. HostEurope on certain plans) ship PHP 8.x without
// sodium enabled — without this the plugin's signed-cache verification
// always fails and apply-fixes never reach the page. Library is
// paragonie/sodium_compat (ISC, pure-PHP). No-op when native sodium is
// present (the require itself does nothing visible — the bundled
// autoload.php internally guards the function-defining branch).
if (!function_exists('sodium_crypto_sign_verify_detached')) {
    require_once __DIR__ . '/vendor/sodium_compat/autoload.php';
}

// HTML5-spec-compliant parser/serializer (masterminds/html5, MIT, pure-PHP + ext-dom).
// Vendored for the server-side mutation engine: unlike DOMDocument::saveHTML() (libxml2,
// HTML4) it round-trips modern HTML5 without corrupting inline <script> template literals,
// SVG viewBox casing, void elements or entity/percent encodings. Guarded so a host that
// already provides the class (unlikely) is not overridden.
if (!class_exists(\Masterminds\HTML5::class)) {
    require_once __DIR__ . '/vendor/html5/autoload.php';
}

// Server-side mutation engine (parse HTML5 -> apply overrides -> serialize) used by the
// output injectors to place fixes in the raw HTTP response instead of a client <script>.
require_once __DIR__ . '/lib/CssSelectorToXPath.php';
require_once __DIR__ . '/lib/ServerHtmlMutator.php';

class SiteCheckAccessibilityPlugin extends Plugin
{
    private const PLUGIN_VERSION = '1.0.0';
    // Block-level tags that receive a data-sw-block-id marker for
    // block-scoped structural fixes.
    private const STABLE_BLOCK_TAGS = ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'figcaption', 'blockquote', 'dt', 'dd'];
    // Containers (nav, footer, cookie banners, …) whose descendants never
    // receive a data-sw-block-id marker.
    private const STABLE_BLOCK_EXCLUDED_ANCESTOR_TAGS = ['nav', 'footer', 'header', 'aside'];
    private const STABLE_BLOCK_EXCLUDED_ANCESTOR_CLASSES = ['nav', 'navbar', 'navigation', 'footer', 'site-footer', 'cookie-banner', 'cookie-notice', 'skip-link', 'breadcrumb', 'breadcrumbs'];
    private const STABLE_BLOCK_EXCLUDED_ANCESTOR_ROLES = ['navigation', 'banner', 'contentinfo'];
    private const WEBAPP_BASE_URL = 'https://site-check.de';
    private const CONNECT_STATE_NOT_CONNECTED = 'not_connected';
    private const CONNECT_STATE_PAIRING_PENDING = 'pairing_pending';
    private const CONNECT_STATE_CONNECTED = 'connected';
    private const CONNECT_STATE_ERROR = 'error';

    // Ed25519 public key (base64 SPKI DER) matching the webapp-side
    // SYNC_SIGNING_PRIVATE_KEY. Generated together via
    // `npm run crypto:generate-signing-key`. An empty placeholder here
    // disables signed-cache verification, which in 2.1.0+ means the
    // plugin will deliver NO overrides at all (overrides.json is gone).
    // Paste the printed key to enable delivery.
    private const SYNC_PUBLIC_KEY_B64 = 'MCowBQYDK2VwAyEAZwHEER1nwO7GLPVBd+egLQEHveO7z+J7ibAg3JIDfoc=';

    // Cache freshness + grace window. Cache older than STALE_SECONDS triggers
    // an async refresh. Grace > 24h without a successful pull forces
    // fail-closed (overrides not injected) so an indefinitely offline webapp
    // cannot keep shipping fixes forever.
    private const CACHE_STALE_SECONDS = 300;
    private const CACHE_GRACE_SECONDS = 86400;

    // Limits + whitelists for the sr_only_text_insert / css_rule_inject /
    // element_insert fix types. The plugin re-validates every payload
    // fail-closed and never trusts it.
    private const SR_ONLY_TEXT_MAX_LENGTH = 500;
    private const SR_ONLY_INSERT_POSITIONS = ['before', 'after', 'first_child'];
    private const CSS_INJECT_SELECTOR_MAX_LENGTH = 300;
    private const CSS_INJECT_VALUE_MAX_LENGTH = 300;
    private const ELEMENT_INSERT_HTML_MAX_LENGTH = 2000;
    // 2.3.0 — <label> added for the Steckbrief label-create fix (WCAG 1.3.1):
    // inserts a <label for="…"> linked to an input. `for` passes the fail-closed
    // checks below (not on*, not a forbidden tag/URL/entity); the webapp
    // element-insert sanitizer is the authoritative attribute gate.
    private const ELEMENT_INSERT_ALLOWED_TAGS = ['a', 'span', 'p', 'button', 'div', 'label'];
    private const ELEMENT_INSERT_POSITIONS = ['before', 'after', 'first_child', 'last_child'];

    /**
     * Per-request cache of pre-flight page HTML, keyed by pageUrl.
     * Populated by fetchHtmlForPreflight() during /apply-fixes. Reset at
     * the start of each /apply-fixes call so the cache does not leak
     * stale HTML across unrelated requests. A value of "" means the
     * fetch was attempted and failed (do not retry within this request).
     *
     * @var array<string, string>
     */
    private array $preflightHtmlCache = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [['onPluginsInitialized', 0]],
            'onOutputGenerated' => [['onOutputGenerated', 0]],
            'onAdminTwigTemplatePaths' => [['onAdminTwigTemplatePaths', 0]],
            'onTwigInitialized' => [['onTwigInitialized', 0]],
        ];
    }

    /**
     * Expose the admin connect-panel state to Twig as a function. The admin
     * field template calls sw_acc_admin_connect_state() rather than invoking the
     * plugin method directly on the object — the latter does not resolve through
     * Grav's admin Twig object-attribute access, yielding an empty state.
     */
    public function onTwigInitialized(): void
    {
        $this->grav['twig']->twig->addFunction(
            new \Twig\TwigFunction('sw_acc_admin_connect_state', [$this, 'buildAdminConnectPanelState'])
        );
    }

    public function onPluginsInitialized(): void
    {
        try {
            if ($this->isPluginEnabled()) {
                // Plugin 2.1.0 migration: the legacy overrides.json is gone.
                // cache.json is the sole delivery source now. Drop any
                // leftover file from a previous 2.0.x install so a
                // downgrade-then-upgrade path cannot resurrect stale fixes.
                // Idempotent — no-op when the file does not exist.
                $legacyOverridesPath = $this->getDataDir() . '/overrides.json';
                if (file_exists($legacyOverridesPath)) {
                    @unlink($legacyOverridesPath);
                }
            }

            $uri = $this->grav['uri'];
            $path = rtrim((string) $uri->path(), '/');

            if ($path === '') {
                $path = '/';
            }

            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

            if ($this->maybeHandlePluginEndpoint($path, $method)) {
                return;
            }

            if ($this->isAdmin()) {
                return;
            }
        } catch (\Throwable $e) {
            $this->grav['log']->error('[SiteCheckAccessibility] Plugin Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'error' => 'Internal error',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            exit;
        }
    }

    private function maybeHandlePluginEndpoint(string $path, string $method): bool
    {
        if ($path === '/plugin/sw-acc-connector/register' && $method === 'POST') {
            $this->handleRegisterEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/apply-fixes' && $method === 'POST') {
            $this->handleApplyFixesEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/set-image-meta-alt' && $method === 'POST') {
            $this->handleSetImageMetaAltEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/sync-status' && $method === 'GET') {
            $this->handleSyncStatusEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/apply-overlay-config' && $method === 'POST') {
            $this->handleApplyOverlayConfigEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/set-overlay' && $method === 'POST') {
            $this->handleSetOverlayEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/connect' && $method === 'POST') {
            $this->handleConnectEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/disconnect' && $method === 'POST') {
            $this->handleDisconnectEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/connect-status' && $method === 'GET') {
            $this->handleConnectStatusEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/healthcheck' && $method === 'GET') {
            $this->handleHealthcheckEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/page-inventory' && $method === 'GET') {
            $this->handlePageInventoryEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/webapp-status' && $method === 'GET') {
            $this->handleWebappStatusEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/sync-push' && $method === 'POST') {
            $this->handleSyncPushEndpoint();
        }

        if ($path === '/plugin/sw-acc-connector/clear-cache' && $method === 'POST') {
            $this->handleClearCacheEndpoint();
        }

        return false;
    }

    /**
     * Opt-in apply diagnostics. When enabled (see isPluginApplyDebugEnabled),
     * appends one trace line per apply attempt to apply-debug.log in the plugin
     * data dir. Off by default, so a normal install never writes a log file.
     */
    private function isPluginApplyDebugEnabled(): bool
    {
        // Opt-in only: apply diagnostics are OFF by default so a public/store
        // install never writes a debug log to disk. Enable per-site via the env
        // var DEBUG_PLUGIN_APPLY=1 or a marker file `apply-debug.enabled` in the
        // plugin data dir.
        $env = strtolower((string) (getenv('DEBUG_PLUGIN_APPLY') ?: ''));
        if ($env === '1' || $env === 'true') return true;
        return file_exists($this->getDataDir() . '/apply-debug.enabled');
    }

    private function pluginApplyDebug(string $event, array $context = []): void
    {
        if (!$this->isPluginApplyDebugEnabled()) return;
        try {
            $path = $this->getDataDir() . '/apply-debug.log';
            $line = '[' . gmdate('c') . '] [PLUGIN-APPLY-' . $event . '] ' .
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            @file_put_contents($path, $line, FILE_APPEND);
        } catch (\Throwable $ignored) {
            // Swallow IO errors — diagnose log is best-effort.
        }
    }


    private function handleWebappStatusEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAdminOrToken();

        $token = $this->getStoredApiToken();
        $connectionState = $this->readConnectionState();
        $localStatus = (string) ($connectionState['status'] ?? self::CONNECT_STATE_NOT_CONNECTED);

        if ($token === '' || $this->getWebappBaseUrl() === '') {
            $this->sendJson([
                'success' => true,
                'reconciled' => false,
                'authorized' => false,
                'webappReachable' => false,
                'localStatus' => $localStatus,
                'webapp' => null,
            ]);
            return;
        }

        try {
            $response = $this->callWebappJson('/api/connectors/grav/plugin-status', [
                'method' => 'GET',
                'headers' => ['Authorization: Bearer ' . $token],
                'timeout' => 5,
            ]);
        } catch (\Throwable $e) {
            $this->sendJson([
                'success' => true,
                'reconciled' => false,
                'authorized' => true,
                'webappReachable' => false,
                'localStatus' => $localStatus,
                'webapp' => null,
                'reason' => $e->getMessage(),
            ]);
            return;
        }

        $status = (int) ($response['status'] ?? 0);
        $body = is_array($response['json'] ?? null) ? $response['json'] : [];

        if ($status === 401) {
            // Webapp no longer recognises this token. Clear local state so the
            // admin panel reflects reality and the user can re-pair cleanly.
            $this->clearStoredApiToken();
            $this->writeConnectionState([
                'status' => self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => null,
                'pairingCode' => null,
                'pluginBaseUrl' => rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
                'connectedAt' => null,
                'lastPairingAttemptAt' => gmdate('c'),
                'lastError' => 'Die Webapp hat die Verbindung beendet.',
            ]);

            $this->sendJson([
                'success' => true,
                'reconciled' => true,
                'authorized' => false,
                'webappReachable' => true,
                'localStatus' => self::CONNECT_STATE_NOT_CONNECTED,
                'webapp' => null,
            ]);
            return;
        }

        if ($status !== 200 || !(($body['success'] ?? false) === true)) {
            $this->sendJson([
                'success' => true,
                'reconciled' => false,
                'authorized' => true,
                'webappReachable' => true,
                'localStatus' => $localStatus,
                'webapp' => null,
                'reason' => 'Unerwartete Webapp-Antwort (HTTP ' . $status . ').',
            ]);
            return;
        }

        $webappWebsite = is_array($body['data']['website'] ?? null) ? $body['data']['website'] : [];
        $webappStatus = (string) ($webappWebsite['status'] ?? '');

        $reconciled = false;
        if ($webappStatus === 'DISCONNECTED' || $webappStatus === 'DRAFT') {
            // Webapp considers us unlinked; make local state match.
            $this->clearStoredApiToken();
            $this->writeConnectionState([
                'status' => self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => null,
                'pairingCode' => null,
                'pluginBaseUrl' => rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
                'connectedAt' => null,
                'lastPairingAttemptAt' => gmdate('c'),
                'lastError' => 'Die Webapp meldet den Status ' . $webappStatus . '.',
            ]);
            $reconciled = true;
            $localStatus = self::CONNECT_STATE_NOT_CONNECTED;
        }

        $this->sendJson([
            'success' => true,
            'reconciled' => $reconciled,
            'authorized' => true,
            'webappReachable' => true,
            'localStatus' => $localStatus,
            'webapp' => [
                'websiteId' => $webappWebsite['id'] ?? null,
                'status' => $webappStatus,
                'name' => $webappWebsite['name'] ?? null,
                'baseUrl' => $webappWebsite['baseUrl'] ?? null,
                'lastSeenAt' => $webappWebsite['lastSeenAt'] ?? null,
            ],
        ]);
    }

    private function handleHealthcheckEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();

        $state = $this->readSyncState();
        $overlay = $this->readOverlayRuntimeConfig();
        $connection = $this->readConnectionState();

        $this->sendJson([
            'success' => true,
            'ok' => true,
            'pluginVersion' => self::PLUGIN_VERSION,
            'phpVersion' => PHP_VERSION,
            'gravVersion' => defined('GRAV_VERSION') ? GRAV_VERSION : null,
            'features' => [
                'alt-text',
                'aria-label',
                'attribute-override',
                'attribute-remove',
                'sr-only-insert',
                'css-rule-inject',
                'element-insert',
                'element-insert-label',
                'element-reorder',
                'label-append',
                'text-content',
                'document-override',
                'contrast-adjustment',
                'css-declaration-set',
                'overlay-config',
                'page-inventory',
                'server-side-apply',
            ],
            'status' => [
                'connectState' => (string) ($connection['status'] ?? self::CONNECT_STATE_NOT_CONNECTED),
                'lastRegisterAt' => $state['lastRegisterAt'] ?? null,
                'lastApplyAt' => $state['lastApplyAt'] ?? null,
                'lastOverlaySyncAt' => $state['lastOverlaySyncAt'] ?? null,
                'overlayEnabled' => (bool) ($overlay['enabled'] ?? false),
            ],
        ]);
    }


    /**
     * GET /plugin/sw-acc-connector/page-inventory
     *
     * Returns the count of PUBLISHED, routable, non-modular content pages plus
     * their absolute URLs (bounded). The webapp calls this as the first step of
     * each scan to (a) map the site onto a pricing tier by sub-page count and
     * (b) refuse the scan for sites above the largest self-service tier
     * (> 250 published pages → custom-offer contact path).
     *
     * Only published+routable pages are counted — drafts, unpublished, modular
     * fragments and non-routable pages (error pages etc.) never inflate the
     * billing-relevant page count. `urls` is capped (the webapp aborts above
     * 250 anyway); `publishedCount` is always the true total.
     */
    private function handlePageInventoryEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();

        // Bound the URL list so a huge site cannot blow up the response. The
        // webapp only needs the URLs for sites it will actually scan (<= 250);
        // a little headroom lets it detect the > 250 cut-off cleanly.
        $maxUrls = 300;
        $publishedCount = 0;
        $urls = [];

        try {
            $pages = $this->grav['pages'];
            $all = $pages !== null ? $pages->all() : null;

            if ($all !== null) {
                foreach ($all as $page) {
                    if ($page === null) {
                        continue;
                    }
                    if (method_exists($page, 'modular') && $page->modular()) {
                        continue;
                    }
                    if (method_exists($page, 'routable') && !$page->routable()) {
                        continue;
                    }
                    if (method_exists($page, 'published') && !$page->published()) {
                        continue;
                    }

                    $publishedCount++;
                    if (count($urls) < $maxUrls) {
                        $urls[] = $page->url(true);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->sendJson([
                'success' => false,
                'error' => 'Seiten-Inventar konnte nicht ermittelt werden: ' . $e->getMessage(),
            ], 500);
        }

        $this->sendJson([
            'success' => true,
            'pluginVersion' => self::PLUGIN_VERSION,
            'inventory' => [
                'publishedCount' => $publishedCount,
                'urls' => $urls,
                'truncated' => $publishedCount > count($urls),
            ],
        ]);
    }


    public function onAdminTwigTemplatePaths($event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/admin/themes/grav/templates';
        $event['paths'] = $paths;
    }

    public function onOutputGenerated(): void
    {
        if (!$this->isPluginEnabled()) {
            return;
        }

        // Skip admin requests entirely. Admin AJAX tasks (e.g. clearCache)
        // return JSON via the same RenderProcessor pipeline that fires this
        // event — appending <script> snippets to a JSON body produces
        // invalid JSON and breaks the admin UI silently.
        if ($this->isAdmin()) {
            return;
        }

        $output = $this->grav->output ?? null;

        if (!is_string($output) || trim($output) === '') {
            return;
        }

        // Defense in depth: only mutate responses that look like HTML.
        // Anything that does not contain a recognisable HTML marker
        // (doctype / <html / <body) is left untouched so plugin endpoints,
        // sitemap XML, RSS feeds, etc. cannot be corrupted.
        $sniff = ltrim($output);
        $looksLikeHtml = stripos($sniff, '<!doctype') === 0
            || stripos($sniff, '<html') === 0
            || stripos($output, '<body') !== false;

        if (!$looksLikeHtml) {
            return;
        }

        // B5 fail-open: a mutation error must NEVER surface as an HTTP 500.
        // The whole server-side + bootstrap sequence runs inside applyOutputMutations;
        // on any Throwable we leave $this->grav->output at the original, unmutated
        // response and log once. A broken fix is always preferable to a broken site.
        try {
            $this->grav->output = $this->applyOutputMutations($output);
        } catch (\Throwable $e) {
            try {
                $this->grav['log']->error('[SiteCheckAccessibility] onOutputGenerated failed, original output delivered: ' . $e->getMessage());
            } catch (\Throwable $ignored) {
                // Logging must never itself defeat the fail-open guarantee.
            }
        }
    }

    /**
     * Full server-side + client-bootstrap mutation sequence for one delivered
     * HTML page. Extracted from onOutputGenerated so the hook can wrap it
     * fail-open (B5): any Throwable raised here leaves the original response
     * intact instead of producing a 500.
     */
    private function applyOutputMutations(string $output): string
    {
        // 1.1.0 — Einfache & Leichte Sprache: stabile Block-IDs in
        // Block-Level-Text-Elementen setzen, damit die Webapp-Scan-
        // Pipeline (extract-text-blocks.ts) Übersetzungen über
        // data-sw-block-id deterministisch zuordnen kann. Idempotent:
        // bereits gesetzte IDs bleiben unverändert. Boilerplate-
        // Container (nav/footer/aside, role="navigation"/banner/
        // contentinfo, .cookie-banner etc.) werden übersprungen.
        $output = $this->injectStableBlockIds($output);

        $currentPageUrl = $this->getCurrentPageUrl();
        $overrides = [];

        if ($currentPageUrl !== '') {
            // Post-2.1.0 binary resolver:
            //   null    → no authoritative cache (signed-delivery not
            //             configured, pre-bootstrap, or grace expired) →
            //             deliver NO overrides, fail-closed.
            //   [items] → cache is valid, signature verified, validUntil
            //             OK and issuedAt monotone.
            // The pre-2.1.0 overrides.json merge is gone: cache.json is the
            // only source of truth.
            $cacheOverrides = $this->resolveCachedOverrides();
            $overrides = is_array($cacheOverrides) ? $cacheOverrides : [];

            if (count($overrides) > 0) {
                $output = $this->injectAltOverridesIntoHtml($output, $currentPageUrl, $overrides);
                $output = $this->injectContrastOverridesIntoHtml($output, $currentPageUrl, $overrides);
            }
        }

        $output = $this->injectSelectorOverridesBootstrap($output, $currentPageUrl, $overrides);
        $output = $this->injectSiblingInsertBootstrap($output, $currentPageUrl, $overrides);
        $output = $this->injectDocumentOverridesBootstrap($output, $currentPageUrl, $overrides);
        // 2.2.0 info-and-relationships runtime mutations. Order: tag_replace
        // first (structural), then element_replace (subtree swap), then
        // element_remove (subtraction). Each is idempotent via its own marker.
        $output = $this->injectTagReplaceBootstrap($output, $currentPageUrl, $overrides);
        $output = $this->injectElementReplaceBootstrap($output, $currentPageUrl, $overrides);
        $output = $this->injectElementRemoveBootstrap($output, $currentPageUrl, $overrides);
        // 1.4.0 WCAG-Phase-0 fix types — all three are pure client-side
        // <script> bootstraps (no server-side re-serialization, 1.2.3 rule).
        $output = $this->injectSrOnlyInsertBootstrap($output, $currentPageUrl, $overrides);
        $output = $this->injectCssRuleInjectBootstrap($output, $currentPageUrl, $overrides);
        $output = $this->injectElementInsertBootstrap($output, $currentPageUrl, $overrides);
        // 2.1.0 WCAG-Phase-2 meaningful-sequence: reorder direct children (V0).
        $output = $this->injectElementReorderBootstrap($output, $currentPageUrl, $overrides);
        // 2.5.0 WCAG 3.3.2 required-indicator: append a visible " *" to the
        // field's <label>. Runs after structural mutations so the resolved
        // <label> is stable.
        $output = $this->injectLabelAppendBootstrap($output, $currentPageUrl, $overrides);
        $output = $this->injectScHelperStyles($output, $overrides);

        $output = $this->injectOverlayRuntimeBootstrap($output);

        return $output;
    }

    /**
     * Binary cache resolver (post-2.1.0, after overrides.json removal).
     *
     * Returns:
     *   null    → NO overrides may be delivered. Covers:
     *             - signed-delivery not configured (SYNC_PUBLIC_KEY_B64 empty)
     *             - pre-bootstrap (no cache written yet, grace not yet applicable)
     *             - grace expired (webapp silent > 24h → fail-closed)
     *   [items] → cache is valid, signature verified, validUntil OK and
     *             issuedAt monotone.
     *
     * No synchronous webapp pull is performed here — that caused a race in
     * the post-apply DOM verify window. Cache writes happen via /sync-push
     * (pushed by the webapp right after a successful apply) and via the
     * async refresh scheduled below.
     */
    private function resolveCachedOverrides(): ?array
    {
        if (trim(self::SYNC_PUBLIC_KEY_B64) === '') {
            return null;
        }

        $cache = $this->loadSignedCache();

        if ($cache === null) {
            // No cache on disk. If grace expired, try one refresh in
            // shutdown so we recover once the webapp is back — but the
            // current request delivers nothing.
            if ($this->isGracePeriodExpired()) {
                register_shutdown_function(function () {
                    try {
                        $this->tryRefreshSignedCache();
                    } catch (\Throwable $ignored) {
                    }
                });
            }
            return null;
        }

        if ($this->isGracePeriodExpired()) {
            // Cache still on disk but webapp hasn't confirmed liveness in
            // over 24h → stop serving. The async refresh below will still
            // fire so we recover once the webapp is back.
            register_shutdown_function(function () {
                try {
                    $this->tryRefreshSignedCache();
                } catch (\Throwable $ignored) {
                }
            });
            return null;
        }

        if ($this->isCacheStale($cache)) {
            register_shutdown_function(function () {
                try {
                    $this->tryRefreshSignedCache();
                } catch (\Throwable $ignored) {
                }
            });
        }

        $items = is_array($cache['overrides'] ?? null) ? $cache['overrides'] : [];
        return $this->indexOverrideItemsById($items);
    }

    private function indexOverrideItemsById(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $scope = $this->normalizeOverrideScope($item['scope'] ?? null);
            $item['scope'] = $scope;
            $id = (string) ($item['id'] ?? '');
            if ($id === '') {
                $pageKey = $this->normalizePageKey((string) ($item['pageUrl'] ?? ''));
                $item['pageKey'] = $pageKey !== '' ? $pageKey : ($item['pageKey'] ?? '');
                $imageUrlRaw = (string) ($item['imageUrl'] ?? '');
                $imageKey = $imageUrlRaw !== '' ? $this->normalizeImageKey($imageUrlRaw) : '';
                if ($imageKey !== '') {
                    $item['imageKey'] = $imageKey;
                }
                $selector = (string) ($item['selector'] ?? '');
                $type = (string) ($item['type'] ?? 'alt_text_override');
                $itemPageKey = (string) ($item['pageKey'] ?? '');
                if ($type === 'alt_text_override') {
                    $id = $this->buildAltOverrideId($scope, $itemPageKey, $imageKey, $selector);
                } elseif ($type === 'contrast_adjustment') {
                    $cssProperty = (string) ($item['cssProperty'] ?? 'color');
                    $id = sha1($itemPageKey . '|' . $selector . '|' . $cssProperty);
                } elseif ($type === 'document_override') {
                    // 2.2.0 info-and-relationships: document_override has NO selector
                    // (writes to <html> lang or <title>). Was previously dropped here
                    // because the legacy `else { continue; }` rejected anything
                    // without a selector — that filter killed every page-title /
                    // lang fix before it reached injectDocumentOverridesBootstrap.
                    $documentTarget = (string) ($item['documentTarget'] ?? '');
                    if ($documentTarget === '') continue;
                    $id = sha1($itemPageKey . '|document|' . $documentTarget);
                } elseif ($type === 'sibling_text_insert' && $selector !== '') {
                    // 2.2.0 info-fieldset-legend-missing — sibling insert has
                    // selector + position but no `attribute`. Same drop-bug:
                    // the legacy elseif-branch required an attribute.
                    $position = (string) ($item['position'] ?? 'after');
                    $id = sha1($itemPageKey . '|sibling|' . $selector . '|' . $position);
                    // Stable insertId so the runtime can de-dupe re-inserts.
                    if (!isset($item['insertId'])) {
                        $item['insertId'] = 'sc-acc-ins-' . substr($id, 0, 12);
                    }
                } elseif ($type === 'tag_replace' && $selector !== '') {
                    // 2.2.0 info-h1-missing / info-multiple-h1 / info-table-
                    // header-missing all use tag_replace and were silently
                    // dropped here.
                    $fromTag = strtolower((string) ($item['fromTag'] ?? ''));
                    $toTag = strtolower((string) ($item['toTag'] ?? ''));
                    if ($fromTag === '' || $toTag === '') continue;
                    $id = sha1($itemPageKey . '|tag_replace|' . $selector . '|' . $fromTag . '->' . $toTag);
                } elseif ($type === 'element_replace' && $selector !== '') {
                    // 2.2.0 info-list-markup-missing — element_replace was
                    // silently dropped.
                    $sanitized = (string) ($item['sanitizedHtml'] ?? ($item['html'] ?? ''));
                    if ($sanitized === '') continue;
                    $id = sha1($itemPageKey . '|element_replace|' . $selector . '|' . sha1($sanitized));
                } elseif ($type === 'element_remove' && $selector !== '') {
                    $id = sha1($itemPageKey . '|element_remove|' . $selector);
                } elseif ($type === 'attribute_remove' && $selector !== '') {
                    // 1.3.0 informative-marked-decorative: pure attribute
                    // deletion (aria-hidden / role="presentation"). Without
                    // this branch an id-less item would fall into the legacy
                    // catch-all below that requires an `attribute` field and
                    // be silently dropped (same drop-bug class as 2.2.0).
                    $attributes = $this->normalizeAttributeNameList($item['attributes'] ?? null);
                    if (count($attributes) === 0) continue;
                    $item['attributes'] = $attributes;
                    $id = $this->buildSelectorOverrideId($scope, $itemPageKey, $selector, 'attribute_remove:' . implode(',', $attributes));
                } elseif ($type === 'sr_only_text_insert' && $selector !== '') {
                    // 1.4.0 WCAG-Phase-0: screenreader-only text insert. Same
                    // drop-bug guard as attribute_remove — without this branch
                    // an id-less item would fall into the legacy catch-all
                    // that requires an `attribute` field and be dropped.
                    $srText = trim((string) ($item['text'] ?? ''));
                    if ($srText === '') continue;
                    $srPosition = $this->normalizeSrOnlyInsertPosition($item['position'] ?? null);
                    $item['position'] = $srPosition;
                    $id = $this->buildSelectorOverrideId($scope, $itemPageKey, $selector, 'sr_only_text_insert:' . $srPosition);
                } elseif ($type === 'css_rule_inject') {
                    // 1.4.0 WCAG-Phase-0: page-scoped CSS rules carry NO
                    // single selector — the ID hashes the normalized rule
                    // list instead. Fail-closed on invalid rule lists.
                    $rulesCheck = $this->normalizeCssInjectRules($item['cssRules'] ?? null);
                    if ($rulesCheck['ok'] !== true) continue;
                    $item['cssRules'] = $rulesCheck['rules'];
                    $id = $this->buildCssRuleInjectId($scope, $itemPageKey, $rulesCheck['rules']);
                } elseif ($type === 'element_insert' && $selector !== '') {
                    // 1.4.0 WCAG-Phase-0: sanitized element insert (skip-link
                    // pattern). Fail-closed on the HTML whitelist re-check.
                    $insertHtml = trim((string) ($item['html'] ?? ''));
                    $htmlCheck = $this->validateElementInsertHtml($insertHtml);
                    if ($htmlCheck['ok'] !== true) continue;
                    $item['html'] = $insertHtml;
                    $insertPosition = $this->normalizeElementInsertPosition($item['position'] ?? null);
                    $item['position'] = $insertPosition;
                    $id = $this->buildSelectorOverrideId($scope, $itemPageKey, $selector, 'element_insert:' . $insertPosition . ':' . sha1($insertHtml));
                } elseif ($type === 'element_reorder') {
                    // 2.1.0 WCAG-Phase-2: reorder a parent's direct children. Carries
                    // parentSelector + orderedChildSelectors (>=2), no `attribute`
                    // field — needs its own branch so an id-less item is not dropped
                    // by the legacy attribute-required catch-all below.
                    $parentSelector = trim((string) ($item['parentSelector'] ?? $selector));
                    $childSelectors = $this->normalizeReorderChildSelectors($item['orderedChildSelectors'] ?? null);
                    if ($parentSelector === '' || count($childSelectors) < 2) continue;
                    $item['parentSelector'] = $parentSelector;
                    $item['orderedChildSelectors'] = $childSelectors;
                    $id = $this->buildSelectorOverrideId($scope, $itemPageKey, $parentSelector, 'element_reorder:' . sha1(implode('>', $childSelectors)));
                } elseif ($type === 'text_content_override' && $selector !== '') {
                    // 2.3.2: text_content_override has selector + value but no
                    // `attribute` field — the bootstrap branches on item.operation,
                    // not item.attribute, for text writes. The legacy catch-all
                    // elseif below required an attribute and silently dropped
                    // every text_content_override item, so info-heading-empty,
                    // info-heading-generic, info-link-empty, info-link-generic-text
                    // and info-link-context-missing fixes never reached the DOM.
                    $item['attribute'] = 'textContent';
                    $id = $this->buildSelectorOverrideId($scope, $itemPageKey, $selector, 'textContent');
                } elseif ($type === 'label_append' && $selector !== '') {
                    // 2.5.0 WCAG 3.3.2 required-indicator: append " *" to the
                    // field's <label>. Carries selector + text but NO `attribute`
                    // field, so the legacy attribute-required catch-all below
                    // would silently drop it (same drop-bug class as
                    // sibling_text_insert / text_content_override). Without this
                    // branch injectLabelAppendBootstrap sees matchedItems:0 and
                    // the visible marker never reaches the page.
                    $labelText = trim((string) ($item['text'] ?? ''));
                    if ($labelText === '') continue;
                    $id = $this->buildSelectorOverrideId($scope, $itemPageKey, $selector, 'label_append');
                } elseif ($selector !== '') {
                    $attribute = (string) ($item['attribute'] ?? (($type === 'aria_label_set') ? 'aria-label' : ''));
                    if ($attribute === '') continue;
                    $id = $this->buildSelectorOverrideId($scope, $itemPageKey, $selector, $attribute);
                } else {
                    continue;
                }
                $item['id'] = $id;
                // Ensure the plugin-store fields the injectors rely on are set.
                if (!isset($item['altText']) && isset($item['value']) && $type === 'alt_text_override') {
                    $item['altText'] = $item['value'];
                }
            }
            $result[$id] = $item;
        }
        return $result;
    }

    private function handleRegisterEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();
        $this->touchSyncState('lastRegisterAt');

        $siteTitle = (string) ($this->grav['config']->get('site.title') ?? 'Grav Site');
        $baseUrl = rtrim((string) $this->grav['uri']->rootUrl(true), '/');

        $this->sendJson([
            'success' => true,
            'site' => [
                'title' => $siteTitle,
                'baseUrl' => $baseUrl,
                'pluginVersion' => self::PLUGIN_VERSION,
                'mode' => 'runtime-overrides',
                'overlayRuntimeEnabled' => (bool) $this->config->get('plugins.site-check-accessibility.overlay_runtime_enabled', true),
                'webappBaseUrl' => $this->getWebappBaseUrl(),
                'connection' => [
                    'status' => ($this->readConnectionState()['status'] ?? self::CONNECT_STATE_NOT_CONNECTED),
                    'websiteId' => $this->readConnectionState()['websiteId'] ?? null,
                    'connectedAt' => $this->readConnectionState()['connectedAt'] ?? null,
                    'lastError' => $this->readConnectionState()['lastError'] ?? null,
                    'hasStoredToken' => $this->getStoredApiToken() !== '',
                ],
            ],
        ]);
    }

    private function handleApplyFixesEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();

        $payload = $this->readJsonBody();
        $fixes = $payload['fixes'] ?? null;

        if (!is_array($fixes)) {
            $this->sendJson([
                'success' => false,
                'error' => 'fixes muss ein Array sein',
            ], 400);
        }

        // Fresh pre-flight cache per /apply-fixes batch so stored HTML never
        // crosses request boundaries. Fixes within the same batch that target
        // the same pageUrl share a single loopback GET.
        $this->preflightHtmlCache = [];

        // Post-2.1.0: preflight-only endpoint. The plugin no longer persists
        // fix items — the webapp owns the canonical AppliedResolution rows
        // and ships them to the plugin as a signed cache.json via /sync-push.
        // Here we just validate each fix shape, run the DOM preflight where
        // applicable, and report success/error per fix so the webapp can
        // persist the right verdict.
        $results = [];
        $allSuccessful = true;

        foreach ($fixes as $fix) {
            if (!is_array($fix)) {
                $results[] = [
                    'success' => false,
                    'error' => 'Ungültiger Fix-Eintrag',
                ];
                $allSuccessful = false;
                continue;
            }

            $type = trim((string) ($fix['type'] ?? 'alt_text_override'));

            if ($type === 'css_declaration_set' || $type === 'css_declaration_override') {
                $result = $this->upsertCssDeclarationOverride($fix);
            } elseif ($type === 'sibling_text_insert') {
                $result = $this->upsertSiblingTextInsert($fix);
            } elseif ($type === 'contrast_adjustment') {
                $result = $this->upsertContrastOverride($fix);
            } elseif ($type === 'aria_label_set') {
                $result = $this->upsertAttributeOverride($fix, 'aria-label');
            } elseif ($type === 'attribute_override' || $type === 'attr_set') {
                // 2.1.2: attr_set is the bundled_form_fix-friendly alias for
                // attribute_override (pairs with label_append + placeholder_append).
                $result = $this->upsertAttributeOverride($fix);
            } elseif ($type === 'label_append') {
                $result = $this->upsertLabelAppend($fix);
            } elseif ($type === 'placeholder_append') {
                $result = $this->upsertPlaceholderAppend($fix);
            } elseif ($type === 'html_insert_before') {
                $result = $this->upsertHtmlInsertBefore($fix);
            } elseif ($type === 'text_content_override' || $type === 'link_text_rewrite') {
                $result = $this->upsertTextOverride($fix);
            } elseif ($type === 'document_override') {
                $result = $this->upsertDocumentOverride($fix);
            } elseif ($type === 'tag_replace') {
                $result = $this->upsertTagReplace($fix);
            } elseif ($type === 'element_replace') {
                $result = $this->upsertElementReplace($fix);
            } elseif ($type === 'element_remove') {
                $result = $this->upsertElementRemove($fix);
            } elseif ($type === 'attribute_remove') {
                // 1.3.0 text-alt informative fixes: remove suppressing
                // markers (aria-hidden / role="presentation") client-side.
                $result = $this->upsertAttributeRemove($fix);
            } elseif ($type === 'sr_only_text_insert') {
                // 1.4.0 WCAG-Phase-0: screenreader-only text insert (V0).
                $result = $this->upsertSrOnlyTextInsert($fix);
            } elseif ($type === 'css_rule_inject') {
                // 1.4.0 WCAG-Phase-0: scoped CSS rule injection (V1).
                $result = $this->upsertCssRuleInject($fix);
            } elseif ($type === 'element_insert') {
                // 1.4.0 WCAG-Phase-0: sanitized element insert (V1).
                $result = $this->upsertElementInsert($fix);
            } elseif ($type === 'element_reorder') {
                // 2.1.0 WCAG-Phase-2: reorder a parent's direct children (V0).
                $result = $this->upsertElementReorder($fix);
            } elseif ($type === '' || $type === 'alt_text_override') {
                // Legacy alt payloads may arrive without an explicit type.
                $result = $this->upsertAltOverride($fix);
            } else {
                // 1.4.0 (WP-1.1.0 parity): unknown types fail explicitly
                // instead of silently degrading to an alt override — the
                // webapp maps this onto its unsupported_fix_type fallback.
                $result = [
                    'success' => false,
                    'error' => 'unsupported_fix_type: ' . $type,
                ];
            }
            $results[] = $result;

            if (($result['success'] ?? false) !== true) {
                $allSuccessful = false;
            }
        }

        $this->touchSyncState('lastApplyAt');

        $this->sendJson([
            'success' => $allSuccessful,
            'results' => $results,
            'count' => count($results),
        ], $allSuccessful ? 200 : 207);
    }

    /**
     * Central asset-meta endpoint (text-alt Followup 1).
     *
     * Body: { assetUrl: string, altText: string, isDecorative?: boolean }
     *
     * Upserts a single shared_component alt-text override keyed only on the
     * normalised image URL — the same entry matches every page that renders the
     * asset. "Einmal entscheiden, mehrfach anwenden" without having to enumerate
     * page URLs on the caller side.
     *
     * When isDecorative=true the altText is forced to empty and the override
     * suppresses the image from assistive tech on every page.
     */
    private function handleSetImageMetaAltEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();

        $payload = $this->readJsonBody();
        $assetUrl = trim((string) ($payload['assetUrl'] ?? ''));
        $isDecorative = ($payload['isDecorative'] ?? false) === true;
        $rawAltText = $payload['altText'] ?? '';
        $altText = is_string($rawAltText) ? trim($rawAltText) : '';

        if ($assetUrl === '') {
            $this->sendJson([
                'success' => false,
                'error' => 'assetUrl fehlt',
            ], 400);
        }

        if (!$isDecorative && $altText === '') {
            $this->sendJson([
                'success' => false,
                'error' => 'altText muss gesetzt sein (ausser isDecorative=true)',
            ], 400);
        }

        $effectiveAltText = $isDecorative ? '' : $altText;

        // Post-2.1.0: the upsert helper is now validation-only. The webapp
        // persists the AppliedResolution row and pushes a signed cache.json
        // to /sync-push; this endpoint just confirms the input is well-formed
        // and the normalized identity that cache.json will reference.
        $result = $this->upsertAltOverride([
            'pageUrl' => $assetUrl,
            'imageUrl' => $assetUrl,
            'altText' => $effectiveAltText,
            'scope' => 'shared_component',
            'source' => 'central-meta',
        ]);

        if (($result['success'] ?? false) !== true) {
            $this->sendJson([
                'success' => false,
                'error' => $result['error'] ?? 'Override konnte nicht gesetzt werden',
            ], 400);
        }

        $this->touchSyncState('lastApplyAt');

        $this->sendJson([
            'success' => true,
            'id' => $result['id'] ?? null,
            'scope' => 'shared_component',
            'assetUrl' => $assetUrl,
            'imageKey' => $result['imageKey'] ?? null,
            'altText' => $effectiveAltText,
            'isDecorative' => $isDecorative,
        ], 200);
    }

    private function handleSyncStatusEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();

        $state = $this->readSyncState();
        // Post-2.1.0: storedOverrides reflects the signed cache.json content
        // since overrides.json is gone. null from resolveCachedOverrides
        // means fail-closed / pre-bootstrap — report 0 to callers.
        $cacheOverrides = $this->resolveCachedOverrides();
        $storedOverrideCount = is_array($cacheOverrides) ? count($cacheOverrides) : 0;
        $overlay = $this->readOverlayRuntimeConfig();

        $this->sendJson([
            'success' => true,
            'status' => [
                'pluginVersion' => self::PLUGIN_VERSION,
                'mode' => 'runtime-overrides',
                'lastApplyAt' => $state['lastApplyAt'] ?? null,
                'lastRegisterAt' => $state['lastRegisterAt'] ?? null,
                'lastOverlaySyncAt' => $state['lastOverlaySyncAt'] ?? null,
                'pendingFixes' => 0,
                'storedOverrides' => $storedOverrideCount,
                'overlayEnabled' => (bool) ($overlay['enabled'] ?? false),
                'overlayConfigured' => ((string) ($overlay['publicWebsiteId'] ?? '') !== '' && $this->getWebappBaseUrl() !== ''),
                'publicWebsiteId' => $overlay['publicWebsiteId'] ?? null,
                'webappBaseUrl' => $this->getWebappBaseUrl(),
            ],
        ]);
    }

    private function handleApplyOverlayConfigEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();

        $payload = $this->readJsonBody();
        $enabled = ($payload['enabled'] ?? false) === true;
        $publicWebsiteId = trim((string) ($payload['publicWebsiteId'] ?? ''));

        if ($publicWebsiteId === '') {
            $this->sendJson([
                'success' => false,
                'error' => 'publicWebsiteId fehlt',
            ], 400);
        }

        $overlayConfig = [
            'enabled' => $enabled,
            'publicWebsiteId' => $publicWebsiteId,
            'updatedAt' => gmdate('c'),
        ];

        if (!$this->writeOverlayRuntimeConfig($overlayConfig)) {
            $this->sendJson([
                'success' => false,
                'error' => 'Overlay-Konfiguration konnte nicht geschrieben werden',
            ], 500);
        }

        $this->touchSyncState('lastOverlaySyncAt');

        $this->sendJson([
            'success' => true,
            'overlay' => [
                'enabled' => $enabled,
                'publicWebsiteId' => $publicWebsiteId,
                'webappBaseUrl' => $this->getWebappBaseUrl(),
                'updatedAt' => $overlayConfig['updatedAt'],
            ],
        ]);
    }

    // Admin panel overlay toggle — flips the local "Assistenz-Overlay erlauben"
    // kill-switch (plugins.site-check-accessibility.overlay_runtime_enabled).
    // Same-origin admin fetch, no bearer token (mirrors connect/disconnect).
    private function handleSetOverlayEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAdminOrToken();

        $payload = $this->readJsonBody();
        $enabled = ($payload['enabled'] ?? false) === true;

        if (!$this->persistOverlayRuntimeEnabled($enabled)) {
            $this->sendJson([
                'success' => false,
                'error' => 'Overlay-Einstellung konnte nicht gespeichert werden',
            ], 500);
        }

        $this->sendJson([
            'success' => true,
            'overlayRuntimeEnabled' => $enabled,
        ]);
    }

    private function persistOverlayRuntimeEnabled(bool $enabled): bool
    {
        // Reflect immediately for the current request …
        $this->config->set('plugins.site-check-accessibility.overlay_runtime_enabled', $enabled);

        // … and persist to the plugin config yaml so the delivery-time
        // kill-switch (config->get) survives beyond this request.
        try {
            $locator = $this->grav['locator'];
            $path = $locator->findResource('config://plugins/site-check-accessibility.yaml', true, true);
            if (!is_string($path) || $path === '') {
                return false;
            }
            $file = CompiledYamlFile::instance($path);
            $data = (array) $file->content();
            $data['overlay_runtime_enabled'] = $enabled;
            $file->save($data);
            $file->free();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function finalizeSuccessfulConnection(string $websiteId, string $pluginBaseUrl, ?string $message = null, array $extra = []): void
    {
        // Successful connection: never store the (optional) informational
        // message in lastError, or the admin panel renders it in red. Route
        // it through lastNotice so the UI treats it as a soft info.
        $state = $this->writeConnectionState([
            'status' => self::CONNECT_STATE_CONNECTED,
            'pairingCode' => null,
            'pluginBaseUrl' => $pluginBaseUrl,
            'websiteId' => $websiteId,
            'connectedAt' => gmdate('c'),
            'lastPairingAttemptAt' => gmdate('c'),
            'lastError' => null,
            'lastNotice' => $message,
        ]);
        $this->touchSyncState('lastRegisterAt');

        $payload = [
            'success' => true,
            'connection' => [
                'status' => $state['status'] ?? self::CONNECT_STATE_CONNECTED,
                'websiteId' => $websiteId,
                'pluginBaseUrl' => $pluginBaseUrl,
                'connectedAt' => $state['connectedAt'] ?? gmdate('c'),
                'lastError' => null,
                'lastNotice' => $message,
            ],
        ];

        if ($message !== null && $message !== '') {
            $payload['notice'] = $message;
        }

        foreach ($extra as $key => $value) {
            $payload[$key] = $value;
        }

        $this->sendJson($payload);
    }

    private function isTimeoutException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'operation timed out')
            || str_contains($message, 'failed to open stream');
    }

    private function handleConnectEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAdminOrToken();

        $payload = $this->readJsonBody();
        $pairingCode = trim((string) ($payload['pairingCode'] ?? ''));

        if ($pairingCode === '') {
            $this->sendJson([
                'success' => false,
                'error' => 'pairingCode fehlt',
            ], 400);
        }

        $pluginBaseUrl = rtrim((string) $this->grav['uri']->rootUrl(true), '/');
        if ($pluginBaseUrl === '') {
            $this->writeConnectionState([
                'status' => self::CONNECT_STATE_ERROR,
                'lastError' => 'Plugin-Basis-URL konnte nicht ermittelt werden',
                'lastPairingAttemptAt' => gmdate('c'),
            ]);

            $this->sendJson([
                'success' => false,
                'error' => 'Plugin-Basis-URL konnte nicht ermittelt werden',
            ], 500);
        }

        $this->writeConnectionState([
            'status' => self::CONNECT_STATE_PAIRING_PENDING,
            'pairingCode' => $pairingCode,
            'pluginBaseUrl' => $pluginBaseUrl,
            'lastPairingAttemptAt' => gmdate('c'),
            'lastError' => null,
        ]);

        $exchangeResponse = null;
        $exchangeBody = [];
        $exchangeData = [];
        $websiteId = '';
        $apiToken = '';

        try {
            $exchangeResponse = $this->callWebappJson('/api/connectors/grav/pairing/exchange', [
                'method' => 'POST',
                'json' => [
                    'pairingCode' => $pairingCode,
                    'pluginBaseUrl' => $pluginBaseUrl,
                ],
            ]);

            $exchangeBody = $exchangeResponse['json'] ?? [];
            $exchangeSuccess = ($exchangeBody['success'] ?? false) === true;
            $exchangeData = is_array($exchangeBody['data'] ?? null) ? $exchangeBody['data'] : [];
            $exchangeErrorMessage = (string) (($exchangeBody['error']['message'] ?? '') ?: ($exchangeBody['error'] ?? ''));

            if (!$exchangeSuccess) {
                $this->writeConnectionState([
                    'status' => self::CONNECT_STATE_ERROR,
                    'pairingCode' => $pairingCode,
                    'pluginBaseUrl' => $pluginBaseUrl,
                    'lastPairingAttemptAt' => gmdate('c'),
                    'lastError' => $exchangeErrorMessage !== '' ? $exchangeErrorMessage : 'Pairing-Austausch fehlgeschlagen',
                ]);

                $this->sendJson([
                    'success' => false,
                    'error' => $exchangeErrorMessage !== '' ? $exchangeErrorMessage : 'Pairing-Austausch fehlgeschlagen',
                ], $exchangeResponse['status'] ?? 502);
            }

            $website = is_array($exchangeData['website'] ?? null) ? $exchangeData['website'] : [];
            $connectorAuth = is_array($exchangeData['connectorAuth'] ?? null) ? $exchangeData['connectorAuth'] : [];
            $connection = is_array($exchangeData['connection'] ?? null) ? $exchangeData['connection'] : [];
            $websiteId = trim((string) (($website['id'] ?? '') ?: ($connection['websiteId'] ?? '')));
            $apiToken = trim((string) (($connectorAuth['apiToken'] ?? '') ?: ($connection['apiToken'] ?? '')));

            if ($websiteId === '' || $apiToken === '') {
                $this->writeConnectionState([
                    'status' => self::CONNECT_STATE_ERROR,
                    'pairingCode' => $pairingCode,
                    'pluginBaseUrl' => $pluginBaseUrl,
                    'lastPairingAttemptAt' => gmdate('c'),
                    'lastError' => 'Webapp hat keine vollständigen Connector-Zugangsdaten geliefert',
                ]);

                $this->sendJson([
                    'success' => false,
                    'error' => 'Webapp hat keine vollständigen Connector-Zugangsdaten geliefert',
                ], 502);
            }

            if (!$this->writeStoredApiToken($apiToken)) {
                $this->writeConnectionState([
                    'status' => self::CONNECT_STATE_ERROR,
                    'pairingCode' => $pairingCode,
                    'pluginBaseUrl' => $pluginBaseUrl,
                    'websiteId' => $websiteId,
                    'lastPairingAttemptAt' => gmdate('c'),
                    'lastError' => 'API-Token konnte lokal nicht gespeichert werden',
                ]);

                $this->sendJson([
                    'success' => false,
                    'error' => 'API-Token konnte lokal nicht gespeichert werden',
                ], 500);
            }

            try {
                $registerResponse = $this->callWebappJson('/api/connectors/grav/register', [
                    'method' => 'POST',
                    'headers' => ['Authorization: Bearer ' . $apiToken],
                    'json' => [
                        'websiteId' => $websiteId,
                        'pluginBaseUrl' => $pluginBaseUrl,
                    ],
                ]);

                $registerBody = $registerResponse['json'] ?? [];
                $registerSuccess = ($registerBody['success'] ?? false) === true;
                $registerErrorMessage = (string) (($registerBody['error']['message'] ?? '') ?: ($registerBody['error'] ?? ''));

                if (!$registerSuccess) {
                    $this->writeConnectionState([
                        'status' => self::CONNECT_STATE_ERROR,
                        'pairingCode' => $pairingCode,
                        'pluginBaseUrl' => $pluginBaseUrl,
                        'websiteId' => $websiteId,
                        'lastPairingAttemptAt' => gmdate('c'),
                        'lastError' => $registerErrorMessage !== '' ? $registerErrorMessage : 'Connector-Registrierung fehlgeschlagen',
                    ]);

                    $this->sendJson([
                        'success' => false,
                        'error' => $registerErrorMessage !== '' ? $registerErrorMessage : 'Connector-Registrierung fehlgeschlagen',
                    ], $registerResponse['status'] ?? 502);
                }

                $this->finalizeSuccessfulConnection($websiteId, $pluginBaseUrl, null, [
                    'exchange' => $exchangeData,
                    'register' => $registerBody['data'] ?? $registerBody,
                ]);
            } catch (\Throwable $registerException) {
                if ($this->isTimeoutException($registerException)) {
                    $warningMessage = 'Die Website ist verbunden. Die technische Rückmeldung des Registers war nur verzögert.';
                    $this->finalizeSuccessfulConnection($websiteId, $pluginBaseUrl, $warningMessage, [
                        'exchange' => $exchangeData,
                    ]);
                }

                throw $registerException;
            }
        } catch (\Throwable $e) {
            $this->writeConnectionState([
                'status' => self::CONNECT_STATE_ERROR,
                'pairingCode' => $pairingCode,
                'pluginBaseUrl' => $pluginBaseUrl,
                'websiteId' => $websiteId !== '' ? $websiteId : null,
                'lastPairingAttemptAt' => gmdate('c'),
                'lastError' => $e->getMessage(),
            ]);

            $this->sendJson([
                'success' => false,
                'error' => 'Verbindung konnte nicht hergestellt werden: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function handleDisconnectEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAdminOrToken();

        $existingState = $this->readConnectionState();
        $existingToken = $this->getStoredApiToken();
        $existingWebsiteId = (string) ($existingState['websiteId'] ?? '');

        // Notify the webapp proactively before we clear the token locally, so
        // the webapp can flip the website status to DISCONNECTED right away
        // instead of discovering the disconnect on the next poll.
        if ($existingToken !== '' && $existingWebsiteId !== '' && $this->getWebappBaseUrl() !== '') {
            $this->notifyWebappDisconnect($existingToken, $existingWebsiteId);
        }

        $this->clearStoredApiToken();

        // 1.0.1: explicit disconnect must remove the signed cache so the
        // runtime stops shipping fixes to a site that is no longer paired.
        // Idempotent — @unlink silently no-ops when the file is already gone
        // (fresh install, previously cleaned, etc).
        @unlink($this->getSignedCacheFilePath());
        @unlink($this->getSignedCacheStateFilePath());

        $state = $this->writeConnectionState([
            'status' => self::CONNECT_STATE_NOT_CONNECTED,
            'websiteId' => null,
            'pairingCode' => null,
            'pluginBaseUrl' => rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
            'connectedAt' => null,
            'lastPairingAttemptAt' => gmdate('c'),
            'lastError' => null,
        ]);

        $this->sendJson([
            'success' => true,
            'connection' => [
                'status' => $state['status'] ?? self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => $existingState['websiteId'] ?? null,
                'pluginBaseUrl' => $state['pluginBaseUrl'] ?? null,
            ],
        ]);
    }

    private function notifyWebappDisconnect(string $token, string $websiteId): void
    {
        $options = [
            'method' => 'POST',
            'json' => ['websiteId' => $websiteId, 'source' => 'plugin'],
            'headers' => ['Authorization: Bearer ' . $token],
            'timeout' => 5,
        ];

        try {
            $this->callWebappJson('/api/connectors/grav/plugin-disconnected', $options);
            return;
        } catch (\Throwable $firstError) {
            // One retry after 2 seconds; never propagate errors to the user.
            try {
                sleep(2);
                $this->callWebappJson('/api/connectors/grav/plugin-disconnected', $options);
            } catch (\Throwable $secondError) {
                $this->grav['log']->warning('[SiteCheckAccessibility] disconnect-notify failed twice: ' . $secondError->getMessage());
            }
        }
    }

    private function handleConnectStatusEndpoint(): void
    {
        // Intentionally unauthenticated: the webapp polls this as a lightweight
        // reachability/status probe before every apply (no credentials sent).
        $state = $this->readConnectionState();
        $storedToken = $this->getStoredApiToken();

        $this->sendJson([
            'success' => true,
            'connection' => [
                'status' => $state['status'] ?? self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => $state['websiteId'] ?? null,
                'pluginBaseUrl' => $state['pluginBaseUrl'] ?? rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
                'connectedAt' => $state['connectedAt'] ?? null,
                'lastPairingAttemptAt' => $state['lastPairingAttemptAt'] ?? null,
                'lastError' => $state['lastError'] ?? null,
                'lastNotice' => $state['lastNotice'] ?? null,
                'hasStoredToken' => $storedToken !== '',
            ],
        ]);
    }

    private function normalizeOverrideScope($value): string
    {
        return $value === 'shared_component' ? 'shared_component' : 'occurrence';
    }

    /**
     * 1.3.0 — attribute_remove / removeAttributes support.
     *
     * Normalizes a payload-provided list of attribute names to a safe,
     * de-duplicated, lowercased list. Only plain attribute-name shapes pass
     * (letters/digits/underscore/colon/dot/hyphen, letter- or underscore-led)
     * — anything else is dropped fail-closed so a malformed payload can never
     * smuggle selector fragments or script into the bootstrap JSON. Removal
     * itself is inherently non-injecting (removeAttribute cannot add markup),
     * the validation just keeps the wire format strict.
     *
     * @param mixed $value
     * @return string[]
     */
    private function normalizeAttributeNameList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) continue;
            $name = strtolower(trim($entry));
            if ($name === '') continue;
            if (!preg_match('/^[a-z_][a-z0-9_:.\-]*$/', $name)) continue;
            if (!in_array($name, $result, true)) {
                $result[] = $name;
            }
        }
        return $result;
    }

    /**
     * 1.4.0 — sr_only_text_insert position whitelist. Unknown/absent values
     * fall back to `first_child` (least layout-affecting default for
     * screenreader-only text, matches §1.1).
     *
     * @param mixed $value
     */
    private function normalizeSrOnlyInsertPosition($value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($normalized, self::SR_ONLY_INSERT_POSITIONS, true) ? $normalized : 'first_child';
    }

    /**
     * 1.4.0 — element_insert position whitelist. Unknown/absent values fall
     * back to `before` (the skip-link anchor default).
     *
     * @param mixed $value
     */
    private function normalizeElementInsertPosition($value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($normalized, self::ELEMENT_INSERT_POSITIONS, true) ? $normalized : 'before';
    }

    /**
     * 2.1.0 — element_reorder child-selector list. Trims, drops empties/dupes,
     * caps at 200 entries (abuse guard). Returns [] when fewer than 2 remain
     * (a reorder needs at least two distinct children).
     *
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeReorderChildSelectors($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($value as $entry) {
            $selector = trim((string) $entry);
            if ($selector === '' || isset($seen[$selector])) {
                continue;
            }
            $seen[$selector] = true;
            $out[] = $selector;
            if (count($out) >= 200) {
                break;
            }
        }
        return count($out) >= 2 ? $out : [];
    }

    /**
     * 1.4.0 — css_rule_inject rule-list validation. Fail-closed: ANY invalid
     * rule rejects the whole list (a partially applied CSS fix would report
     * success while silently dropping declarations). Deliberately stricter
     * than the legacy contrast/css_declaration_set path (which only ever
     * receives webapp-built hex colors and does no character filtering):
     *   - selector: non-empty, bounded, no `{ } < > @ ;` and no control
     *     chars (no rule-block breakout, no markup, no at-rules),
     *   - property: plain `^[a-zA-Z-]+$` name (lowercased),
     *   - value: non-empty, bounded, no `{ } < > ; @`, no backslash (CSS
     *     escapes could re-assemble forbidden sequences), no control chars,
     *     and none of `url(`, `expression(`, `javascript:` (case-insensitive).
     *
     * @param mixed $value
     * @return array{ok: bool, rules?: array<int, array{selector: string, property: string, value: string}>, reason?: string}
     */
    private function normalizeCssInjectRules($value): array
    {
        if (!is_array($value) || count($value) === 0) {
            return ['ok' => false, 'reason' => 'cssRules fehlt oder ist leer'];
        }

        $rules = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                return ['ok' => false, 'reason' => 'cssRules-Eintrag ist kein Objekt'];
            }

            $selector = trim((string) ($entry['selector'] ?? ''));
            $property = strtolower(trim((string) ($entry['property'] ?? '')));
            $ruleValue = trim((string) ($entry['value'] ?? ''));

            if ($selector === '') {
                return ['ok' => false, 'reason' => 'cssRules: selector fehlt'];
            }
            if (mb_strlen($selector) > self::CSS_INJECT_SELECTOR_MAX_LENGTH) {
                return ['ok' => false, 'reason' => 'cssRules: selector zu lang (max ' . self::CSS_INJECT_SELECTOR_MAX_LENGTH . ' Zeichen)'];
            }
            if (preg_match('/[{}<>@;]|[\x00-\x1f]/', $selector)) {
                return ['ok' => false, 'reason' => 'cssRules: selector enthält unzulässige Zeichen'];
            }
            if (!$this->isPlausibleSelector($selector)) {
                return ['ok' => false, 'reason' => 'cssRules: selector unplausibel'];
            }

            if ($property === '' || !preg_match('/^[a-zA-Z-]+$/', $property)) {
                return ['ok' => false, 'reason' => 'cssRules: property muss dem Muster ^[a-zA-Z-]+$ entsprechen'];
            }

            if ($ruleValue === '') {
                return ['ok' => false, 'reason' => 'cssRules: value fehlt'];
            }
            if (mb_strlen($ruleValue) > self::CSS_INJECT_VALUE_MAX_LENGTH) {
                return ['ok' => false, 'reason' => 'cssRules: value zu lang (max ' . self::CSS_INJECT_VALUE_MAX_LENGTH . ' Zeichen)'];
            }
            if (preg_match('/[{}<>;@\\\\]|[\x00-\x1f]/', $ruleValue)) {
                return ['ok' => false, 'reason' => 'cssRules: value enthält unzulässige Zeichen'];
            }
            $lowerValue = strtolower($ruleValue);
            foreach (['url(', 'expression(', 'javascript:'] as $forbiddenSequence) {
                if (strpos($lowerValue, $forbiddenSequence) !== false) {
                    return ['ok' => false, 'reason' => 'cssRules: value enthält unzulässige Sequenz (' . $forbiddenSequence . ')'];
                }
            }

            $rules[] = ['selector' => $selector, 'property' => $property, 'value' => $ruleValue];
        }

        return ['ok' => true, 'rules' => $rules];
    }

    /**
     * 1.4.0 — deterministic css_rule_inject identity. The item has no single
     * selector, so the ID hashes the normalized rule list. Identical
     * derivation in upsertCssRuleInject and indexOverrideItemsById so
     * id-less cache items collapse onto the apply-time identity. Scope
     * participation mirrors buildSelectorOverrideId.
     *
     * @param array<int, array{selector: string, property: string, value: string}> $rules
     */
    private function buildCssRuleInjectId(string $scope, string $pageKey, array $rules): string
    {
        $rulesJson = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($rulesJson)) {
            $rulesJson = serialize($rules);
        }

        if ($scope === 'shared_component') {
            return sha1('shared_component|css_rule_inject:' . md5($rulesJson));
        }

        return sha1($pageKey . '|css_rule_inject:' . md5($rulesJson));
    }

    /**
     * 1.4.0 — element_insert HTML whitelist (skip-link pattern). Raw-string
     * validation, deliberately WITHOUT DOMDocument: the plugin never parses
     * or re-serializes page HTML on the output path (1.2.3 lesson), and the
     * snippet lands in the DOM client-side via <template> +
     * insertAdjacentElement anyway. The webapp sanitizer is authoritative;
     * this is the fail-closed second line of defence. Checks
     * (case-insensitive on the raw string):
     *   - bounded length,
     *   - explicit `<script` / `<style` / `<iframe` rejection,
     *   - no `javascript:` / `vbscript:` / `data:text/html` URLs — matched
     *     against a whitespace-collapsed copy so tab/newline-split schemes
     *     (`java\tscript:`) cannot slip through,
     *   - no numeric (`&#…`) or `&colon;` entities (colon-smuggle vectors —
     *     the client-side template parse would decode them),
     *   - no inline event handlers (`on…=`),
     *   - every `<` must open a plain opening/closing tag (no comments,
     *     doctypes, CDATA, processing instructions),
     *   - every tag name must be in ELEMENT_INSERT_ALLOWED_TAGS (this also
     *     rejects `</script>`-style closers of non-whitelisted tags, so the
     *     JSON embedded in the bootstrap <script> can never break out).
     *
     * @return array{ok: bool, reason?: string}
     */
    private function validateElementInsertHtml(string $html): array
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return ['ok' => false, 'reason' => 'html fehlt'];
        }
        if (strlen($trimmed) > self::ELEMENT_INSERT_HTML_MAX_LENGTH) {
            return ['ok' => false, 'reason' => 'html zu lang (max ' . self::ELEMENT_INSERT_HTML_MAX_LENGTH . ' Zeichen)'];
        }

        $lower = strtolower($trimmed);
        foreach (['<script', '<style', '<iframe'] as $forbiddenTag) {
            if (strpos($lower, $forbiddenTag) !== false) {
                return ['ok' => false, 'reason' => 'html enthält verbotenes Tag (' . ltrim($forbiddenTag, '<') . ')'];
            }
        }

        $collapsed = preg_replace('/[\s\x00-\x1f]+/', '', $lower);
        if (!is_string($collapsed)) {
            return ['ok' => false, 'reason' => 'html konnte nicht geprüft werden'];
        }
        foreach (['javascript:', 'vbscript:', 'data:text/html'] as $forbiddenUrl) {
            if (strpos($collapsed, $forbiddenUrl) !== false) {
                return ['ok' => false, 'reason' => 'html enthält verbotene URL-Sequenz (' . $forbiddenUrl . ')'];
            }
        }

        if (strpos($lower, '&#') !== false || strpos($lower, '&colon;') !== false) {
            return ['ok' => false, 'reason' => 'html enthält unzulässige Entity-Sequenz'];
        }

        if (preg_match('/\bon[a-z0-9_]+\s*=/i', $trimmed)) {
            return ['ok' => false, 'reason' => 'html enthält Event-Handler-Attribut (on…=)'];
        }

        // Every `<` must start a regular opening/closing tag — comments,
        // doctype, CDATA and processing instructions are rejected outright.
        if (preg_match('/<(?![a-zA-Z]|\/[a-zA-Z])/', $trimmed)) {
            return ['ok' => false, 'reason' => 'html enthält unzulässige <-Sequenz (Kommentar/Doctype/PI)'];
        }

        if (preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9-]*)/', $trimmed, $matches) > 0) {
            foreach ($matches[1] as $tagName) {
                $normalizedTag = strtolower($tagName);
                if (!in_array($normalizedTag, self::ELEMENT_INSERT_ALLOWED_TAGS, true)) {
                    return ['ok' => false, 'reason' => 'html enthält nicht erlaubtes Tag <' . $normalizedTag . '> (erlaubt: ' . implode(', ', self::ELEMENT_INSERT_ALLOWED_TAGS) . ')'];
                }
            }
        } else {
            return ['ok' => false, 'reason' => 'html enthält kein erlaubtes Element'];
        }

        return ['ok' => true];
    }

    private function buildSelectorOverrideId(string $scope, string $pageKey, string $selector, string $attribute): string
    {
        if ($scope === 'shared_component') {
            return sha1('shared_component|' . $attribute . '|' . $selector);
        }

        return sha1($pageKey . '|' . $attribute . '|' . $selector);
    }

    private function buildAltOverrideId(string $scope, string $pageKey, string $imageKey, string $selector = ''): string
    {
        if ($selector !== '') {
            return $this->buildSelectorOverrideId($scope, $pageKey, $selector, 'alt');
        }

        return sha1($pageKey . '|alt|resource|' . $imageKey);
    }

    // Validation + preflight only (post-2.1.0). Canonical persistence moved
    // to the webapp — this helper just confirms the input is well-formed,
    // the target element exists (occurrence scope), and returns the
    // normalized identity the webapp uses to key AppliedResolution rows.
    private function upsertAltOverride(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $imageUrl = trim((string) ($fix['imageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $altText = $fix['altText'] ?? $fix['value'] ?? null;
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if ($imageUrl === '' && $selector === '') {
            return ['success' => false, 'error' => 'imageUrl oder selector fehlt'];
        }

        if (!is_string($altText)) {
            return ['success' => false, 'error' => 'altText fehlt'];
        }

        $altText = trim($altText);
        $pageKey = $this->normalizePageKey($pageUrl);

        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        $normalizedSelector = $selector !== '' ? $selector : '';
        $imageKey = $imageUrl !== '' ? $this->normalizeImageKey($imageUrl) : '';

        if ($normalizedSelector === '' && $imageKey === '') {
            return ['success' => false, 'error' => 'Kein gültiges Alt-Text-Ziel vorhanden'];
        }

        // Pre-flight target check: confirm the rendered page actually
        // contains an <img> whose src matches our imageKey, OR (as a
        // fallback) an element that the scanner-provided selector
        // resolves to. Without this check the override lands in the
        // store and the runtime JS bootstrap silently skips it, leaving
        // the caller with a success response and no DOM change.
        // Only a DEFINITIVE negative blocks the write; inconclusive
        // results (loopback down, selector too exotic) fall through so
        // a hiccupy infrastructure never regresses a valid apply.
        if ($scope === 'occurrence') {
            $targetMatches = null;
            if ($imageKey !== '') {
                $targetMatches = $this->preflightAltTargetExists($pageUrl, $imageKey);
            }
            if ($targetMatches === null && $normalizedSelector !== '') {
                $targetMatches = $this->preflightSelectorExists($pageUrl, $normalizedSelector);
            }
            if ($targetMatches === false) {
                return [
                    'success' => false,
                    'error' => 'Alt-Text-Ziel auf der Seite nicht gefunden (kein passendes <img> bzw. Selector-Treffer). Override nicht geschrieben.',
                ];
            }
        }

        $id = $this->buildAltOverrideId($scope, $pageKey, $imageKey, $normalizedSelector);

        $result = [
            'success' => true,
            'id' => $id,
            'scope' => $scope,
            'pageKey' => $pageKey,
            'imageKey' => $imageKey !== '' ? $imageKey : null,
            'imageUrl' => $imageUrl !== '' ? $imageUrl : null,
            'pageUrl' => $pageUrl,
            'selector' => $normalizedSelector !== '' ? $normalizedSelector : null,
            'attribute' => 'alt',
            'value' => $altText,
        ];

        // 1.3.0 — optional removeAttributes: echoed back normalized so the
        // webapp persists the exact list the runtime bootstrap will apply
        // after the alt write. Absent field → key omitted (backward compat).
        $removeAttributes = $this->normalizeAttributeNameList($fix['removeAttributes'] ?? null);
        if (count($removeAttributes) > 0) {
            $result['removeAttributes'] = $removeAttributes;
        }

        return $result;
    }

    private function upsertAttributeOverride(array $fix, string $defaultAttribute = 'aria-label'): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $attribute = trim((string) ($fix['attribute'] ?? $defaultAttribute));
        $value = trim((string) ($fix['value'] ?? ''));
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }

        if (!$this->isPlausibleSelector($selector)) {
            return ['success' => false, 'error' => 'Selector matched no elements'];
        }

        if ($attribute === '') {
            return ['success' => false, 'error' => 'attribute fehlt'];
        }

        if ($value === '') {
            return ['success' => false, 'error' => 'value fehlt'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        // Pre-flight: confirm the selector actually matches at least one
        // element on the rendered page. Only blocks on a DEFINITIVE
        // negative (converter understood the selector, zero matches).
        // Shared-component scope skips the check — one override targets
        // many pages, so a single-page probe is not authoritative.
        if ($scope === 'occurrence') {
            $selectorMatches = $this->preflightSelectorExists($pageUrl, $selector);
            if ($selectorMatches === false) {
                return [
                    'success' => false,
                    'error' => 'Selector matcht auf der Seite kein Element (Pre-Flight). Override nicht geschrieben.',
                ];
            }
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $selector, $attribute);

        $result = [
            'success' => true,
            'id' => $id,
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'attribute' => $attribute,
            'value' => $value,
        ];

        // 1.3.0 — optional removeAttributes: echoed back normalized so the
        // webapp persists the exact list the runtime bootstrap will apply
        // after the attribute write. Absent field → key omitted.
        $removeAttributes = $this->normalizeAttributeNameList($fix['removeAttributes'] ?? null);
        if (count($removeAttributes) > 0) {
            $result['removeAttributes'] = $removeAttributes;
        }

        return $result;
    }

    /**
     * 2.1.2 Bundled-Form-Fix: `label_append`.
     *
     * Appends a short suffix (typically ' *') to the text of a <label>.
     * Idempotent: the apply side is expected to pass the same text every time;
     * the runtime applies it exactly once per cache.json cycle. Preflight
     * validates the selector and returns the normalised operation id.
     *
     * Shape: { pageUrl, selector, text }
     */
    private function upsertLabelAppend(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $text = (string) ($fix['text'] ?? '');
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }
        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }
        if ($text === '') {
            return ['success' => false, 'error' => 'text fehlt'];
        }
        if (!$this->isPlausibleSelector($selector)) {
            return ['success' => false, 'error' => 'Selector matched no elements'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        if ($scope === 'occurrence') {
            $selectorMatches = $this->preflightSelectorExists($pageUrl, $selector);
            if ($selectorMatches === false) {
                return [
                    'success' => false,
                    'error' => 'Label-Selector matcht auf der Seite kein Element (Pre-Flight).',
                ];
            }
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $selector, 'label_append');

        return [
            'success' => true,
            'id' => $id,
            'type' => 'label_append',
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'text' => $text,
        ];
    }

    /**
     * 2.1.2 Bundled-Form-Fix: `placeholder_append`.
     *
     * Appends a short suffix to an input's `placeholder` attribute. Used when
     * a required field has no visible <label> to annotate.
     *
     * Shape: { pageUrl, selector, text }
     */
    private function upsertPlaceholderAppend(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $text = (string) ($fix['text'] ?? '');
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }
        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }
        if ($text === '') {
            return ['success' => false, 'error' => 'text fehlt'];
        }
        if (!$this->isPlausibleSelector($selector)) {
            return ['success' => false, 'error' => 'Selector matched no elements'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        if ($scope === 'occurrence') {
            $selectorMatches = $this->preflightSelectorExists($pageUrl, $selector);
            if ($selectorMatches === false) {
                return [
                    'success' => false,
                    'error' => 'Placeholder-Selector matcht auf der Seite kein Element (Pre-Flight).',
                ];
            }
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $selector, 'placeholder_append');

        return [
            'success' => true,
            'id' => $id,
            'type' => 'placeholder_append',
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'text' => $text,
        ];
    }

    /**
     * 2.1.2 Bundled-Form-Fix: `html_insert_before`.
     *
     * Inserts an HTML snippet (typically the "* = Pflichtfeld"-Legende) before
     * a target selector. Idempotent via `idempotentKey`: the runtime skips the
     * insert when an element with `data-fix-key="<key>"` already exists.
     *
     * Shape: { pageUrl, targetSelector, html, idempotentKey }
     */
    private function upsertHtmlInsertBefore(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $targetSelector = trim((string) ($fix['targetSelector'] ?? $fix['selector'] ?? ''));
        $html = (string) ($fix['html'] ?? '');
        $idempotentKey = trim((string) ($fix['idempotentKey'] ?? ''));
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }
        if ($targetSelector === '') {
            return ['success' => false, 'error' => 'targetSelector fehlt'];
        }
        if ($html === '') {
            return ['success' => false, 'error' => 'html fehlt'];
        }
        if ($idempotentKey === '') {
            return ['success' => false, 'error' => 'idempotentKey fehlt'];
        }
        if (!$this->isPlausibleSelector($targetSelector)) {
            return ['success' => false, 'error' => 'targetSelector matched no elements'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        if ($scope === 'occurrence') {
            $selectorMatches = $this->preflightSelectorExists($pageUrl, $targetSelector);
            if ($selectorMatches === false) {
                return [
                    'success' => false,
                    'error' => 'targetSelector matcht auf der Seite kein Element (Pre-Flight).',
                ];
            }
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $targetSelector, 'html_insert_before:' . $idempotentKey);

        return [
            'success' => true,
            'id' => $id,
            'type' => 'html_insert_before',
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'targetSelector' => $targetSelector,
            'html' => $html,
            'idempotentKey' => $idempotentKey,
        ];
    }

    private function upsertTextOverride(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $value = trim((string) ($fix['value'] ?? ''));
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }

        if ($value === '') {
            return ['success' => false, 'error' => 'value fehlt'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $selector, 'textContent');

        return [
            'success' => true,
            'id' => $id,
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'attribute' => 'textContent',
            'value' => $value,
        ];
    }

    private function upsertDocumentOverride(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $documentTarget = trim((string) ($fix['documentTarget'] ?? ''));
        $value = trim((string) ($fix['value'] ?? ''));

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if (!in_array($documentTarget, ['title', 'lang'], true)) {
            return ['success' => false, 'error' => 'documentTarget muss title oder lang sein'];
        }

        if ($value === '') {
            return ['success' => false, 'error' => 'value fehlt'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        $id = sha1($pageKey . '|document|' . $documentTarget);

        return [
            'success' => true,
            'id' => $id,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'documentTarget' => $documentTarget,
            'value' => $value,
        ];
    }

    private function upsertContrastOverride(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $cssProperty = trim((string) ($fix['cssProperty'] ?? 'color'));
        $value = trim((string) ($fix['value'] ?? ''));

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }

        if (!in_array($cssProperty, ['color', 'background-color'], true)) {
            return ['success' => false, 'error' => 'Nur cssProperty=color oder background-color wird aktuell unterstützt'];
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return ['success' => false, 'error' => 'value muss eine Hex-Farbe wie #112233 sein'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        $id = sha1($pageKey . '|' . $selector . '|' . $cssProperty);

        return [
            'success' => true,
            'id' => $id,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'cssProperty' => $cssProperty,
            'value' => strtolower($value),
        ];
    }

    private function upsertCssDeclarationOverride(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);
        $pseudoClass = $this->normalizeCssPseudoClass($fix['pseudoClass'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }

        // Phase 0.1: accept either declarations[] (preferred) or the legacy
        // single cssProperty/value pair. Every entry is normalized to
        // {property, value} tuples in the stored override.
        $rawDeclarations = $fix['declarations'] ?? null;
        $declarations = [];

        if (is_array($rawDeclarations) && count($rawDeclarations) > 0) {
            foreach ($rawDeclarations as $entry) {
                if (!is_array($entry)) continue;
                $property = trim((string) ($entry['property'] ?? ''));
                $propertyValue = trim((string) ($entry['value'] ?? ''));
                if ($property === '' || $propertyValue === '') continue;
                $declarations[] = ['property' => $property, 'value' => $propertyValue];
            }
        } else {
            $property = trim((string) ($fix['cssProperty'] ?? ''));
            $propertyValue = trim((string) ($fix['value'] ?? ''));
            if ($property !== '' && $propertyValue !== '') {
                $declarations[] = ['property' => $property, 'value' => $propertyValue];
            }
        }

        if (count($declarations) === 0) {
            return ['success' => false, 'error' => 'declarations fehlen'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        // ID keeps cssProperty participation so single-property updates on the
        // same selector overwrite in place; multi-declaration writes use a
        // stable hash over the declaration keys + pseudoClass.
        $idSeed = $pageKey . '|css_decl|' . $selector . '|' . ($pseudoClass ?? '') . '|';
        foreach ($declarations as $declaration) {
            $idSeed .= $declaration['property'] . ',';
        }
        $id = sha1($idSeed);

        return [
            'success' => true,
            'id' => $id,
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'pseudoClass' => $pseudoClass,
            'declarations' => $declarations,
            'cssProperty' => $declarations[0]['property'],
            'value' => $declarations[0]['value'],
        ];
    }

    private function normalizeCssPseudoClass($value): ?string
    {
        if (!is_string($value)) return null;
        $normalized = strtolower(trim($value));
        if ($normalized === '') return null;
        $allowed = ['hover', 'focus', 'focus-visible', 'active'];
        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function upsertSiblingTextInsert(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $text = trim((string) ($fix['text'] ?? ''));
        $position = strtolower(trim((string) ($fix['position'] ?? 'after')));
        if ($position !== 'before' && $position !== 'first_child') {
            $position = 'after';
        }

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }
        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }
        if ($text === '') {
            return ['success' => false, 'error' => 'text fehlt'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        $insertId = 'sc-acc-sibling-' . substr(sha1($pageKey . '|' . $selector . '|' . $position), 0, 12);
        $id = sha1($pageKey . '|sibling|' . $selector . '|' . $position);

        return [
            'success' => true,
            'id' => $id,
            'insertId' => $insertId,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'text' => $text,
            'position' => $position,
        ];
    }

    /**
     * 2.2.0: info-and-relationships tag_replace preflight. Shape validation +
     * normalized fields; actual DOM mutation is performed client-side via
     * injectTagReplaceBootstrap() on every page render using the signed cache.
     */
    private function upsertTagReplace(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $fromTag = strtolower(trim((string) ($fix['fromTag'] ?? '')));
        $toTag = strtolower(trim((string) ($fix['toTag'] ?? '')));
        $rawOverrides = $fix['attributeOverrides'] ?? [];
        $overrides = [];
        if (is_array($rawOverrides)) {
            foreach ($rawOverrides as $entry) {
                if (!is_array($entry)) continue;
                $attribute = trim((string) ($entry['attribute'] ?? ''));
                $value = (string) ($entry['value'] ?? '');
                if ($attribute === '') continue;
                $overrides[] = ['attribute' => $attribute, 'value' => $value];
            }
        }

        if ($pageUrl === '') return ['success' => false, 'error' => 'pageUrl fehlt'];
        if ($selector === '') return ['success' => false, 'error' => 'selector fehlt'];
        if ($fromTag === '' || $toTag === '') return ['success' => false, 'error' => 'fromTag/toTag fehlt'];
        if (!preg_match('/^[a-z][a-z0-9]*$/', $fromTag) || !preg_match('/^[a-z][a-z0-9]*$/', $toTag)) {
            return ['success' => false, 'error' => 'fromTag/toTag ungültig'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];

        $id = sha1($pageKey . '|tag_replace|' . $selector . '|' . $fromTag . '->' . $toTag);

        return [
            'success' => true,
            'id' => $id,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'fromTag' => $fromTag,
            'toTag' => $toTag,
            'attributeOverrides' => $overrides,
        ];
    }

    /**
     * 2.2.0: info-and-relationships element_replace preflight. Re-runs the
     * ul/ol/li whitelist server-side as a second line of defence; the webapp
     * sanitizer is authoritative but the plugin never trusts payload.
     */
    private function upsertElementReplace(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $sanitizedHtml = (string) ($fix['sanitizedHtml'] ?? '');

        if ($pageUrl === '') return ['success' => false, 'error' => 'pageUrl fehlt'];
        if ($selector === '') return ['success' => false, 'error' => 'selector fehlt'];
        if ($sanitizedHtml === '') return ['success' => false, 'error' => 'sanitizedHtml fehlt'];

        $sanitizeCheck = $this->sanitizeElementReplaceHtml($sanitizedHtml);
        if ($sanitizeCheck['ok'] !== true) {
            return ['success' => false, 'error' => 'sanitization_failed:' . $sanitizeCheck['reason']];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];

        $id = sha1($pageKey . '|element_replace|' . $selector . '|' . sha1($sanitizeCheck['html']));

        return [
            'success' => true,
            'id' => $id,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'sanitizedHtml' => $sanitizeCheck['html'],
        ];
    }

    /**
     * 2.2.0: info-and-relationships element_remove preflight. Selector-only
     * payload. Runtime removal via injectElementRemoveBootstrap().
     */
    private function upsertElementRemove(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));

        if ($pageUrl === '') return ['success' => false, 'error' => 'pageUrl fehlt'];
        if ($selector === '') return ['success' => false, 'error' => 'selector fehlt'];

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];

        $id = sha1($pageKey . '|element_remove|' . $selector);

        return [
            'success' => true,
            'id' => $id,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
        ];
    }

    /**
     * 1.3.0: attribute_remove preflight (validation-only, post-2.1.0 model).
     * Pure attribute deletion on a selector target — used by the
     * informative-marked-decorative fix to clear suppressing markers
     * (aria-hidden="true" / role="presentation") after the alt/aria-label
     * write. Runtime removal happens client-side via the selector-overrides
     * bootstrap (operation "remove"), same selector resolution / relax
     * fallback / data-sc-acc-managed marking as the set-based overrides.
     * Per-fix verdict shape mirrors upsertAttributeOverride.
     *
     * Shape: { pageUrl, selector, attributes: string[], scope? }
     */
    private function upsertAttributeRemove(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $attributes = $this->normalizeAttributeNameList($fix['attributes'] ?? null);
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }

        if (!$this->isPlausibleSelector($selector)) {
            return ['success' => false, 'error' => 'Selector matched no elements'];
        }

        if (count($attributes) === 0) {
            return ['success' => false, 'error' => 'attributes fehlt (Liste gültiger Attributnamen)'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        // Pre-flight: same semantics as upsertAttributeOverride — only a
        // DEFINITIVE negative (selector understood, zero matches) blocks;
        // inconclusive results fall through. Shared-component scope skips
        // the single-page probe.
        if ($scope === 'occurrence') {
            $selectorMatches = $this->preflightSelectorExists($pageUrl, $selector);
            if ($selectorMatches === false) {
                return [
                    'success' => false,
                    'error' => 'Selector matcht auf der Seite kein Element (Pre-Flight). Override nicht geschrieben.',
                ];
            }
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $selector, 'attribute_remove:' . implode(',', $attributes));

        return [
            'success' => true,
            'id' => $id,
            'type' => 'attribute_remove',
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'attributes' => $attributes,
        ];
    }

    /**
     * 1.4.0 WCAG-Phase-0: sr_only_text_insert preflight (validation-only,
     * post-2.1.0 model). Screenreader-only text (visually hidden, V0 — must
     * not change rendering) at a selector anchor. Runtime insert happens
     * client-side via injectSrOnlyInsertBootstrap(); the CSS class
     * `sc-acc-sr-only` is FIXED and defined once by the bootstrap — the
     * payload deliberately carries no free-form class name (§1.1, injection
     * surface). Text is inserted as text (createTextNode), never as HTML,
     * so `<`/`>` have no business in the payload and are rejected.
     *
     * Shape: { pageUrl, selector, text, position?: before|after|first_child, scope? }
     */
    private function upsertSrOnlyTextInsert(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $text = trim((string) ($fix['text'] ?? ''));
        $position = $this->normalizeSrOnlyInsertPosition($fix['position'] ?? null);
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }

        if (!$this->isPlausibleSelector($selector)) {
            return ['success' => false, 'error' => 'Selector matched no elements'];
        }

        if ($text === '') {
            return ['success' => false, 'error' => 'text fehlt'];
        }

        if (mb_strlen($text) > self::SR_ONLY_TEXT_MAX_LENGTH) {
            return ['success' => false, 'error' => 'text zu lang (max ' . self::SR_ONLY_TEXT_MAX_LENGTH . ' Zeichen)'];
        }

        if (preg_match('/[<>]/', $text)) {
            return ['success' => false, 'error' => 'text darf kein Markup enthalten (< / > unzulässig)'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        // Pre-flight: same semantics as upsertAttributeOverride — only a
        // DEFINITIVE negative (selector understood, zero matches) blocks;
        // inconclusive results fall through. Shared-component scope skips
        // the single-page probe.
        if ($scope === 'occurrence') {
            $selectorMatches = $this->preflightSelectorExists($pageUrl, $selector);
            if ($selectorMatches === false) {
                return [
                    'success' => false,
                    'error' => 'Selector matcht auf der Seite kein Element (Pre-Flight). Override nicht geschrieben.',
                ];
            }
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $selector, 'sr_only_text_insert:' . $position);

        return [
            'success' => true,
            'id' => $id,
            'type' => 'sr_only_text_insert',
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'text' => $text,
            'position' => $position,
        ];
    }

    /**
     * 1.4.0 WCAG-Phase-0: css_rule_inject preflight (validation-only).
     * Scoped CSS rules (V1 — visible only in an interaction state, e.g.
     * :focus-visible / skip-link reveal / prefers-reduced-motion). Runtime
     * delivery is a client-side <style data-sc-acc-css-inject> tag appended
     * by injectCssRuleInjectBootstrap() — NOT the server-side contrast
     * preg_replace path, so this stays consistent with the script-bootstrap
     * model. Rule-list validation is fail-closed and strictly tighter than
     * the legacy contrast path (see normalizeCssInjectRules). No selector
     * preflight: the rules carry their own selectors and typically target
     * interaction states a static probe cannot assert.
     *
     * Shape: { pageUrl, cssRules: [{ selector, property, value }, ...], scope? }
     */
    private function upsertCssRuleInject(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        $rulesCheck = $this->normalizeCssInjectRules($fix['cssRules'] ?? null);
        if ($rulesCheck['ok'] !== true) {
            return ['success' => false, 'error' => (string) ($rulesCheck['reason'] ?? 'cssRules ungültig')];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        $id = $this->buildCssRuleInjectId($scope, $pageKey, $rulesCheck['rules']);

        return [
            'success' => true,
            'id' => $id,
            'type' => 'css_rule_inject',
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'cssRules' => $rulesCheck['rules'],
        ];
    }

    /**
     * 1.4.0 WCAG-Phase-0: element_insert preflight (validation-only).
     * Inserts a sanitized element (skip-link pattern) at a selector anchor
     * (V1 — visible only on focus). The webapp sanitizer is authoritative;
     * the plugin re-validates the raw HTML fail-closed (tag whitelist
     * a/span/p/button/div, no event handlers, no script URLs — see
     * validateElementInsertHtml). Runtime insert happens client-side via
     * injectElementInsertBootstrap() (<template> + insertAdjacentElement,
     * de-dup via data-sc-acc-insert-id).
     *
     * Shape: { pageUrl, selector, html, position?: before|after|first_child|last_child, scope? }
     */
    private function upsertElementInsert(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $selector = trim((string) ($fix['selector'] ?? ''));
        $html = trim((string) ($fix['html'] ?? ''));
        $position = $this->normalizeElementInsertPosition($fix['position'] ?? null);
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }

        if ($selector === '') {
            return ['success' => false, 'error' => 'selector fehlt'];
        }

        if (!$this->isPlausibleSelector($selector)) {
            return ['success' => false, 'error' => 'Selector matched no elements'];
        }

        $htmlCheck = $this->validateElementInsertHtml($html);
        if ($htmlCheck['ok'] !== true) {
            return ['success' => false, 'error' => 'html_validation_failed: ' . (string) ($htmlCheck['reason'] ?? 'unbekannt')];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        // Pre-flight: the anchor selector must exist on the rendered page.
        // Same DEFINITIVE-negative-only semantics as the other handlers.
        if ($scope === 'occurrence') {
            $selectorMatches = $this->preflightSelectorExists($pageUrl, $selector);
            if ($selectorMatches === false) {
                return [
                    'success' => false,
                    'error' => 'Selector matcht auf der Seite kein Element (Pre-Flight). Override nicht geschrieben.',
                ];
            }
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $selector, 'element_insert:' . $position . ':' . sha1($html));

        return [
            'success' => true,
            'id' => $id,
            'type' => 'element_insert',
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'selector' => $selector,
            'html' => $html,
            'position' => $position,
        ];
    }

    /**
     * 2.1.0 WCAG-Phase-2 element_reorder preflight (validation-only). Server-side
     * apply happens in injectElementReorderBootstrap() via
     * ServerHtmlMutator::applyElementReorder (moves existing nodes only).
     *
     * Shape: { pageUrl, parentSelector, orderedChildSelectors: string[] (>=2), scope? }
     */
    private function upsertElementReorder(array $fix): array
    {
        $pageUrl = trim((string) ($fix['pageUrl'] ?? ''));
        $parentSelector = trim((string) ($fix['parentSelector'] ?? $fix['selector'] ?? ''));
        $childSelectors = $this->normalizeReorderChildSelectors($fix['orderedChildSelectors'] ?? null);
        $scope = $this->normalizeOverrideScope($fix['scope'] ?? null);

        if ($pageUrl === '') {
            return ['success' => false, 'error' => 'pageUrl fehlt'];
        }
        if ($parentSelector === '' || !$this->isPlausibleSelector($parentSelector)) {
            return ['success' => false, 'error' => 'parentSelector fehlt oder unplausibel'];
        }
        if (count($childSelectors) < 2) {
            return ['success' => false, 'error' => 'orderedChildSelectors braucht mindestens 2 Einträge'];
        }

        $pageKey = $this->normalizePageKey($pageUrl);
        if ($pageKey === '') {
            return ['success' => false, 'error' => 'pageUrl konnte nicht normalisiert werden'];
        }

        // Pre-flight: the parent selector must exist on the rendered page.
        if ($scope === 'occurrence') {
            $parentMatches = $this->preflightSelectorExists($pageUrl, $parentSelector);
            if ($parentMatches === false) {
                return [
                    'success' => false,
                    'error' => 'parentSelector matcht auf der Seite kein Element (Pre-Flight). Override nicht geschrieben.',
                ];
            }
        }

        $id = $this->buildSelectorOverrideId($scope, $pageKey, $parentSelector, 'element_reorder:' . sha1(implode('>', $childSelectors)));

        return [
            'success' => true,
            'id' => $id,
            'type' => 'element_reorder',
            'scope' => $scope,
            'pageKey' => $pageKey,
            'pageUrl' => $pageUrl,
            'parentSelector' => $parentSelector,
            'orderedChildSelectors' => $childSelectors,
        ];
    }

    /**
     * 2.2.0: mirrors the webapp element-replace-sanitizer. Whitelist only
     * <ul>, <ol>, <li>; no attributes; text escaped. Fail-closed.
     */
    private function sanitizeElementReplaceHtml(string $input): array
    {
        $trimmed = trim($input);
        if ($trimmed === '') return ['ok' => false, 'reason' => 'empty'];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!doctype html><html><body><div id="__sw_root">' . $trimmed . '</div></body></html>';
        $loaded = $dom->loadHTML(
            mb_convert_encoding($wrapped, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        if ($loaded === false) {
            libxml_clear_errors();
            return ['ok' => false, 'reason' => 'parse'];
        }

        $root = $dom->getElementById('__sw_root');
        if ($root === null) return ['ok' => false, 'reason' => 'parse'];

        $topLevel = [];
        foreach ($root->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) $topLevel[] = $child;
        }
        if (count($topLevel) !== 1) return ['ok' => false, 'reason' => 'multiple_roots'];

        $topTag = strtolower((string) $topLevel[0]->nodeName);
        if ($topTag !== 'ul' && $topTag !== 'ol') {
            return ['ok' => false, 'reason' => 'top:' . $topTag];
        }

        return $this->serializeSanitizedNode($topLevel[0]);
    }

    private function serializeSanitizedNode(\DOMNode $node): array
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = (string) $node->nodeValue;
            return ['ok' => true, 'html' => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return ['ok' => false, 'reason' => 'nodeType:' . $node->nodeType];
        }

        $tag = strtolower((string) $node->nodeName);
        $allowed = ['ul', 'ol', 'li'];
        if (!in_array($tag, $allowed, true)) {
            return ['ok' => false, 'reason' => $tag];
        }
        if ($node->attributes !== null && $node->attributes->length > 0) {
            return ['ok' => false, 'reason' => $tag . ':has-attrs'];
        }

        $parts = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $childTag = strtolower((string) $child->nodeName);
                if (($tag === 'ul' || $tag === 'ol') && $childTag !== 'li') {
                    return ['ok' => false, 'reason' => $tag . '>' . $childTag];
                }
                if ($tag === 'li' && $childTag !== 'ul' && $childTag !== 'ol') {
                    return ['ok' => false, 'reason' => $tag . '>' . $childTag];
                }
            }
            $result = $this->serializeSanitizedNode($child);
            if ($result['ok'] !== true) return $result;
            $parts[] = $result['html'];
        }

        return ['ok' => true, 'html' => '<' . $tag . '>' . implode('', $parts) . '</' . $tag . '>'];
    }

    /**
     * 1.1.0 — Einfache & Leichte Sprache.
     *
     * Setzt eine stabile `data-sw-block-id` an jedem Block-Level-Text-
     * Element, das die Webapp-Scan-Pipeline später als Übersetzungs-
     * Kandidaten erkennt (siehe STABLE_BLOCK_TAGS). ID = SHA-1 über
     * absolute Page-URL + XPath-Position. Solange das Markup
     * unverändert bleibt, ist die ID über Reloads stabil und matched
     * 1:1 die im Webapp-Scanner generierten Hashes.
     *
     * Idempotent: bereits gesetzte Attribute werden nicht überschrieben.
     * Boilerplate-Container (nav/footer/aside, role=navigation/banner/
     * contentinfo, .cookie-banner etc.) werden komplett übersprungen —
     * dort lohnt sich keine Übersetzung.
     */
    private function injectStableBlockIds(string $html): string
    {
        // GATED OFF BY DEFAULT (1.2.3). This routine reserialised the ENTIRE
        // page via DOMDocument::saveHTML(), which libxml2 (an HTML4 parser)
        // cannot round-trip losslessly on modern pages: it percent-encodes
        // href/src attributes, entity-encodes non-ASCII, and mangles inline
        // <script> template literals plus SVG/<video> foreign content — which
        // corrupted the whole document on EVERY render (nav, layout, artist
        // widgets, …), regardless of whether any fix was applied. The
        // data-sw-block-id feature (Einfache/Leichte Sprache) is outside the
        // V1 scope, so the unsafe server-side path stays disabled. Re-enabling
        // requires a client-side bootstrap reimplementation (walk the DOM in
        // the browser like injectSelectorOverridesBootstrap) — never a full
        // server-side saveHTML() round-trip.
        if (!$this->config->get('plugins.site-check-accessibility.stable_block_ids_enabled', false)) {
            return $html;
        }

        if (!class_exists(\DOMDocument::class) || !class_exists(\DOMXPath::class)) {
            return $html;
        }

        $absolutePageUrl = '';
        try {
            $uri = $this->grav['uri'];
            $absolutePageUrl = (string) $uri->url(true);
        } catch (\Throwable $ignored) {
            // No URL context (rare, non-HTTP render path) — fall back to a
            // selector-only stable ID via the page-key heuristic.
            $absolutePageUrl = '';
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        if ($loaded === false) {
            libxml_clear_errors();
            return $html;
        }

        $xpath = new \DOMXPath($dom);
        $tagFilter = implode(' or ', array_map(
            static fn($tag) => 'self::' . $tag,
            self::STABLE_BLOCK_TAGS,
        ));
        $nodes = $xpath->query('//*[' . $tagFilter . ']');
        if ($nodes === false || $nodes->length === 0) {
            libxml_clear_errors();
            return $html;
        }

        $changed = false;

        foreach ($nodes as $node) {
            if (!($node instanceof \DOMElement)) continue;

            // Skip if already annotated — idempotency across re-renders.
            if ($node->hasAttribute('data-sw-block-id')) continue;

            // Skip empty/whitespace-only blocks early — they can't be
            // translated and would just pollute the DOM.
            $text = trim((string) $node->textContent);
            if ($text === '') continue;

            if ($this->isInsideBoilerplateContainer($node)) continue;

            $xpathExpr = $node->getNodePath();
            if (!is_string($xpathExpr) || $xpathExpr === '') continue;

            $stableId = sha1($absolutePageUrl . '|' . $xpathExpr);
            $node->setAttribute('data-sw-block-id', $stableId);
            $changed = true;
        }

        if (!$changed) {
            libxml_clear_errors();
            return $html;
        }

        $serialized = $dom->saveHTML();
        libxml_clear_errors();
        if (!is_string($serialized) || $serialized === '') {
            return $html;
        }
        return $serialized;
    }

    private function isInsideBoilerplateContainer(\DOMElement $node): bool
    {
        $current = $node->parentNode;
        $safety = 0;
        while ($current instanceof \DOMElement && $safety < 20) {
            $tag = strtolower($current->nodeName);
            if (in_array($tag, self::STABLE_BLOCK_EXCLUDED_ANCESTOR_TAGS, true)) {
                return true;
            }

            $role = strtolower((string) $current->getAttribute('role'));
            if ($role !== '' && in_array($role, self::STABLE_BLOCK_EXCLUDED_ANCESTOR_ROLES, true)) {
                return true;
            }

            $classAttr = (string) $current->getAttribute('class');
            if ($classAttr !== '') {
                $classes = preg_split('/\s+/', strtolower($classAttr)) ?: [];
                foreach (self::STABLE_BLOCK_EXCLUDED_ANCESTOR_CLASSES as $excluded) {
                    if (in_array($excluded, $classes, true)) {
                        return true;
                    }
                }
            }

            $current = $current->parentNode;
            $safety += 1;
        }
        return false;
    }

    private function injectAltOverridesIntoHtml(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') {
            return $html;
        }

        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $sharedResourceOverrides = [];
        $pageResourceOverrides = [];

        foreach ($store as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['type'] ?? 'alt_text_override') !== 'alt_text_override') {
                continue;
            }

            $selector = trim((string) ($item['selector'] ?? ''));
            if ($selector !== '') {
                continue;
            }

            $itemScope = $this->normalizeOverrideScope($item['scope'] ?? null);
            $itemPageKey = (string) ($item['pageKey'] ?? '');
            $itemImageKey = (string) ($item['imageKey'] ?? '');

            if ($itemImageKey === '' || !array_key_exists('altText', $item)) {
                continue;
            }

            $itemAltText = (string) ($item['altText'] ?? '');

            if ($itemScope === 'shared_component') {
                $sharedResourceOverrides[$itemImageKey] = $itemAltText;
                continue;
            }

            if (!$this->pageKeysMatch($itemPageKey, $currentPageKey, $currentPageRoute)) {
                continue;
            }

            $pageResourceOverrides[$itemImageKey] = $itemAltText;
        }

        if (count($sharedResourceOverrides) === 0 && count($pageResourceOverrides) === 0) {
            return $html;
        }

        // Server-side first (2.0.0): the resource-alt path has no CSS selector — it targets
        // images by their (normalised) src. Match every <img> in the delivered HTML and set
        // alt at the source, so the fix sits in the raw HTTP response. Only images NOT present
        // server-side (lazy-loaded / injected by the target site's own JS) fall back to the
        // client bootstrap below (data-sc-acc-fallback="1"). Matching mirrors the client
        // normalizePath, so the result is identical either way. Page-resource overrides win
        // over shared-component ones (last write into the map).
        $rules = [];
        foreach ($sharedResourceOverrides as $imageKey => $altText) {
            $rules[$imageKey] = ['k' => $imageKey, 'a' => $altText, 's' => 'shared-component-legacy-resource'];
        }
        foreach ($pageResourceOverrides as $imageKey => $altText) {
            $rules[$imageKey] = ['k' => $imageKey, 'a' => $altText, 's' => 'occurrence-legacy-resource'];
        }

        // Everything falls back to the client only when the HTML5 engine is unavailable (no
        // ext-dom); otherwise we apply server-side and keep just the images that weren't there.
        $fallbackRules = array_values($rules);
        if (class_exists(\Masterminds\HTML5::class) && class_exists(\DOMDocument::class)) {
            $ops = [];
            foreach ($rules as $rule) {
                $ops[] = ['imageKey' => $rule['k'], 'value' => $rule['a'], 'scope' => $rule['s']];
            }
            $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applyResourceAltOverrides($html, $ops, $currentPageUrl);
            $html = $mutation['html'];
            $fallbackRules = [];
            foreach (array_values($mutation['unappliedOps'] ?? []) as $op) {
                $fallbackRules[] = [
                    'k' => (string) ($op['imageKey'] ?? ''),
                    'a' => (string) ($op['value'] ?? ''),
                    's' => (string) ($op['scope'] ?? ''),
                ];
            }
        }

        if (count($fallbackRules) === 0) {
            // Every referenced image was present server-side — nothing for the browser to do.
            return $html;
        }

        $json = json_encode($fallbackRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) {
            return $html;
        }

        $snippet = '<script data-sc-acc-alt-resource-overrides="1" data-sc-acc-fallback="1">(function(){var rules=' . $json . ';function normalizePath(value){if(typeof value!=="string"||value.trim()===""){return"";}try{return decodeURIComponent(new URL(value,window.location.href).pathname).replace(/\/+/g,"/");}catch(e){return"";}}function apply(){var map={};for(var i=0;i<rules.length;i+=1){map[rules[i].k]=rules[i];}var imgs=document.getElementsByTagName("img");for(var j=0;j<imgs.length;j+=1){var img=imgs[j];var src=img.getAttribute("src")||"";if(!src){continue;}var key=normalizePath(src);if(!key){continue;}var rule=map[key];if(!rule){continue;}img.setAttribute("alt",rule.a);img.setAttribute("data-sc-acc-alt-managed","1");img.setAttribute("data-sc-acc-alt-scope",rule.s);}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }

        return $html . $snippet;
    }


    private function injectSelectorOverridesBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') {
            return $html;
        }

        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        $skippedForOtherPage = [];
        foreach ($store as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? '');
            if (!in_array($type, ['alt_text_override', 'aria_label_set', 'attribute_override', 'text_content_override', 'attribute_remove'], true)) {
                continue;
            }

            $selector = trim((string) ($item['selector'] ?? ''));
            if ($selector === '') {
                continue;
            }

            $scope = $this->normalizeOverrideScope($item['scope'] ?? null);
            $itemPageKey = (string) ($item['pageKey'] ?? '');
            if ($scope === 'occurrence' && !$this->pageKeysMatch($itemPageKey, $currentPageKey, $currentPageRoute)) {
                $skippedForOtherPage[] = [
                    'selector' => $selector,
                    'attribute' => trim((string) ($item['attribute'] ?? '')),
                    'pageKey' => $itemPageKey,
                ];
                continue;
            }

            // 1.3.0 — attribute_remove: pure attribute deletion, no
            // setAttribute. Shares selector resolution, relax fallback and
            // data-sc-acc-managed marking with the set-based overrides below.
            if ($type === 'attribute_remove') {
                $removeAttributes = $this->normalizeAttributeNameList($item['attributes'] ?? null);
                if (count($removeAttributes) === 0) {
                    continue;
                }
                $items[] = [
                    'selector' => $selector,
                    'attribute' => null,
                    'value' => '',
                    'scope' => $scope,
                    'imageKey' => null,
                    'operation' => 'remove',
                    'removeAttributes' => $removeAttributes,
                ];
                continue;
            }

            $attribute = trim((string) ($item['attribute'] ?? ($type === 'alt_text_override' ? 'alt' : 'aria-label')));
            $value = array_key_exists('altText', $item) ? (string) ($item['altText'] ?? '') : (string) ($item['value'] ?? '');
            if ($attribute === '') {
                continue;
            }

            $imageKey = trim((string) ($item['imageKey'] ?? ''));

            $entry = [
                'selector' => $selector,
                'attribute' => $attribute,
                'value' => $value,
                'scope' => $scope,
                'imageKey' => $imageKey !== '' ? $imageKey : null,
                'operation' => $type === 'text_content_override' ? 'text' : 'attribute',
            ];

            // 1.3.0 — optional removeAttributes on set-based selector
            // overrides: applied AFTER the value write (e.g. clear
            // aria-hidden / role="presentation" when un-hiding an
            // informative image). Items without the field behave exactly
            // as before (backward compatible).
            $removeAttributes = $this->normalizeAttributeNameList($item['removeAttributes'] ?? null);
            if (count($removeAttributes) > 0) {
                $entry['removeAttributes'] = $removeAttributes;
            }

            $items[] = $entry;
        }

        $this->pluginApplyDebug('INJECT-SELECTOR', [
            'currentPageKey' => $currentPageKey,
            'currentPageRoute' => $currentPageRoute,
            'matchedItems' => count($items),
            'skippedForOtherPage' => count($skippedForOtherPage),
            'firstSelectors' => array_slice(array_map(static function ($it) {
                return ($it['selector'] ?? '') . ' [' . ($it['attribute'] ?? '') . ']';
            }, $items), 0, 5),
        ]);

        if (count($items) === 0 && count($skippedForOtherPage) === 0) {
            return $html;
        }

        if (count($items) === 0) {
            // Nothing to apply on THIS page, but other-page overrides exist.
            // Inject a diagnostic-only script so developers see why nothing
            // happened (e.g. pageKey mismatch between scanner and Grav).
            $diagJson = json_encode([
                'currentPageKey' => $currentPageKey,
                'skippedForOtherPage' => $skippedForOtherPage,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($diagJson) && $this->isPluginApplyDebugEnabled()) {
                $diagSnippet = '<script data-sc-acc-selector-overrides-diag="1">(function(){var info=' . $diagJson . ';if(window.__scAccDbg&&typeof console!=="undefined"&&console.info){console.info("[site-check] No overrides match this page. currentPageKey="+info.currentPageKey+", skipped="+info.skippedForOtherPage.length+" override(s) bound to other pageKeys:",info.skippedForOtherPage);}})();</script>';
                if (stripos($html, '</body>') !== false) {
                    return preg_replace('/<\/body>/i', $diagSnippet . '</body>', $html, 1) ?? ($html . $diagSnippet);
                }
                return $html . $diagSnippet;
            }
            return $html;
        }

        // Server-side first (2.0.0): apply every override that resolves in the delivered
        // HTML directly, so the fix sits in the raw HTTP response — no JS execution needed.
        // Only elements that are NOT present in the server HTML (created later by the
        // target site's own JS: SPA/theme/third-party) fall back to the client bootstrap
        // below. Same operation set, same relax fallback, same imageKey guard, same
        // data-sc-acc-managed marking — so the end result is identical either way.
        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->apply($html, $items, $currentPageUrl);
        $html = $mutation['html'];
        $fallbackItems = array_values($mutation['report']['unappliedOps'] ?? []);

        $this->pluginApplyDebug('INJECT-SELECTOR-SERVER', [
            'appliedServerSide' => $mutation['report']['applied'] ?? 0,
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) {
            // Fully applied at the source — nothing left for the browser to do.
            return $html;
        }

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) {
            return $html;
        }

        // Client fallback ONLY for elements absent from the server HTML (post-JS drift).
        // This is assistance, not a source-fix — data-sc-acc-fallback="1" distinguishes it
        // for verification/telemetry. Resolution mirrors the server engine: exact selector,
        // then the relaxed last segment (tag + classes, no :nth-*/#id/pseudos) but only on
        // a single unambiguous match, so the wrong element is never overwritten.
        $snippet = '<script data-sc-acc-selector-overrides="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';function normalizePath(value){if(typeof value!=="string"||value.trim()===""){return"";}try{return decodeURIComponent(new URL(value,window.location.href).pathname).replace(/\/+/g,"/");}catch(e){return"";}}function matchesImageKey(node,imageKey){if(!imageKey){return true;}if(!node||!node.getAttribute){return false;}var src=node.getAttribute("src")||"";if(!src){return false;}return normalizePath(src)===imageKey;}function relaxSelector(sel){if(typeof sel!=="string"||sel===""){return"";}var parts=sel.split(/\s*>\s*|\s+/);var last="";for(var k=parts.length-1;k>=0;k-=1){if(parts[k]){last=parts[k];break;}}if(!last){return"";}var relaxed=last.replace(/:nth-[a-z-]+\([^)]*\)/gi,"").replace(/::?[a-z-]+(\([^)]*\))?/gi,"").replace(/#[^.:\[]+/g,"").trim();if(!relaxed||relaxed===sel){return"";}return relaxed;}function resolveNodes(selector){var nodes=[];try{nodes=document.querySelectorAll(selector);}catch(e){nodes=[];}if(nodes.length>0){return nodes;}var relaxed=relaxSelector(selector);if(relaxed){var rnodes=[];try{rnodes=document.querySelectorAll(relaxed);}catch(e){rnodes=[];}if(rnodes.length===1){if(window.__scAccDbg&&typeof console!=="undefined"&&console.info){console.info("[site-check] Selector drift: relaxed match",relaxed,"for",selector);}return rnodes;}}return nodes;}function apply(){for(var i=0;i<items.length;i+=1){var item=items[i];var nodes=resolveNodes(item.selector);if(nodes.length===0){if(window.__scAccDbg&&typeof console!=="undefined"&&console.warn){console.warn("[site-check] Selector matched no elements:",item.selector,"attribute:",item.attribute||(item.removeAttributes||[]).join(","));}continue;}for(var j=0;j<nodes.length;j+=1){var node=nodes[j];if(!node){continue;}if(item.attribute==="alt"&&!matchesImageKey(node,item.imageKey||null)){continue;}if(item.operation==="text"){node.textContent=item.value;}else if(item.operation!=="remove"&&node.setAttribute){node.setAttribute(item.attribute,item.value);}var rm=item.removeAttributes;if(rm&&rm.length&&node.removeAttribute){for(var r=0;r<rm.length;r+=1){try{node.removeAttribute(rm[r]);}catch(e){}}}if(node.setAttribute){node.setAttribute("data-sc-acc-managed","1");if(item.attribute){node.setAttribute("data-sc-acc-managed-attribute",item.attribute);}if(rm&&rm.length){node.setAttribute("data-sc-acc-managed-removed",rm.join(" "));}}}} }if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }

        return $html . $snippet;
    }


    private function injectSiblingInsertBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') {
            return $html;
        }

        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'sibling_text_insert') continue;

            $itemPageKey = (string) ($item['pageKey'] ?? '');
            if (!$this->pageKeysMatch($itemPageKey, $currentPageKey, $currentPageRoute)) continue;

            $selector = trim((string) ($item['selector'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            $rawPosition = (string) ($item['position'] ?? 'after');
            $position = $rawPosition === 'before' ? 'before' : ($rawPosition === 'first_child' ? 'first_child' : 'after');
            $insertId = trim((string) ($item['insertId'] ?? ''));

            // 1.0.1: wrapTag is an additive, optional field. Whitelist guards
            // against unsafe values (e.g. <script>) from a compromised payload
            // — anything outside the list falls back to span (legacy default).
            $rawWrapTag = strtolower(trim((string) ($item['wrapTag'] ?? '')));
            $allowedWrapTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'legend'];
            $wrapTag = in_array($rawWrapTag, $allowedWrapTags, true) ? $rawWrapTag : '';

            if ($selector === '' || $text === '' || $insertId === '') continue;

            $items[] = [
                'selector' => $selector,
                'text' => $text,
                'position' => $position,
                'insertId' => $insertId,
                'wrapTag' => $wrapTag,
            ];
        }

        $this->pluginApplyDebug('INJECT-SIBLING', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
            'firstSelectors' => array_slice(array_map(static function ($it) {
                return ($it['selector'] ?? '') . ' @' . ($it['position'] ?? '');
            }, $items), 0, 5),
        ]);

        if (count($items) === 0) {
            return $html;
        }

        // Server-side first (2.0.0): insert the sibling text element directly in the
        // delivered HTML. Only anchors absent from the server HTML (post-JS/SPA) fall
        // back to the observer script below (data-sc-acc-fallback="1").
        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applySiblingInsert($html, $items);
        $html = $mutation['html'];
        $fallbackItems = $mutation['unappliedOps'];

        $this->pluginApplyDebug('INJECT-SIBLING-SERVER', [
            'itemsTotal' => count($items),
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) {
            return $html;
        }

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) {
            return $html;
        }

        // Idempotent: apply() checks for the #insertId before re-inserting so
        // SPA re-renders don't stack duplicates. Marked with .sc-acc-inserted
        // so the CSS layer can style them uniformly if needed.
        // 1.0.1: item.wrapTag (if present and whitelisted server-side) wins
        // over the legacy span/legend default. Without wrapTag we keep the
        // previous behavior: <legend> when first_child on a <fieldset>,
        // otherwise <span> — that path stays backward-compatible for any
        // cache.json items that pre-date the wrapTag field.
        $snippet = '<script data-sc-acc-sibling-inserts="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';function apply(){for(var i=0;i<items.length;i+=1){var item=items[i];if(document.getElementById(item.insertId)){continue;}var nodes=[];try{nodes=document.querySelectorAll(item.selector);}catch(e){nodes=[];}if(nodes.length===0){if(window.__scAccDbg&&typeof console!=="undefined"&&console.warn){console.warn("[site-check] sibling_text_insert selector matched no elements:",item.selector);}continue;}var target=nodes[0];var tag="span";if(item.wrapTag){tag=item.wrapTag;}else if(item.position==="first_child"&&target.tagName&&String(target.tagName).toLowerCase()==="fieldset"){tag="legend";}var node=document.createElement(tag);node.id=item.insertId;node.className="sc-acc-inserted";node.textContent=item.text;if(item.position==="first_child"){if(target.firstChild){target.insertBefore(node,target.firstChild);}else{target.appendChild(node);}}else if(item.position==="before"){if(target.parentNode){target.parentNode.insertBefore(node,target);}}else{if(target.parentNode){target.parentNode.insertBefore(node,target.nextSibling);}}}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}var mo=new MutationObserver(function(){apply();});try{mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }

        return $html . $snippet;
    }

    /**
     * 2.5.0 label_append: append a short visible required marker (" *") to the
     * <label> that belongs to each field selector (WCAG 3.3.2). Server-side
     * first (ServerHtmlMutator::applyLabelAppend resolves label[for] / wrapping
     * <label>); only fields absent from the server HTML (post-JS/SPA) fall back
     * to the observer script. Safe no-op when a field has no label — the paired
     * aria-required attribute_override op still lands. Idempotent via
     * data-sc-acc-required-marked on the label.
     */
    private function injectLabelAppendBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') {
            return $html;
        }

        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'label_append') continue;

            $scope = $this->normalizeOverrideScope($item['scope'] ?? null);
            $itemPageKey = (string) ($item['pageKey'] ?? '');
            if ($scope === 'occurrence' && !$this->pageKeysMatch($itemPageKey, $currentPageKey, $currentPageRoute)) continue;

            $selector = trim((string) ($item['selector'] ?? ''));
            // Keep the raw text (usually " *") — trimming would drop the leading
            // space that separates the marker from the label text ("Name *").
            $rawText = (string) ($item['text'] ?? '');
            if ($selector === '' || trim($rawText) === '') continue;

            $items[] = ['selector' => $selector, 'text' => $rawText];
        }

        $this->pluginApplyDebug('INJECT-LABEL-APPEND', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
        ]);

        if (count($items) === 0) {
            return $html;
        }

        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applyLabelAppend($html, $items);
        $html = $mutation['html'];
        $fallbackItems = $mutation['unappliedOps'];

        $this->pluginApplyDebug('INJECT-LABEL-APPEND-SERVER', [
            'itemsTotal' => count($items),
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) {
            return $html;
        }

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) {
            return $html;
        }

        // Client fallback ONLY for fields absent from the server HTML (post-JS drift).
        // Mirrors the server label resolution: label[for="<id>"], then closest("label").
        // Idempotent via data-sc-acc-required-marked; safe no-op when no label exists.
        $snippet = '<script data-sc-acc-label-append="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';function findLabel(field){if(!field){return null;}var id=field.getAttribute?field.getAttribute("id"):null;if(id){var esc=(window.CSS&&CSS.escape)?CSS.escape(id):String(id).replace(/["\\\\]/g,"\\\\$&");var l=null;try{l=document.querySelector(\'label[for="\'+esc+\'"]\');}catch(e){l=null;}if(l){return l;}}if(field.closest){var w=field.closest("label");if(w){return w;}}return null;}function apply(){for(var i=0;i<items.length;i+=1){var item=items[i];var field=null;try{field=document.querySelector(item.selector);}catch(e){field=null;}if(!field){continue;}var label=findLabel(field);if(!label){continue;}if(label.getAttribute("data-sc-acc-required-marked")==="1"){continue;}var span=document.createElement("span");span.className="sc-acc-required-indicator";span.setAttribute("aria-hidden","true");span.textContent=item.text;label.appendChild(span);label.setAttribute("data-sc-acc-required-marked","1");}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }

        return $html . $snippet;
    }

    private function injectDocumentOverridesBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') {
            return $html;
        }

        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['type'] ?? '') !== 'document_override') {
                continue;
            }

            $itemPageKey = (string) ($item['pageKey'] ?? '');
            $documentTarget = trim((string) ($item['documentTarget'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));

            if (!$this->pageKeysMatch($itemPageKey, $currentPageKey, $currentPageRoute) || !in_array($documentTarget, ['title', 'lang'], true) || $value === '') {
                continue;
            }

            $items[] = [
                'documentTarget' => $documentTarget,
                'value' => $value,
            ];
        }

        $this->pluginApplyDebug('INJECT-DOCUMENT', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
            'targets' => array_map(static function ($it) {
                return ($it['documentTarget'] ?? '?') . '=' . ($it['value'] ?? '');
            }, $items),
        ]);

        if (count($items) === 0) {
            return $html;
        }

        // Server-side (2.0.0): title and html[lang] are page-global and always present in
        // the delivered document, so we set them directly in the HTML — no client script,
        // no fallback needed.
        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applyDocumentOverrides($html, $items);

        $this->pluginApplyDebug('INJECT-DOCUMENT-SERVER', [
            'appliedServerSide' => $mutation['report']['applied'] ?? 0,
        ]);

        return $mutation['html'];
    }

    /**
     * 2.2.0: tag_replace bootstrap. Finds the target element at runtime,
     * clones children + attributes onto a fresh element with the new tag
     * name, and replaces the original node. attributeOverrides are applied
     * on top. Idempotent via a data-sc-acc-tag-replaced marker.
     */
    private function injectTagReplaceBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') return $html;
        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'tag_replace') continue;
            if (!$this->pageKeysMatch((string) ($item['pageKey'] ?? ''), $currentPageKey, $currentPageRoute)) continue;

            $selector = trim((string) ($item['selector'] ?? ''));
            $fromTag = strtolower(trim((string) ($item['fromTag'] ?? '')));
            $toTag = strtolower(trim((string) ($item['toTag'] ?? '')));
            if ($selector === '' || $fromTag === '' || $toTag === '') continue;

            $overrides = [];
            $rawOverrides = $item['attributeOverrides'] ?? [];
            if (is_array($rawOverrides)) {
                foreach ($rawOverrides as $entry) {
                    if (!is_array($entry)) continue;
                    $attribute = trim((string) ($entry['attribute'] ?? ''));
                    if ($attribute === '') continue;
                    $overrides[] = ['attribute' => $attribute, 'value' => (string) ($entry['value'] ?? '')];
                }
            }

            // 1.0.1: inlineStyle is an optional, additive field. Carries a
            // compact CSS string (typography/color/margins) captured from the
            // origin element so the promoted tag does not lose theme styling
            // when theme CSS targets the old selector. Older payloads omit it
            // and the field stays empty — runtime behaves as before.
            $inlineStyle = trim((string) ($item['inlineStyle'] ?? ''));

            $items[] = [
                'selector' => $selector,
                'fromTag' => $fromTag,
                'toTag' => $toTag,
                'attributeOverrides' => $overrides,
                'inlineStyle' => $inlineStyle,
            ];
        }

        $this->pluginApplyDebug('INJECT-TAG-REPLACE', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
            'replacements' => array_map(static function ($it) {
                return ($it['selector'] ?? '') . ': ' . ($it['fromTag'] ?? '?') . '→' . ($it['toTag'] ?? '?');
            }, $items),
        ]);

        if (count($items) === 0) return $html;

        // Server-side first (2.0.0): promote matched tags directly in the delivered HTML
        // via the two-phase (resolve-then-mutate) engine, immune to nth-of-type drift.
        // Only tags absent from the server HTML (post-JS/SPA) fall back to the
        // observer-based client script below (tagged data-sc-acc-fallback="1").
        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applyTagReplace($html, $items);
        $html = $mutation['html'];
        $fallbackItems = $mutation['unappliedOps'];

        $this->pluginApplyDebug('INJECT-TAG-REPLACE-SERVER', [
            'itemsTotal' => count($items),
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) return $html;

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) return $html;

        // 1.0.2 — tag_replace acts on the FIRST matching node only, and crucially
        // remembers which items have already been applied across MutationObserver
        // re-runs. The previous version turned every <h2> into <h1> because:
        //  1) position-based selectors like `div.bp-container h2:nth-of-type(1)`
        //     can match multiple elements at apply time (each h2 is the first
        //     within its own parent), and
        //  2) the MutationObserver re-runs apply() after every DOM change — so
        //     after the first h2 is replaced, the "first match" of the same
        //     selector slides to the second h2 and gets replaced too.
        // The per-item `appliedIndex` map below guards against the second
        // class of bug: once an item has been applied, it is permanently
        // skipped within the IIFE's lifetime. Single-target semantics +
        // console.warn on multiple matches match element_replace's behaviour.
        //
        // 1.1.6 — resolve ALL targets first, THEN mutate (two phases). Mutating
        // as we iterate broke multi-cell tag_replace (table-header td->th): the
        // scanner emits `:nth-of-type(N)` selectors, and replacing td #1 with a
        // th shifts the nth-of-type count of the remaining <td>, so selector #2
        // hit the wrong cell and #3 matched nothing — only 2 of 3 cells applied.
        // Phase 1 collects concrete node references against the unmutated DOM
        // (with a `claimed` guard so two items never grab the same node); phase 2
        // performs the replacements, immune to nth-of-type drift.
        $snippet = '<script data-sc-acc-tag-replace="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';var appliedIndex={};function apply(){var pending=[];var claimed=[];for(var i=0;i<items.length;i+=1){if(appliedIndex[i])continue;var item=items[i];var nodes=[];try{nodes=document.querySelectorAll(item.selector);}catch(e){nodes=[];}if(nodes.length===0){if(window.__scAccDbg&&typeof console!=="undefined"&&console.warn){console.warn("[site-check] tag_replace selector matched no elements:",item.selector,item.fromTag+"->"+item.toTag);}continue;}if(nodes.length>1){if(window.__scAccDbg&&typeof console!=="undefined"&&console.warn){console.warn("[site-check] tag_replace selector matched "+nodes.length+" elements; only the first eligible match is replaced. Selector should be unique:",item.selector);}}var target=null;for(var j=0;j<nodes.length;j+=1){var candidate=nodes[j];if(!candidate)continue;if(candidate.getAttribute("data-sc-acc-tag-replaced")==="1")continue;if(String(candidate.tagName||"").toLowerCase()!==item.fromTag)continue;if(claimed.indexOf(candidate)!==-1)continue;target=candidate;break;}if(!target)continue;claimed.push(target);pending.push({index:i,item:item,target:target});}for(var p=0;p<pending.length;p+=1){var it=pending[p].item;var tg=pending[p].target;var repl=document.createElement(it.toTag);for(var a=0;a<tg.attributes.length;a+=1){var attr=tg.attributes[a];try{repl.setAttribute(attr.name,attr.value);}catch(e){}}while(tg.firstChild){repl.appendChild(tg.firstChild);}if(it.attributeOverrides){for(var k=0;k<it.attributeOverrides.length;k+=1){var ov=it.attributeOverrides[k];try{repl.setAttribute(ov.attribute,String(ov.value));}catch(e){}}}if(it.inlineStyle){try{var prev=repl.getAttribute("style")||"";var merged=prev?(prev.replace(/;\s*$/,"")+";"+it.inlineStyle):it.inlineStyle;repl.setAttribute("style",merged);}catch(e){}}repl.setAttribute("data-sc-acc-tag-replaced","1");if(tg.parentNode){tg.parentNode.replaceChild(repl,tg);appliedIndex[pending[p].index]=true;}}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}var mo=new MutationObserver(function(){apply();});try{mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }
        return $html . $snippet;
    }

    /**
     * 2.2.0: element_replace bootstrap. Replaces matched elements with
     * sanitized HTML (ul/ol/li). Idempotent via a data-sc-acc-element-replaced
     * marker on the replacement root.
     *
     * 1.1.7 — per-item `appliedIndex` guard (mirrors tag_replace). The
     * data-sc-acc-element-replaced marker only protected against re-replacing
     * the SAME (already-replaced) node, not against a DIFFERENT node sliding
     * into the same positional selector. info-list-markup-missing emits
     * `… > p:nth-of-type(N)`; once the target <p> becomes a <ul>, the next <p>
     * (e.g. an unrelated "Kontaktformular" paragraph) shifts into position N,
     * and the MutationObserver re-run replaced it with the SAME list. Once an
     * item has been applied it is now permanently skipped within the IIFE's
     * lifetime, so a second element can never be overwritten.
     */
    private function injectElementReplaceBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') return $html;
        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'element_replace') continue;
            if (!$this->pageKeysMatch((string) ($item['pageKey'] ?? ''), $currentPageKey, $currentPageRoute)) continue;

            $selector = trim((string) ($item['selector'] ?? ''));
            $sanitizedHtml = (string) ($item['sanitizedHtml'] ?? '');
            if ($selector === '' || $sanitizedHtml === '') continue;

            // Defensive re-sanitize at render time as well.
            $check = $this->sanitizeElementReplaceHtml($sanitizedHtml);
            if ($check['ok'] !== true) continue;

            $items[] = [
                'selector' => $selector,
                'html' => $check['html'],
            ];
        }

        $this->pluginApplyDebug('INJECT-ELEMENT-REPLACE', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
            'firstSelectors' => array_slice(array_map(static function ($it) {
                return ($it['selector'] ?? '') . ' (htmlLen=' . strlen((string) ($it['html'] ?? '')) . ')';
            }, $items), 0, 5),
        ]);

        if (count($items) === 0) return $html;

        // Server-side first (2.0.0): parse + replace the element directly in the delivered
        // HTML. Only targets absent from the server HTML (post-JS/SPA) fall back to the
        // observer script below (data-sc-acc-fallback="1").
        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applyElementReplace($html, $items);
        $html = $mutation['html'];
        $fallbackItems = $mutation['unappliedOps'];

        $this->pluginApplyDebug('INJECT-ELEMENT-REPLACE-SERVER', [
            'itemsTotal' => count($items),
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) return $html;

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) return $html;

        $snippet = '<script data-sc-acc-element-replace="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';var appliedIndex={};function apply(){for(var i=0;i<items.length;i+=1){if(appliedIndex[i])continue;var item=items[i];var nodes=[];try{nodes=document.querySelectorAll(item.selector);}catch(e){nodes=[];}if(nodes.length===0){if(window.__scAccDbg&&typeof console!=="undefined"&&console.warn){console.warn("[site-check] element_replace selector matched no elements:",item.selector);}continue;}if(nodes.length>1){if(window.__scAccDbg&&typeof console!=="undefined"&&console.warn){console.warn("[site-check] element_replace selector matched "+nodes.length+" elements; only the first is replaced. Selector should be unique:",item.selector);}}var target=nodes[0];if(!target||target.getAttribute("data-sc-acc-element-replaced")==="1")continue;var tpl=document.createElement("template");tpl.innerHTML=item.html;var frag=tpl.content;var root=frag.firstElementChild;if(!root)continue;root.setAttribute("data-sc-acc-element-replaced","1");if(target.parentNode){target.parentNode.replaceChild(frag,target);appliedIndex[i]=true;}}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}var mo=new MutationObserver(function(){apply();});try{mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }
        return $html . $snippet;
    }

    /**
     * 2.2.0: element_remove bootstrap. Deletes matched elements. Idempotent
     * because once removed there's nothing left to match on subsequent runs.
     */
    private function injectElementRemoveBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') return $html;
        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'element_remove') continue;
            if (!$this->pageKeysMatch((string) ($item['pageKey'] ?? ''), $currentPageKey, $currentPageRoute)) continue;

            $selector = trim((string) ($item['selector'] ?? ''));
            if ($selector === '') continue;

            $items[] = ['selector' => $selector];
        }

        $this->pluginApplyDebug('INJECT-ELEMENT-REMOVE', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
            'firstSelectors' => array_slice(array_map(static function ($it) {
                return (string) ($it['selector'] ?? '');
            }, $items), 0, 5),
        ]);

        if (count($items) === 0) return $html;

        // Server-side first (2.0.0): remove matched elements directly from the delivered
        // HTML. Only elements absent from the server HTML (post-JS/SPA) fall back to the
        // observer-based client script below (tagged data-sc-acc-fallback="1").
        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applyElementRemove($html, $items);
        $html = $mutation['html'];
        $fallbackItems = $mutation['unappliedOps'];

        $this->pluginApplyDebug('INJECT-ELEMENT-REMOVE-SERVER', [
            'itemsTotal' => count($items),
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) return $html;

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) return $html;

        $snippet = '<script data-sc-acc-element-remove="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';function apply(){for(var i=0;i<items.length;i+=1){var item=items[i];var nodes=[];try{nodes=document.querySelectorAll(item.selector);}catch(e){nodes=[];}for(var j=0;j<nodes.length;j+=1){var target=nodes[j];if(target&&target.parentNode){target.parentNode.removeChild(target);}}}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}var mo=new MutationObserver(function(){apply();});try{mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }
        return $html . $snippet;
    }

    /**
     * 1.4.0 WCAG-Phase-0: sr_only_text_insert bootstrap. Inserts
     * `<span class="sc-acc-sr-only" data-sc-acc-sr-only-id="…">text</span>`
     * client-side at the anchor position. Text is written via
     * createTextNode — NEVER as HTML. The visually-hidden CSS class is
     * defined exactly once per page via a <style data-sc-acc-sr-only-style>
     * tag the script creates on demand (standard visually-hidden pattern,
     * V0 contract: the insert must not change rendering). De-dup per item
     * via the data-sc-acc-sr-only-id attribute (= item ID), which also makes
     * the MutationObserver re-runs idempotent.
     */
    private function injectSrOnlyInsertBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') return $html;
        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'sr_only_text_insert') continue;

            $scope = $this->normalizeOverrideScope($item['scope'] ?? null);
            if ($scope === 'occurrence' && !$this->pageKeysMatch((string) ($item['pageKey'] ?? ''), $currentPageKey, $currentPageRoute)) continue;

            $id = trim((string) ($item['id'] ?? ''));
            $selector = trim((string) ($item['selector'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            if ($id === '' || $selector === '' || $text === '') continue;
            // Defensive re-validation at render time (fail-closed): text is
            // inserted as a text node, but reject markup-ish payloads so the
            // JSON embedded in this <script> can never carry `</script>`.
            if (mb_strlen($text) > self::SR_ONLY_TEXT_MAX_LENGTH || preg_match('/[<>]/', $text)) continue;

            $items[] = [
                'id' => $id,
                'selector' => $selector,
                'text' => $text,
                'position' => $this->normalizeSrOnlyInsertPosition($item['position'] ?? null),
            ];
        }

        $this->pluginApplyDebug('INJECT-SR-ONLY', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
            'firstSelectors' => array_slice(array_map(static function ($it) {
                return ($it['selector'] ?? '') . ' @' . ($it['position'] ?? '');
            }, $items), 0, 5),
        ]);

        if (count($items) === 0) return $html;

        // Server-side first (2.0.0): insert the visually-hidden sr-only span (and its style,
        // once) directly in the delivered HTML. Only anchors absent from the server HTML
        // (post-JS/SPA) fall back to the observer script below (data-sc-acc-fallback="1").
        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applySrOnlyInsert($html, $items);
        $html = $mutation['html'];
        $fallbackItems = $mutation['unappliedOps'];

        $this->pluginApplyDebug('INJECT-SR-ONLY-SERVER', [
            'itemsTotal' => count($items),
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) return $html;

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) return $html;

        $snippet = '<script data-sc-acc-sr-only-inserts="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';var CSS=".sc-acc-sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}";function ensureStyle(){if(document.querySelector("style[data-sc-acc-sr-only-style]")){return;}var style=document.createElement("style");style.setAttribute("data-sc-acc-sr-only-style","1");style.appendChild(document.createTextNode(CSS));(document.head||document.documentElement).appendChild(style);}function seenIds(){var map={};var nodes=document.querySelectorAll("[data-sc-acc-sr-only-id]");for(var i=0;i<nodes.length;i+=1){map[nodes[i].getAttribute("data-sc-acc-sr-only-id")]=true;}return map;}function apply(){ensureStyle();var seen=seenIds();for(var i=0;i<items.length;i+=1){var item=items[i];if(seen[item.id]){continue;}var nodes=[];try{nodes=document.querySelectorAll(item.selector);}catch(e){nodes=[];}if(nodes.length===0){if(window.__scAccDbg&&typeof console!=="undefined"&&console.warn){console.warn("[site-check] sr_only_text_insert selector matched no elements:",item.selector);}continue;}var target=nodes[0];var node=document.createElement("span");node.className="sc-acc-sr-only";node.setAttribute("data-sc-acc-sr-only-id",item.id);node.appendChild(document.createTextNode(item.text));if(item.position==="before"){if(target.parentNode){target.parentNode.insertBefore(node,target);seen[item.id]=true;}}else if(item.position==="after"){if(target.parentNode){target.parentNode.insertBefore(node,target.nextSibling);seen[item.id]=true;}}else{if(target.firstChild){target.insertBefore(node,target.firstChild);}else{target.appendChild(node);}seen[item.id]=true;}}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}var mo=new MutationObserver(function(){apply();});try{mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }
        return $html . $snippet;
    }

    /**
     * 1.4.0 WCAG-Phase-0: css_rule_inject bootstrap. Collects every
     * css_rule_inject item matching this page, re-validates the rules
     * fail-closed (mirrors the element_replace render-time re-sanitize) and
     * builds ONE CSS text server-side from the validated parts
     * (`selector{property:value}` per rule). The client script appends a
     * single <style data-sc-acc-css-inject> tag via createElement/
     * createTextNode — de-dup over that tag attribute. Deliberately a
     * client-side script bootstrap, NOT the server-side contrast
     * preg_replace path, for consistency with the script-bootstrap model.
     */
    private function injectCssRuleInjectBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') return $html;
        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $cssParts = [];
        $matchedItems = 0;
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'css_rule_inject') continue;

            $scope = $this->normalizeOverrideScope($item['scope'] ?? null);
            if ($scope === 'occurrence' && !$this->pageKeysMatch((string) ($item['pageKey'] ?? ''), $currentPageKey, $currentPageRoute)) continue;

            // Defensive re-validation at render time (fail-closed).
            $rulesCheck = $this->normalizeCssInjectRules($item['cssRules'] ?? null);
            if ($rulesCheck['ok'] !== true) continue;

            $matchedItems += 1;
            foreach ($rulesCheck['rules'] as $rule) {
                $cssParts[] = $rule['selector'] . '{' . $rule['property'] . ':' . $rule['value'] . '}';
            }
        }

        $this->pluginApplyDebug('INJECT-CSS-RULE', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => $matchedItems,
            'ruleCount' => count($cssParts),
            'firstRules' => array_slice($cssParts, 0, 5),
        ]);

        if (count($cssParts) === 0) return $html;

        $cssText = implode("\n", $cssParts);

        // Native server-side <style> (2.0.0): the CSS is already built and re-validated
        // above, so we place it straight into the delivered HTML instead of shipping it
        // through a client <script>. Neutralise the only token that can terminate a raw
        // <style> element ("</style"); validated CSS never contains it, and "<\/style" is
        // an equivalent, HTML-safe escape ("\/" resolves to "/" in CSS).
        $safeCss = str_ireplace('</style', '<\/style', $cssText);
        $styleTag = '<style data-sc-acc-css-inject="1">' . $safeCss . '</style>';

        if (stripos($html, '</head>') !== false) {
            return preg_replace('#</head>#i', $styleTag . '</head>', $html, 1) ?? ($html . $styleTag);
        }
        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $styleTag . '</body>', $html, 1) ?? ($html . $styleTag);
        }
        return $html . $styleTag;
    }

    /**
     * 1.4.0 WCAG-Phase-0: element_insert bootstrap. Parses the (doubly
     * validated) HTML snippet client-side via <template>, stamps
     * data-sc-acc-insert-id (= item ID) onto the first element child, checks
     * for an existing element with that ID before inserting (de-dup across
     * MutationObserver re-runs and SPA re-renders) and inserts via
     * insertAdjacentElement (before→beforebegin, after→afterend,
     * first_child→afterbegin, last_child→beforeend). Only the first element
     * child of the template is inserted — multi-root snippets are not part
     * of the §1.1 contract.
     */
    private function injectElementInsertBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') return $html;
        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'element_insert') continue;

            $scope = $this->normalizeOverrideScope($item['scope'] ?? null);
            if ($scope === 'occurrence' && !$this->pageKeysMatch((string) ($item['pageKey'] ?? ''), $currentPageKey, $currentPageRoute)) continue;

            $id = trim((string) ($item['id'] ?? ''));
            $selector = trim((string) ($item['selector'] ?? ''));
            $insertHtml = trim((string) ($item['html'] ?? ''));
            if ($id === '' || $selector === '' || $insertHtml === '') continue;

            // Defensive re-validation at render time (fail-closed) — the
            // whitelist also guarantees the JSON below can never contain a
            // `</script>` breakout (script is not an allowed tag name).
            $htmlCheck = $this->validateElementInsertHtml($insertHtml);
            if ($htmlCheck['ok'] !== true) continue;

            $items[] = [
                'id' => $id,
                'selector' => $selector,
                'html' => $insertHtml,
                'position' => $this->normalizeElementInsertPosition($item['position'] ?? null),
            ];
        }

        $this->pluginApplyDebug('INJECT-ELEMENT-INSERT', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
            'firstSelectors' => array_slice(array_map(static function ($it) {
                return ($it['selector'] ?? '') . ' @' . ($it['position'] ?? '') . ' (htmlLen=' . strlen((string) ($it['html'] ?? '')) . ')';
            }, $items), 0, 5),
        ]);

        if (count($items) === 0) return $html;

        // Server-side first (2.0.0): parse + insert the element directly in the delivered
        // HTML. Only anchors absent from the server HTML (post-JS/SPA) fall back to the
        // observer script below (data-sc-acc-fallback="1").
        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applyElementInsert($html, $items);
        $html = $mutation['html'];
        $fallbackItems = $mutation['unappliedOps'];

        $this->pluginApplyDebug('INJECT-ELEMENT-INSERT-SERVER', [
            'itemsTotal' => count($items),
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) return $html;

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) return $html;

        $snippet = '<script data-sc-acc-element-inserts="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';var POS={before:"beforebegin",after:"afterend",first_child:"afterbegin",last_child:"beforeend"};function seenIds(){var map={};var nodes=document.querySelectorAll("[data-sc-acc-insert-id]");for(var i=0;i<nodes.length;i+=1){map[nodes[i].getAttribute("data-sc-acc-insert-id")]=true;}return map;}function apply(){var seen=seenIds();for(var i=0;i<items.length;i+=1){var item=items[i];if(seen[item.id]){continue;}var nodes=[];try{nodes=document.querySelectorAll(item.selector);}catch(e){nodes=[];}if(nodes.length===0){if(window.__scAccDbg&&typeof console!=="undefined"&&console.warn){console.warn("[site-check] element_insert selector matched no elements:",item.selector);}continue;}var target=nodes[0];var tpl=document.createElement("template");tpl.innerHTML=item.html;var root=tpl.content?tpl.content.firstElementChild:null;if(!root){continue;}root.setAttribute("data-sc-acc-insert-id",item.id);var pos=POS[item.position]||"beforebegin";try{target.insertAdjacentElement(pos,root);seen[item.id]=true;}catch(e){}}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}var mo=new MutationObserver(function(){apply();});try{mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }
        return $html . $snippet;
    }

    /**
     * 2.1.0 WCAG-Phase-2: element_reorder bootstrap. Server-side first
     * (ServerHtmlMutator::applyElementReorder moves the parent's direct children
     * into the target order, stamps data-sc-acc-reordered). Only parents absent
     * from the server HTML (post-JS/SPA) fall back to the observer script.
     */
    private function injectElementReorderBootstrap(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') return $html;
        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $items = [];
        foreach ($store as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') !== 'element_reorder') continue;

            $scope = $this->normalizeOverrideScope($item['scope'] ?? null);
            if ($scope === 'occurrence' && !$this->pageKeysMatch((string) ($item['pageKey'] ?? ''), $currentPageKey, $currentPageRoute)) continue;

            $parentSelector = trim((string) ($item['parentSelector'] ?? $item['selector'] ?? ''));
            $childSelectors = $this->normalizeReorderChildSelectors($item['orderedChildSelectors'] ?? null);
            if ($parentSelector === '' || count($childSelectors) < 2) continue;

            $items[] = [
                'parentSelector' => $parentSelector,
                'orderedChildSelectors' => $childSelectors,
            ];
        }

        $this->pluginApplyDebug('INJECT-ELEMENT-REORDER', [
            'currentPageKey' => $currentPageKey,
            'matchedItems' => count($items),
        ]);

        if (count($items) === 0) return $html;

        $mutation = (new \Grav\Plugin\SiteCheckAccessibility\ServerHtmlMutator())->applyElementReorder($html, $items);
        $html = $mutation['html'];
        $fallbackItems = $mutation['unappliedOps'];

        $this->pluginApplyDebug('INJECT-ELEMENT-REORDER-SERVER', [
            'itemsTotal' => count($items),
            'fallbackToClient' => count($fallbackItems),
        ]);

        if (count($fallbackItems) === 0) return $html;

        $json = json_encode($fallbackItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($json)) return $html;

        $snippet = '<script data-sc-acc-element-reorders="1" data-sc-acc-fallback="1">(function(){var items=' . $json . ';function apply(){for(var i=0;i<items.length;i+=1){var item=items[i];var parent=null;try{parent=document.querySelector(item.parentSelector);}catch(e){parent=null;}if(!parent||parent.getAttribute("data-sc-acc-reordered")==="1"){continue;}var sels=item.orderedChildSelectors||[];var ordered=[];var ok=true;for(var j=0;j<sels.length;j+=1){var node=null;try{node=document.querySelector(sels[j]);}catch(e){node=null;}if(!node||node.parentNode!==parent||ordered.indexOf(node)!==-1){ok=false;break;}ordered.push(node);}if(!ok||ordered.length<2){continue;}var last=ordered[0];for(var k=1;k<ordered.length;k+=1){if(ordered[k].compareDocumentPosition(last)&Node.DOCUMENT_POSITION_PRECEDING){last=ordered[k];}}var ref=last.nextSibling;for(var m=0;m<ordered.length;m+=1){if(ordered[m].parentNode){ordered[m].parentNode.removeChild(ordered[m]);}}for(var n=0;n<ordered.length;n+=1){parent.insertBefore(ordered[n],ref);}parent.setAttribute("data-sc-acc-reordered","1");}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}else{apply();}var mo=new MutationObserver(function(){apply();});try{mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }
        return $html . $snippet;
    }

    private function getCurrentPageUrl(): string
    {
        $page = $this->grav['page'] ?? null;

        if (is_object($page) && method_exists($page, 'url')) {
            return (string) $page->url(true);
        }

        return rtrim((string) $this->grav['uri']->rootUrl(true), '/');
    }

    private function resolveUrlAgainstPage(string $value, string $pageUrl): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        $siteBase = rtrim((string) $this->grav['uri']->rootUrl(true), '/');

        if (str_starts_with($value, '//')) {
            $scheme = parse_url($siteBase, PHP_URL_SCHEME) ?: 'http';
            return $scheme . ':' . $value;
        }

        if (str_starts_with($value, '/')) {
            return $siteBase . $value;
        }

        $parts = parse_url($pageUrl);
        if (!is_array($parts)) {
            return $siteBase . '/' . ltrim($value, '/');
        }

        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';

        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        if ($dir === '.' || $dir === '\\') {
            $dir = '';
        }

        return $scheme . '://' . $host . $port . $dir . '/' . ltrim($value, '/');
    }

    /**
     * Plausibility check to reject placeholder selectors leaking from the
     * webapp (e.g. the literal string "element" that the fix-engine used to
     * pass through for form clarity codes). A real CSS selector always starts
     * with a character that CSS syntax allows at the start of a compound
     * selector: # (id), . (class), [ (attribute), : (pseudo), * (universal),
     * or a letter (tag / custom element).
     */
    /**
     * Pre-flight loopback fetch of a Grav-rendered page for DOM-based fix
     * target validation. Shares a per-batch cache so N fixes on the same
     * page cost one GET. Returns null on transport failure — callers MUST
     * treat null as "inconclusive" and not block the apply, so a hiccupy
     * loopback never regresses a fix that would land correctly.
     */
    private function fetchHtmlForPreflight(string $pageUrl): ?string
    {
        $pageUrl = trim($pageUrl);
        if ($pageUrl === '') {
            return null;
        }

        // SSRF guard: only ever fetch pages that live on this very site. The URL
        // comes from the authenticated apply payload, but we still refuse any
        // off-site host (and disable redirect-following below) so it can never be
        // turned into a server-side request to an internal or foreign host.
        $stripWww = static function ($h) { return preg_replace('/^www\./i', '', strtolower((string) $h)); };
        $selfHost = $stripWww($this->grav['uri']->host());
        $targetHost = $stripWww(parse_url($pageUrl, PHP_URL_HOST));
        if ($selfHost === '' || $targetHost === '' || $targetHost !== $selfHost) {
            return null;
        }

        // Only http(s) — blocks file://, gopher://, etc. slipping past the host gate.
        $scheme = strtolower((string) parse_url($pageUrl, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        if (array_key_exists($pageUrl, $this->preflightHtmlCache)) {
            $cached = $this->preflightHtmlCache[$pageUrl];
            return $cached !== '' ? $cached : null;
        }

        $html = null;
        $timeoutSeconds = 6;

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($pageUrl);
                if ($ch !== false) {
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
                    if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                    }
                    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(3, $timeoutSeconds));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Accept: text/html,application/xhtml+xml',
                        'User-Agent: sw-acc-connector-preflight/' . self::PLUGIN_VERSION,
                    ]);
                    $body = curl_exec($ch);
                    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if (is_string($body) && $body !== '' && $status >= 200 && $status < 400) {
                        $html = $body;
                    }
                }
            } else {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => $timeoutSeconds,
                        'ignore_errors' => true,
                        'follow_location' => 0,
                        'max_redirects' => 0,
                        'header' => "Accept: text/html,application/xhtml+xml\r\nUser-Agent: sw-acc-connector-preflight/" . self::PLUGIN_VERSION . "\r\n",
                    ],
                ]);
                $body = @file_get_contents($pageUrl, false, $context);
                if (is_string($body) && $body !== '') {
                    $html = $body;
                }
            }
        } catch (\Throwable $e) {
            $html = null;
        }

        $this->preflightHtmlCache[$pageUrl] = $html ?? '';
        return $html;
    }

    /**
     * Check whether any <img> on the rendered page has a src whose
     * normalized path matches the stored imageKey. This mirrors the JS
     * matchesImageKey check in the bootstrap exactly — if no img matches
     * server-side, the bootstrap will silently miss too and the override
     * is a no-op. Returns null when the fetch failed (inconclusive).
     */
    private function preflightAltTargetExists(string $pageUrl, string $imageKey): ?bool
    {
        if ($imageKey === '') {
            return null;
        }
        $html = $this->fetchHtmlForPreflight($pageUrl);
        if (!is_string($html) || $html === '') {
            return null;
        }
        if (!class_exists(\DOMDocument::class)) {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if ($loaded === false) {
            return null;
        }

        $images = $dom->getElementsByTagName('img');
        foreach ($images as $img) {
            $src = trim((string) $img->getAttribute('src'));
            if ($src === '') {
                continue;
            }
            $absolute = $this->resolveUrlAgainstPage($src, $pageUrl);
            $candidateKey = $this->normalizeImageKey($absolute);
            if ($candidateKey !== '' && $candidateKey === $imageKey) {
                return true;
            }
        }
        return false;
    }

    /**
     * Best-effort check whether the given CSS selector matches at least
     * one element on the rendered page. Uses a minimal CSS→XPath converter
     * that handles the selector shapes the scanner produces in practice
     * (tag, #id, .class, [attr], [attr="value"], combined). Returns:
     *   true  — matched at least one element
     *   false — converter was able to understand the selector and nothing
     *           matched (strong signal the apply would be a no-op)
     *   null  — fetch failed, HTML did not parse, or the selector uses
     *           features the minimal converter cannot express. Callers
     *           MUST treat null as "inconclusive" and not block.
     */
    private function preflightSelectorExists(string $pageUrl, string $selector): ?bool
    {
        if ($selector === '') {
            return null;
        }
        $xpath = $this->cssSelectorToXPath($selector);
        if ($xpath === null) {
            return null;
        }
        $html = $this->fetchHtmlForPreflight($pageUrl);
        if (!is_string($html) || $html === '') {
            return null;
        }
        if (!class_exists(\DOMDocument::class) || !class_exists(\DOMXPath::class)) {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if ($loaded === false) {
            return null;
        }

        try {
            $xp = new \DOMXPath($dom);
            $nodes = @$xp->query($xpath);
            if ($nodes === false) {
                return null;
            }
            return $nodes->length > 0;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Minimal CSS→XPath converter for the common scanner selector shapes.
     *
     * Supported:
     *   tag / *
     *   #id / .class (on their own or attached to a tag)
     *   [attr] / [attr="value"] / [attr='value']
     *   combinations of the above on a single simple selector
     *
     * Anything else (descendant combinators, pseudo-classes, nth-child,
     * attribute operators other than =) returns null so the caller treats
     * the check as inconclusive and lets the apply proceed. This is
     * intentional: the pre-flight is a defensive net, not a full selector
     * engine. Unrecognised selectors fall through to the runtime JS path
     * where the browser evaluates the full selector correctly.
     */
    private function cssSelectorToXPath(string $selector): ?string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return null;
        }
        // Reject anything that implies descendants, combinators, pseudo
        // classes, or unsupported attribute operators.
        if (preg_match('/[\s>+~:,]/', $selector)) {
            return null;
        }
        // Extract tag prefix if present (letter-led token).
        $tag = '*';
        $rest = $selector;
        if ($rest !== '' && $rest[0] === '*') {
            $tag = '*';
            $rest = substr($rest, 1);
        } elseif ($rest !== '' && ctype_alpha($rest[0])) {
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)/', $rest, $m)) {
                $tag = strtolower($m[1]);
                $rest = substr($rest, strlen($m[1]));
            }
        }

        $predicates = [];
        while ($rest !== '') {
            if ($rest[0] === '#') {
                if (!preg_match('/^#([a-zA-Z0-9_\-:.]+)/', $rest, $m)) {
                    return null;
                }
                $predicates[] = '@id=' . $this->xpathQuote($m[1]);
                $rest = substr($rest, strlen($m[0]));
            } elseif ($rest[0] === '.') {
                if (!preg_match('/^\.([a-zA-Z0-9_\-]+)/', $rest, $m)) {
                    return null;
                }
                $predicates[] = 'contains(concat(" ", normalize-space(@class), " "), ' . $this->xpathQuote(' ' . $m[1] . ' ') . ')';
                $rest = substr($rest, strlen($m[0]));
            } elseif ($rest[0] === '[') {
                // [attr] or [attr="value"] or [attr='value'] — no other operators.
                if (!preg_match('/^\[([a-zA-Z_][a-zA-Z0-9_\-:]*)(?:=(?:"([^"]*)"|\'([^\']*)\'))?\]/', $rest, $m)) {
                    return null;
                }
                $attr = $m[1];
                if (array_key_exists(2, $m) && $m[2] !== '') {
                    $predicates[] = '@' . $attr . '=' . $this->xpathQuote($m[2]);
                } elseif (array_key_exists(3, $m) && $m[3] !== '') {
                    $predicates[] = '@' . $attr . '=' . $this->xpathQuote($m[3]);
                } elseif (isset($m[2]) || isset($m[3])) {
                    // explicit empty value: [attr=""]
                    $predicates[] = '@' . $attr . '=""';
                } else {
                    $predicates[] = '@' . $attr;
                }
                $rest = substr($rest, strlen($m[0]));
            } else {
                return null;
            }
        }

        $xpath = '//' . $tag;
        if (count($predicates) > 0) {
            $xpath .= '[' . implode(' and ', $predicates) . ']';
        }
        return $xpath;
    }

    /**
     * Quote a string for XPath 1.0, which has no escape sequences. Mirrors
     * the common concat() trick when the literal contains both quote types.
     */
    private function xpathQuote(string $value): string
    {
        if (strpos($value, "'") === false) {
            return "'" . $value . "'";
        }
        if (strpos($value, '"') === false) {
            return '"' . $value . '"';
        }
        $parts = explode("'", $value);
        return 'concat(' . implode(", \"'\", ", array_map(static function ($p) {
            return "'" . $p . "'";
        }, $parts)) . ')';
    }

    private function isPlausibleSelector(string $selector): bool
    {
        if ($selector === '') {
            return false;
        }
        $first = $selector[0];
        return $first === '#'
            || $first === '.'
            || $first === '['
            || $first === ':'
            || $first === '*'
            || ctype_alpha($first);
    }

    /**
     * Resolve a normalized pageKey to a Grav page route. Returns the page's
     * canonical route (e.g. '/home') or null if no Grav page is found at
     * that path. Used to collapse URL aliases (e.g. root vs /home for the
     * configured home alias) to a single identity for pageKey matching.
     */
    private function resolvePageRoute(string $pageKey): ?string
    {
        if ($pageKey === '') {
            return null;
        }

        $path = parse_url($pageKey, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        // Strip Grav base-url prefix if present.
        $baseUrl = (string) $this->grav['uri']->rootUrl(false);
        if ($baseUrl !== '' && str_starts_with($path, $baseUrl)) {
            $path = substr($path, strlen($baseUrl));
            if ($path === '') {
                $path = '/';
            }
        }

        try {
            $pages = $this->grav['pages'] ?? null;
            if (!is_object($pages) || !method_exists($pages, 'dispatch')) {
                return null;
            }
            $page = $pages->dispatch($path, false);
            if (!is_object($page) || !method_exists($page, 'route')) {
                return null;
            }
            $route = (string) $page->route();
            return $route !== '' ? $route : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Match a stored pageKey against the current page. Exact string match
     * wins fast; otherwise resolve both sides to Grav page routes so URL
     * aliases (root / vs /home, trailing slash, index variants) collapse to
     * a single identity.
     */
    private function pageKeysMatch(string $storedPageKey, string $currentPageKey, ?string $currentPageRoute): bool
    {
        if ($storedPageKey === '') {
            return false;
        }
        if ($storedPageKey === $currentPageKey) {
            return true;
        }
        if ($currentPageRoute === null) {
            return false;
        }
        $storedRoute = $this->resolvePageRoute($storedPageKey);
        return $storedRoute !== null && $storedRoute === $currentPageRoute;
    }

    private function normalizePageKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if ($path === '') {
            $path = '/';
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        return $scheme . '://' . $host . $port . $path;
    }

    private function normalizeImageKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');

        if ($path === '') {
            return '';
        }

        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return $path;
    }



    private function injectContrastOverridesIntoHtml(string $html, string $currentPageUrl, array $store): string
    {
        $currentPageKey = $this->normalizePageKey($currentPageUrl);
        if ($currentPageKey === '') {
            return $html;
        }

        $currentPageRoute = $this->resolvePageRoute($currentPageKey);

        $rules = [];
        foreach ($store as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? '');
            if ($type !== 'contrast_adjustment' && $type !== 'css_declaration_set') {
                continue;
            }

            $itemPageKey = (string) ($item['pageKey'] ?? '');
            $selector = trim((string) ($item['selector'] ?? ''));

            if (!$this->pageKeysMatch($itemPageKey, $currentPageKey, $currentPageRoute) || $selector === '') {
                continue;
            }

            $pseudoClass = is_string($item['pseudoClass'] ?? null) ? $item['pseudoClass'] : null;

            // Phase 0.1: declarations[] is the preferred shape; fall back to
            // the legacy single property/value pair so existing stored
            // overrides keep rendering after the plugin upgrade.
            $declarations = [];
            if (isset($item['declarations']) && is_array($item['declarations'])) {
                foreach ($item['declarations'] as $entry) {
                    if (!is_array($entry)) continue;
                    $property = trim((string) ($entry['property'] ?? ''));
                    $value = trim((string) ($entry['value'] ?? ''));
                    if ($property !== '' && $value !== '') {
                        $declarations[] = ['property' => $property, 'value' => $value];
                    }
                }
            }
            if (count($declarations) === 0) {
                $property = trim((string) ($item['cssProperty'] ?? ''));
                $value = trim((string) ($item['value'] ?? ''));
                if ($property !== '' && $value !== '') {
                    $declarations[] = ['property' => $property, 'value' => $value];
                }
            }
            if (count($declarations) === 0) {
                continue;
            }

            // contrast_adjustment: only color/background-color are safe to inject.
            if ($type === 'contrast_adjustment') {
                $declarations = array_values(array_filter($declarations, function ($entry) {
                    return in_array($entry['property'], ['color', 'background-color'], true);
                }));
                if (count($declarations) === 0) continue;
            }

            $ruleSelector = $selector . ($pseudoClass ? (':' . $pseudoClass) : '');
            $body = '';
            foreach ($declarations as $entry) {
                $body .= $entry['property'] . ': ' . $entry['value'] . ' !important; ';
            }
            $rules[] = $ruleSelector . ' { ' . trim($body) . ' }';
        }

        if (count($rules) === 0) {
            return $html;
        }

        $styleTag = '<style data-sc-acc-contrast-overrides="1">' . implode("
", $rules) . '</style>';

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $styleTag . '</head>', $html, 1) ?? ($html . $styleTag);
        }

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $styleTag . '</body>', $html, 1) ?? ($html . $styleTag);
        }

        return $html . $styleTag;
    }

    /**
     * Resolve the plugin's writable data directory, performing the one-time
     * rebrand migration on first use.
     *
     * The pre-rebrand data dir was user/data/sw-acc-connector; it holds the
     * pairing token (connection-state.json), the signed cache and the sync /
     * overlay state. Renaming the plugin must NOT drop an existing pairing, so
     * on first access we atomically move the legacy dir to the new
     * user/data/site-check-accessibility location — token, cache and state
     * survive, delivery resumes with no re-pairing. Idempotent and guarded.
     */
    private function getDataDir(): string
    {
        static $migrated = false;
        $dir = ROOT_DIR . '/user/data/site-check-accessibility';

        if (!$migrated) {
            $migrated = true;
            $legacy = ROOT_DIR . '/user/data/sw-acc-connector';
            if (!is_dir($dir) && is_dir($legacy)) {
                // Atomic same-filesystem move (rebrand migration).
                @rename($legacy, $dir);
            }
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Defense in depth: drop a deny-all so connection-state.json / cache.json
        // are never web-servable even if the host does not protect user/data/.
        static $hardened = false;
        if (!$hardened) {
            $hardened = true;
            $this->hardenDataDir($dir);
        }

        return $dir;
    }

    /**
     * Write deny-all drop files into the plugin data dir (Apache + IIS) so the
     * stored token and signed cache can never be fetched over HTTP. Best-effort;
     * only writes a file when it is missing.
     */
    private function hardenDataDir(string $dir): void
    {
        $drops = [
            '.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
            'index.html' => "",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
        ];
        foreach ($drops as $name => $contents) {
            $path = $dir . '/' . $name;
            if (!file_exists($path)) {
                @file_put_contents($path, $contents);
            }
        }
    }

    private function getSignedCacheFilePath(): string
    {
        return $this->getDataDir() . '/cache.json';
    }

    private function getSignedCacheStateFilePath(): string
    {
        return $this->getDataDir() . '/cache-state.json';
    }

    /**
     * Derive the raw 32-byte Ed25519 public key from a base64-encoded SPKI
     * DER blob. SPKI wraps the 32-byte key as a trailer after a fixed
     * 12-byte AlgorithmIdentifier prefix, so substr(-32) is safe.
     */
    private function getRawPublicKeyBytes(): ?string
    {
        $b64 = trim(self::SYNC_PUBLIC_KEY_B64);
        if ($b64 === '') return null;
        $der = base64_decode($b64, true);
        if (!is_string($der) || strlen($der) < 32) return null;
        return substr($der, -32);
    }

    private function verifyEnvelopeSignature(string $payloadBytes, string $signatureB64): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $this->pluginApplyDebug('VERIFY-DIAG', ['stage' => 'sodium_missing']);
            return false;
        }
        $sig = base64_decode($signatureB64, true);
        if (!is_string($sig) || strlen($sig) !== 64) {
            $this->pluginApplyDebug('VERIFY-DIAG', [
                'stage' => 'sig_decode_or_length',
                'sigB64Len' => strlen($signatureB64),
                'sigDecodedLen' => is_string($sig) ? strlen($sig) : -1,
            ]);
            return false;
        }
        $pub = $this->getRawPublicKeyBytes();
        if ($pub === null || strlen($pub) !== 32) {
            $this->pluginApplyDebug('VERIFY-DIAG', [
                'stage' => 'pub_extract',
                'pubLen' => $pub === null ? -1 : strlen($pub),
            ]);
            return false;
        }
        try {
            $ok = sodium_crypto_sign_verify_detached($sig, $payloadBytes, $pub);
            $this->pluginApplyDebug('VERIFY-DIAG', [
                'stage' => 'sodium_called',
                'result' => $ok,
                'payloadLen' => strlen($payloadBytes),
                'sigLen' => strlen($sig),
                'pubLen' => strlen($pub),
                'pubFirst4Hex' => bin2hex(substr($pub, 0, 4)),
                'pubLast4Hex' => bin2hex(substr($pub, -4)),
            ]);
            return $ok;
        } catch (\Throwable $e) {
            $this->pluginApplyDebug('VERIFY-DIAG', [
                'stage' => 'sodium_threw',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Load + verify cache.json. Returns the decoded payload when signature
     * and issuedAt / validUntil gates pass; null otherwise.
     */
    private function loadSignedCache(): ?array
    {
        $path = $this->getSignedCacheFilePath();
        if (!file_exists($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return null;
        $envelope = json_decode($raw, true);
        if (!is_array($envelope)) return null;

        $payloadB64 = (string) ($envelope['payloadB64'] ?? '');
        $signatureB64 = (string) ($envelope['signature'] ?? '');
        if ($payloadB64 === '' || $signatureB64 === '') return null;

        $payloadBytes = base64_decode($payloadB64, true);
        if (!is_string($payloadBytes)) return null;

        if (!$this->verifyEnvelopeSignature($payloadBytes, $signatureB64)) {
            return null;
        }

        $payload = json_decode($payloadBytes, true);
        if (!is_array($payload)) return null;

        $validUntilRaw = (string) ($payload['subscription']['validUntil'] ?? '');
        if ($validUntilRaw !== '') {
            $validUntilTs = strtotime($validUntilRaw);
            if ($validUntilTs !== false && $validUntilTs <= time()) {
                return null;
            }
        }

        // Monotonic issuedAt: a replayed older envelope must not overwrite a
        // newer one. The writer enforces this on the write path (below); the
        // reader also tolerates missing state (first-read).
        $state = $this->readSignedCacheState();
        $lastIssuedAt = $state['lastIssuedAt'] ?? null;
        $thisIssuedAt = (string) ($payload['issuedAt'] ?? '');
        if (is_string($lastIssuedAt) && $lastIssuedAt !== '' && $thisIssuedAt !== '' && strtotime($thisIssuedAt) < strtotime($lastIssuedAt)) {
            return null;
        }

        return $payload;
    }

    private function storeSignedCache(array $envelope, string $version, string $issuedAt): bool
    {
        $path = $this->getSignedCacheFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return false;
        if (file_put_contents($path, $json) === false) return false;

        $this->writeSignedCacheState([
            'lastVersion' => $version,
            'lastIssuedAt' => $issuedAt,
            'lastSuccessfulFetchAt' => gmdate('c'),
        ]);
        return true;
    }

    private function readSignedCacheState(): array
    {
        $path = $this->getSignedCacheStateFilePath();
        if (!file_exists($path)) return [];
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeSignedCacheState(array $state): void
    {
        $path = $this->getSignedCacheStateFilePath();
        $existing = $this->readSignedCacheState();
        $next = array_merge($existing, $state);
        $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($path, $json);
        }
    }

    private function isCacheStale(array $cachePayload): bool
    {
        $issuedAtRaw = (string) ($cachePayload['issuedAt'] ?? '');
        if ($issuedAtRaw === '') return true;
        $issuedAtTs = strtotime($issuedAtRaw);
        if ($issuedAtTs === false) return true;
        return (time() - $issuedAtTs) > self::CACHE_STALE_SECONDS;
    }

    /**
     * Grace period has expired = "webapp confirmed liveness in the past, but
     * has not been reachable for longer than CACHE_GRACE_SECONDS". A fresh
     * install that has NEVER successfully fetched is NOT grace-expired —
     * it's pre-bootstrap, which the caller (resolveCachedOverrides) treats
     * as "no overrides, fail-closed, do not crash" so first-connect works.
     */
    private function isGracePeriodExpired(): bool
    {
        $state = $this->readSignedCacheState();
        $lastFetch = (string) ($state['lastSuccessfulFetchAt'] ?? '');
        if ($lastFetch === '') return false;
        $ts = strtotime($lastFetch);
        if ($ts === false) return false;
        return (time() - $ts) > self::CACHE_GRACE_SECONDS;
    }

    /**
     * Lazy-pull: GET the webapp's /api/plugin-api/sync with the stored token
     * and If-None-Match for the last known version. On 200 the cache is
     * rewritten; on 304 we just touch lastSuccessfulFetchAt; on any error we
     * leave the cache untouched.
     */
    private function tryRefreshSignedCache(): void
    {
        $webappBaseUrl = $this->getWebappBaseUrl();
        $apiToken = $this->getStoredApiToken();
        if ($webappBaseUrl === '' || $apiToken === '') return;

        $state = $this->readSignedCacheState();
        $lastVersion = (string) ($state['lastVersion'] ?? '');

        $url = rtrim($webappBaseUrl, '/') . '/api/plugin-api/sync';
        $headers = [
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
        ];
        if ($lastVersion !== '') {
            $headers[] = 'If-None-Match: ' . $lastVersion;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $httpStatus = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $httpStatus = (int) $m[1];
                    break;
                }
            }
        }

        if ($httpStatus === 304) {
            $this->writeSignedCacheState(['lastSuccessfulFetchAt' => gmdate('c')]);
            return;
        }
        // Disconnect / deauthorization (401): the webapp no longer recognises
        // this token — the site was disconnected or deleted. Reconcile on the
        // FRONTEND refresh path too (previously only the admin healthcheck did
        // this), so the render path stops serving fixes on the very next
        // pageload instead of waiting out the 24h grace. clearStoredApiToken()
        // nulls the token in connection-state AND unlinks the signed cache, so
        // both the delivered fixes and the "connected" status reflect reality.
        if ($httpStatus === 401) {
            $this->clearStoredApiToken();
            return;
        }
        // Subscription lapsed / entitlement inactive (403). The pairing is still
        // valid — only the subscription is gone — so drop the signed cache to
        // stop delivering fixes, but KEEP the token so delivery resumes
        // automatically on the next refresh once the subscription is active
        // again (no re-pairing required).
        if ($httpStatus === 403) {
            $this->clearSignedCacheFiles();
            return;
        }
        if ($httpStatus !== 200 || !is_string($raw) || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) return;
        $envelope = $decoded['envelope'] ?? null;
        $version = (string) ($decoded['version'] ?? '');
        $issuedAt = (string) ($decoded['issuedAt'] ?? '');
        if (!is_array($envelope) || $version === '') return;

        // Verify BEFORE persisting so a forged 200 can never poison the cache.
        $payloadBytes = base64_decode((string) ($envelope['payloadB64'] ?? ''), true);
        $signatureB64 = (string) ($envelope['signature'] ?? '');
        if (!is_string($payloadBytes) || $signatureB64 === '') return;
        if (!$this->verifyEnvelopeSignature($payloadBytes, $signatureB64)) return;

        $this->storeSignedCache($envelope, $version, $issuedAt);
    }

    /**
     * POST /plugin/sw-acc-connector/clear-cache
     * Operator-triggered cache wipe. Deletes cache.json + cache-state.json
     * so the plugin delivers no overrides until the next /sync-push.
     * Auth-protected (same Bearer/_token mechanism as /sync-push). Triggered
     * from the webapp admin "Danger Zone" modal — never auto-fired.
     */
    private function handleClearCacheEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();

        $cachePath = $this->getSignedCacheFilePath();
        $statePath = $this->getSignedCacheStateFilePath();

        $deleted = [];
        foreach ([$cachePath, $statePath] as $path) {
            if (file_exists($path)) {
                if (@unlink($path)) {
                    $deleted[] = $path;
                }
            }
        }

        $this->pluginApplyDebug('CACHE-CLEAR', [
            'requestedBy' => 'webapp-admin',
            'deletedFiles' => $deleted,
            'cacheStillExists' => file_exists($cachePath),
            'stateStillExists' => file_exists($statePath),
        ]);

        $this->sendJson([
            'success' => true,
            'cleared' => count($deleted),
            'paths' => $deleted,
        ]);
    }

    /**
     * POST /plugin/sw-acc-connector/sync-push
     * Body: { envelope: { payloadB64, signature }, version, issuedAt }
     * Webapp calls this right after a successful apply so the plugin cache
     * is current before the next pageload — saves one lazy-pull roundtrip.
     */
    private function handleSyncPushEndpoint(): void
    {
        $this->assertPluginEnabled();
        $this->assertAuthorized();

        $body = $this->readJsonBody();
        $envelope = is_array($body['envelope'] ?? null) ? $body['envelope'] : null;
        $version = (string) ($body['version'] ?? '');
        $issuedAt = (string) ($body['issuedAt'] ?? '');

        $this->pluginApplyDebug('SYNC-PUSH-RECEIVED', [
            'hasEnvelope' => $envelope !== null,
            'version' => $version,
            'issuedAt' => $issuedAt,
            'payloadB64Len' => is_array($envelope) ? strlen((string) ($envelope['payloadB64'] ?? '')) : null,
        ]);

        if ($envelope === null || $version === '') {
            $this->pluginApplyDebug('SYNC-PUSH-REJECTED', ['reason' => 'envelope_or_version_missing']);
            $this->sendJson([
                'success' => false,
                'error' => 'envelope und version sind erforderlich',
            ], 400);
        }

        $payloadB64 = (string) ($envelope['payloadB64'] ?? '');
        $signatureB64 = (string) ($envelope['signature'] ?? '');
        $payloadBytes = base64_decode($payloadB64, true);
        if (!is_string($payloadBytes) || $signatureB64 === '') {
            $this->pluginApplyDebug('SYNC-PUSH-REJECTED', ['reason' => 'envelope_incomplete']);
            $this->sendJson([
                'success' => false,
                'error' => 'envelope ist unvollständig',
            ], 400);
        }

        if (!$this->verifyEnvelopeSignature($payloadBytes, $signatureB64)) {
            $this->pluginApplyDebug('SYNC-PUSH-REJECTED', ['reason' => 'signature_failed']);
            $this->sendJson([
                'success' => false,
                'error' => 'Signatur-Prüfung fehlgeschlagen',
            ], 400);
        }

        $payloadDecoded = json_decode($payloadBytes, true);
        $overrideCount = (is_array($payloadDecoded) && is_array($payloadDecoded['overrides'] ?? null))
            ? count($payloadDecoded['overrides'])
            : null;
        $overrideTypes = [];
        if (is_array($payloadDecoded) && is_array($payloadDecoded['overrides'] ?? null)) {
            foreach ($payloadDecoded['overrides'] as $ov) {
                if (!is_array($ov)) continue;
                $type = (string) ($ov['type'] ?? '?');
                $overrideTypes[$type] = ($overrideTypes[$type] ?? 0) + 1;
            }
        }
        $this->pluginApplyDebug('SYNC-PUSH-VERIFIED', [
            'version' => $version,
            'overrideCount' => $overrideCount,
            'overrideTypes' => $overrideTypes,
        ]);

        if (!$this->storeSignedCache($envelope, $version, $issuedAt)) {
            $this->pluginApplyDebug('SYNC-PUSH-REJECTED', ['reason' => 'cache_write_failed']);
            $this->sendJson([
                'success' => false,
                'error' => 'Cache konnte nicht geschrieben werden',
            ], 500);
        }

        $this->pluginApplyDebug('SYNC-PUSH-STORED', [
            'version' => $version,
            'issuedAt' => $issuedAt,
            'cachePath' => $this->getSignedCacheFilePath(),
        ]);

        $this->sendJson([
            'success' => true,
            'version' => $version,
            'issuedAt' => $issuedAt,
        ]);
    }

    /**
     * Inject the small sc-* CSS helper block when at least one override declares
     * requiresHelpers (e.g. complex-graphic adding an sc-sr-only span). The CSS
     * is tiny (<1 KB), shipped inline to avoid an extra HTTP roundtrip.
     *
     * Canonical contents live in assets/sc-helpers.css — this inline block must
     * stay in sync with that file.
     */
    private function injectScHelperStyles(string $html, array $overrides): string
    {
        $needsHelpers = false;
        foreach ($overrides as $entry) {
            if (!is_array($entry)) continue;
            $flags = $entry['requiresHelpers'] ?? null;
            if (is_array($flags) && in_array('sc-sr-only', $flags, true)) {
                $needsHelpers = true;
                break;
            }
        }

        if (!$needsHelpers) {
            return $html;
        }

        if (stripos($html, 'data-sc-acc-helpers="1"') !== false) {
            return $html;
        }

        $style =
            '<style data-sc-acc-helpers="1">' .
            '.sc-sr-only{position:absolute!important;width:1px!important;height:1px!important;' .
            'padding:0!important;margin:-1px!important;overflow:hidden!important;' .
            'clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}' .
            '.sc-sr-only-focusable:not(:focus):not(:focus-within){position:absolute!important;' .
            'width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;' .
            'overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;' .
            'border:0!important}' .
            '</style>';

        if (stripos($html, '</head>') !== false) {
            $result = preg_replace('/<\/head>/i', $style . '</head>', $html, 1);
            return is_string($result) ? $result : ($html . $style);
        }

        return $style . $html;
    }

    private function injectOverlayRuntimeBootstrap(string $html): string
    {
        $payload = $this->buildOverlayBootstrapPayload();

        if ($payload === null) {
            return $html;
        }

        if (stripos($html, 'data-sc-acc-overlay-runtime="1"') !== false) {
            return $html;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        if (!is_string($json)) {
            return $html;
        }

        $scriptSrc = htmlspecialchars(
            rtrim((string) $payload['webappBaseUrl'], '/') . '/api/overlay/runtime.js',
            ENT_QUOTES,
            'UTF-8'
        );

        $snippet =
            '<script>window.__SWACC_OVERLAY_BOOTSTRAP__=' . $json . ';</script>' .
            '<script src="' . $scriptSrc . '" defer data-sc-acc-overlay-runtime="1"></script>';

        if (stripos($html, '</body>') !== false) {
            $result = preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1);
            return is_string($result) ? $result : ($html . $snippet);
        }

        return $html . $snippet;
    }

    private function buildOverlayBootstrapPayload(): ?array
    {
        $stored = $this->readOverlayRuntimeConfig();
        $runtimeEnabled = (bool) $this->config->get('plugins.site-check-accessibility.overlay_runtime_enabled', true);
        $publicWebsiteId = trim((string) ($stored['publicWebsiteId'] ?? ''));

        if (!$runtimeEnabled || ($stored['enabled'] ?? false) !== true || $publicWebsiteId === '') {
            return null;
        }

        $payload = [
            'webappBaseUrl' => $this->getWebappBaseUrl(),
            'websiteId' => $publicWebsiteId,
        ];

        // Security: never ship the pairing bearer token into public page HTML.
        // This overlay bootstrap is emitted on every visitor-facing page, so the
        // payload is limited to non-secret identifiers (webappBaseUrl + the
        // public website id). The overlay heartbeat authenticates webapp-side via
        // the public website id, not via a token embedded in the page.
        return $payload;
    }

    private function getWebappBaseUrl(): string
    {
        // Configurable override (default: the canonical prod constant). Lets a
        // dev/staging install point the plugin at a local webapp so the
        // connection panel AND all outbound calls (sync, overlay runtime,
        // webapp-status) reflect the URL it is actually paired with, instead of
        // always showing/using prod (F3). Customers without the override keep
        // the prod default — no behaviour change.
        $override = trim((string) $this->config->get('plugins.site-check-accessibility.webapp_base_url', ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }
        return rtrim(self::WEBAPP_BASE_URL, '/');
    }

    /**
     * Vercel-Deployment-Protection-Bypass für Server-zu-Server-Calls.
     *
     * Standard-Vercel-Previews (und teilweise auch Dev-Aliase wie
     * dev.site-check.de) sind hinter einer SSO-Schranke versteckt.
     * Browser-User authentifizieren sich via Vercel-Session (Cookie),
     * Server-zu-Server-Calls aus diesem Plugin haben aber keinen
     * Session-Kontext. Vercel bietet dafür den Header
     *   x-vercel-protection-bypass: <secret>
     * Das Secret wird im Vercel-Dashboard generiert
     * (Settings → Deployment Protection → "Protection Bypass for
     * Automation"). Wenn der Header fehlt oder das Secret nicht passt,
     * blockt Vercel mit 401 vor dem Next.js-Routing.
     *
     * Resolver-Reihenfolge:
     *   1. ENV-Variable SWACC_VERCEL_BYPASS_SECRET
     *   2. Plugin-Config plugins.site-check-accessibility.vercel_bypass_secret
     *   3. leer → kein Header gesendet (Production hat keinen Schutz,
     *      braucht das auch nicht).
     */
    private function getVercelBypassSecret(): string
    {
        $env = trim((string) (getenv('SWACC_VERCEL_BYPASS_SECRET') ?: ''));
        if ($env !== '') return $env;

        try {
            $cfg = trim((string) ($this->config->get('plugins.site-check-accessibility.vercel_bypass_secret') ?? ''));
            if ($cfg !== '') return $cfg;
        } catch (\Throwable $ignored) {
            // Config not available — fall through.
        }

        return '';
    }

    private function getStoredApiToken(): string
    {
        $state = $this->readConnectionState();
        $token = trim((string) ($state['apiToken'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        return trim((string) $this->config->get('plugins.site-check-accessibility.api_token'));
    }

    private function writeStoredApiToken(string $apiToken): bool
    {
        $state = $this->readConnectionState();
        $state['apiToken'] = $apiToken;

        return $this->writeConnectionState($state) !== [];
    }

    private function clearStoredApiToken(): void
    {
        $state = $this->readConnectionState();
        $state['apiToken'] = null;
        $this->writeConnectionState($state);

        // 2.5.0 — once the pairing token is gone the plugin is no longer
        // authorized to deliver fixes, so the signed cache MUST go with it.
        // Without this, the reconciler (handleWebappStatusEndpoint) cleared the
        // token on a 401 / DISCONNECTED webapp status but left cache.json on
        // disk, so the render path kept serving the fixes until the 24h grace
        // expired. Idempotent — @unlink no-ops when the file is already gone.
        @unlink($this->getSignedCacheFilePath());
        @unlink($this->getSignedCacheStateFilePath());
    }

    /**
     * Delete the signed cache + cache-state WITHOUT touching the pairing token.
     * Used when the subscription lapses (sync → 403): stop delivering fixes but
     * keep the site paired, so delivery resumes automatically on the next
     * refresh once the subscription is active again. Idempotent.
     */
    private function clearSignedCacheFiles(): void
    {
        @unlink($this->getSignedCacheFilePath());
        @unlink($this->getSignedCacheStateFilePath());
    }

    private function readConnectionState(): array
    {
        $path = $this->getConnectionStateFilePath();

        if (!file_exists($path)) {
            return [
                'status' => self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => null,
                'pairingCode' => null,
                'pluginBaseUrl' => rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
                'connectedAt' => null,
                'lastPairingAttemptAt' => null,
                'lastError' => null,
                'apiToken' => null,
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'status' => self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => null,
                'pairingCode' => null,
                'pluginBaseUrl' => rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
                'connectedAt' => null,
                'lastPairingAttemptAt' => null,
                'lastError' => null,
                'apiToken' => null,
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'status' => self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => null,
                'pairingCode' => null,
                'pluginBaseUrl' => rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
                'connectedAt' => null,
                'lastPairingAttemptAt' => null,
                'lastError' => null,
                'apiToken' => null,
            ];
        }

        return [
            'status' => (string) ($decoded['status'] ?? self::CONNECT_STATE_NOT_CONNECTED),
            'websiteId' => $decoded['websiteId'] ?? null,
            'pairingCode' => $decoded['pairingCode'] ?? null,
            'pluginBaseUrl' => $decoded['pluginBaseUrl'] ?? rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
            'connectedAt' => $decoded['connectedAt'] ?? null,
            'lastPairingAttemptAt' => $decoded['lastPairingAttemptAt'] ?? null,
            'lastError' => $decoded['lastError'] ?? null,
            'apiToken' => $decoded['apiToken'] ?? null,
        ];
    }

    private function writeConnectionState(array $data): array
    {
        $current = $this->readConnectionState();
        $payload = [
            'status' => (string) ($data['status'] ?? $current['status'] ?? self::CONNECT_STATE_NOT_CONNECTED),
            'websiteId' => array_key_exists('websiteId', $data) ? $data['websiteId'] : ($current['websiteId'] ?? null),
            'pairingCode' => array_key_exists('pairingCode', $data) ? $data['pairingCode'] : ($current['pairingCode'] ?? null),
            'pluginBaseUrl' => (string) ($data['pluginBaseUrl'] ?? $current['pluginBaseUrl'] ?? rtrim((string) $this->grav['uri']->rootUrl(true), '/')),
            'connectedAt' => array_key_exists('connectedAt', $data) ? $data['connectedAt'] : ($current['connectedAt'] ?? null),
            'lastPairingAttemptAt' => array_key_exists('lastPairingAttemptAt', $data) ? $data['lastPairingAttemptAt'] : ($current['lastPairingAttemptAt'] ?? null),
            'lastError' => array_key_exists('lastError', $data) ? $data['lastError'] : ($current['lastError'] ?? null),
            'apiToken' => array_key_exists('apiToken', $data) ? $data['apiToken'] : ($current['apiToken'] ?? null),
            'updatedAt' => gmdate('c'),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return [];
        }

        if (file_put_contents($this->getConnectionStateFilePath(), $json) === false) {
            return [];
        }

        return $payload;
    }

    private function getConnectionStateFilePath(): string
    {
        return $this->getDataDir() . '/connection-state.json';
    }

    private function callWebappJson(string $path, array $options = []): array
    {
        $url = rtrim($this->getWebappBaseUrl(), '/') . '/' . ltrim($path, '/');
        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        $jsonPayload = $options['json'] ?? null;
        $timeoutSeconds = (int) ($options['timeout'] ?? 20);
        if ($timeoutSeconds < 1) {
            $timeoutSeconds = 1;
        }
        $extraHeaders = $options['headers'] ?? [];

        $headers = [
            'Accept: application/json',
        ];

        // Vercel-Deployment-Protection-Bypass für geschützte Preview-/
        // Dev-Domains (siehe getVercelBypassSecret()). Wenn kein Secret
        // gesetzt ist, wird der Header weggelassen — auf Production
        // (site-check.de) ist Schutz typischerweise aus, der Header
        // ist dort harmlos zu ignorieren, aber sparen wir uns einfach.
        $bypassSecret = $this->getVercelBypassSecret();
        if ($bypassSecret !== '') {
            $headers[] = 'x-vercel-protection-bypass: ' . $bypassSecret;
            // Set-Bypass-Cookie ist nur für nachfolgende Browser-Requests
            // relevant — Plugin-Calls sind one-shot, also bewusst NICHT
            // angefragt (vermeidet, dass Vercel überflüssige Cookies
            // zurückschickt, die wir eh wegwerfen).
        }

        if (is_array($extraHeaders)) {
            foreach ($extraHeaders as $extraHeader) {
                if (is_string($extraHeader) && $extraHeader !== '') {
                    $headers[] = $extraHeader;
                }
            }
        }
        $body = null;

        if ($jsonPayload !== null) {
            $body = json_encode($jsonPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($body)) {
                throw new \RuntimeException('JSON-Request an die Webapp konnte nicht serialisiert werden');
            }
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('cURL konnte nicht initialisiert werden');
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, max(1, $timeoutSeconds)));
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $responseBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false) {
                throw new \RuntimeException($error !== '' ? $error : 'Webapp-Anfrage fehlgeschlagen');
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'content' => $body ?? '',
                    'ignore_errors' => true,
                    'timeout' => $timeoutSeconds,
                ],
            ]);

            $responseBody = @file_get_contents($url, false, $context);
            $status = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                        $status = (int) $matches[1];
                        break;
                    }
                }
            }

            if ($responseBody === false) {
                throw new \RuntimeException('Webapp-Anfrage fehlgeschlagen');
            }
        }

        $decoded = json_decode((string) $responseBody, true);

        return [
            'status' => $status,
            'body' => (string) $responseBody,
            'json' => is_array($decoded) ? $decoded : [],
            'url' => $url,
            'method' => $method,
        ];
    }

    private function readJsonBody(): array
    {
        $rawBody = file_get_contents('php://input');

        if (!is_string($rawBody) || trim($rawBody) === '') {
            $this->sendJson([
                'success' => false,
                'error' => 'Leerer Request-Body',
            ], 400);
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            $this->sendJson([
                'success' => false,
                'error' => 'Ungültiges JSON',
            ], 400);
        }

        return $payload;
    }

    private function readSyncState(): array
    {
        $path = $this->getSyncStateFilePath();

        if (!file_exists($path)) {
            return [
                'lastApplyAt' => null,
                'lastRegisterAt' => null,
                'lastOverlaySyncAt' => null,
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'lastApplyAt' => null,
                'lastRegisterAt' => null,
                'lastOverlaySyncAt' => null,
            ];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [
            'lastApplyAt' => null,
            'lastRegisterAt' => null,
            'lastOverlaySyncAt' => null,
        ];
    }

    private function touchSyncState(string $key): void
    {
        $state = $this->readSyncState();
        $state[$key] = gmdate('c');

        file_put_contents(
            $this->getSyncStateFilePath(),
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function getSyncStateFilePath(): string
    {
        return $this->getDataDir() . '/sync-state.json';
    }

    private function readOverlayRuntimeConfig(): array
    {
        $path = $this->getOverlayRuntimeConfigFilePath();

        if (!file_exists($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeOverlayRuntimeConfig(array $config): bool
    {
        $payload = [
            'enabled' => ($config['enabled'] ?? false) === true,
            'publicWebsiteId' => trim((string) ($config['publicWebsiteId'] ?? '')),
            'updatedAt' => (string) ($config['updatedAt'] ?? gmdate('c')),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (!is_string($json)) {
            return false;
        }

        return file_put_contents($this->getOverlayRuntimeConfigFilePath(), $json) !== false;
    }

    private function getOverlayRuntimeConfigFilePath(): string
    {
        return $this->getDataDir() . '/overlay-runtime-config.json';
    }

    private function isPluginEnabled(): bool
    {
        return (bool) $this->config->get('plugins.site-check-accessibility.enabled');
    }

    private function assertPluginEnabled(): void
    {
        if (!$this->isPluginEnabled()) {
            $this->sendJson([
                'success' => false,
                'error' => 'Plugin ist deaktiviert',
            ], 403);
        }
    }

    /**
     * Gate the state-changing endpoints (connect / disconnect / set-overlay) and
     * the webapp-status read. They may be triggered either from the authenticated
     * admin panel (per-install secret in X-Sc-Acc-Nonce) or by the webapp over
     * M2M (Bearer / X-Sw-Api-Token) — e.g. the webapp calls /disconnect with the
     * bearer token. Accept either; an anonymous caller has neither, which closes
     * the unauthenticated-state-change hole while keeping both callers working.
     * (The /connect-status probe is intentionally left unauthenticated because
     * the webapp polls it without credentials as a reachability check.)
     */
    private function assertAdminOrToken(): void
    {
        $provided = trim((string) ($_SERVER['HTTP_X_SC_ACC_NONCE'] ?? ''));
        if ($provided !== '' && hash_equals($this->getAdminActionSecret(), $provided)) {
            return;
        }
        // Not the admin panel — fall back to the M2M bearer/token check (401 on fail).
        $this->assertAuthorized();
    }

    /**
     * Per-install secret backing the admin endpoint gate. Stored in the deny-all
     * protected plugin data dir and rendered only into the authenticated admin
     * panel. Deliberately NOT a Grav session nonce: these endpoints run in
     * onPluginsInitialized, before the session is reliably available, so a
     * session-based nonce cannot round-trip.
     */
    private function getAdminActionSecret(): string
    {
        $path = $this->getDataDir() . '/admin-secret';
        $secret = is_file($path) ? trim((string) @file_get_contents($path)) : '';
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            @file_put_contents($path, $secret);
        }
        return $secret;
    }

    private function assertAuthorized(): void
    {
        $expectedToken = $this->getStoredApiToken();

        if ($expectedToken === '') {
            $this->sendJson([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        // Header-based auth only. The X-Sw-Api-Token custom header survives even
        // on Apache + PHP-CGI/FastCGI (where the Authorization header is stripped),
        // so a token in the query string — which would leak into access/proxy
        // logs — is not needed and is no longer accepted.
        $customHeader = trim((string) ($_SERVER['HTTP_X_SW_API_TOKEN'] ?? ''));
        if ($customHeader !== '') {
            if (!hash_equals($expectedToken, $customHeader)) {
                $this->sendJson([
                    'success' => false,
                    'error' => 'Unauthorized',
                ], 401);
            }
            return;
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!is_string($authHeader) || stripos($authHeader, 'Bearer ') !== 0) {
            $this->sendJson([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }

        $token = trim(substr($authHeader, 7));

        if (!hash_equals($expectedToken, $token)) {
            $this->sendJson([
                'success' => false,
                'error' => 'Unauthorized',
            ], 401);
        }
    }


    private function getConnectionStatusLabel(string $status): string
    {
        switch ($status) {
            case self::CONNECT_STATE_CONNECTED:
                return 'Verbunden';
            case self::CONNECT_STATE_PAIRING_PENDING:
                return 'Verbindung läuft';
            case self::CONNECT_STATE_ERROR:
                return 'Fehler';
            case self::CONNECT_STATE_NOT_CONNECTED:
            default:
                return 'Nicht verbunden';
        }
    }

    public function buildAdminConnectPanelState(): array
    {
        // Only ever expose the admin action secret + connection metadata (and run
        // the outbound reconcile) inside the authenticated admin. This method is a
        // globally registered Twig function; if a frontend template were to call
        // it, an empty state keeps the secret out of public HTML.
        if (!$this->isAdmin()) {
            return [];
        }

        // Ask the webapp for the authoritative status before rendering the
        // admin panel, so a hard refresh already shows the truth instead of a
        // stale local "connected" state. Errors are tolerated — the JS-level
        // loadWebappStatus() call picks up the slack.
        $this->reconcileLocalStateWithWebapp();

        $state = $this->readConnectionState();
        $pluginBaseUrl = rtrim((string) $this->grav['uri']->rootUrl(true), '/');
        $status = (string) ($state['status'] ?? self::CONNECT_STATE_NOT_CONNECTED);

        return [
            'status' => $status,
            'statusLabel' => $this->getConnectionStatusLabel($status),
            'websiteId' => $state['websiteId'] ?? null,
            'pluginBaseUrl' => (string) ($state['pluginBaseUrl'] ?? $pluginBaseUrl),
            'connectedAt' => $state['connectedAt'] ?? null,
            'lastPairingAttemptAt' => $state['lastPairingAttemptAt'] ?? null,
            'lastError' => $state['lastError'] ?? null,
            'lastNotice' => $state['lastNotice'] ?? null,
            'hasStoredToken' => $this->getStoredApiToken() !== '',
            'webappBaseUrl' => $this->getWebappBaseUrl(),
            'pluginVersion' => self::PLUGIN_VERSION,
            'overlayRuntimeEnabled' => (bool) $this->config->get('plugins.site-check-accessibility.overlay_runtime_enabled', true),
            'adminConnectEndpoint' => $pluginBaseUrl . '/plugin/sw-acc-connector/connect',
            'adminDisconnectEndpoint' => $pluginBaseUrl . '/plugin/sw-acc-connector/disconnect',
            'adminStatusEndpoint' => $pluginBaseUrl . '/plugin/sw-acc-connector/connect-status',
            'adminWebappStatusEndpoint' => $pluginBaseUrl . '/plugin/sw-acc-connector/webapp-status',
            'adminOverlayEndpoint' => $pluginBaseUrl . '/plugin/sw-acc-connector/set-overlay',
            // CSRF/auth secret for the admin-driven endpoints. Rendered only inside
            // the authenticated admin; the panel JS returns it in X-Sc-Acc-Nonce.
            'csrfToken' => $this->getAdminActionSecret(),
            'csrfHeader' => 'X-Sc-Acc-Nonce',
            // Shared connector-UI kit (single source: .plugin/_shared/connector-ui/).
            // Injected inline so the admin panel stays self-contained (no extra asset request).
            'kitCss' => (string) @file_get_contents(__DIR__ . '/admin/themes/grav/templates/forms/fields/connectpanel/sc-connector.css'),
            'kitJs' => (string) @file_get_contents(__DIR__ . '/admin/themes/grav/templates/forms/fields/connectpanel/sc-connector.js'),
        ];
    }

    private function reconcileLocalStateWithWebapp(): void
    {
        $token = $this->getStoredApiToken();
        if ($token === '' || $this->getWebappBaseUrl() === '') {
            return;
        }

        try {
            $response = $this->callWebappJson('/api/connectors/grav/plugin-status', [
                'method' => 'GET',
                'headers' => ['Authorization: Bearer ' . $token],
                'timeout' => 3,
            ]);
        } catch (\Throwable $e) {
            return;
        }

        $status = (int) ($response['status'] ?? 0);
        $body = is_array($response['json'] ?? null) ? $response['json'] : [];

        if ($status === 401) {
            $this->clearStoredApiToken();
            $this->writeConnectionState([
                'status' => self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => null,
                'pairingCode' => null,
                'pluginBaseUrl' => rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
                'connectedAt' => null,
                'lastPairingAttemptAt' => gmdate('c'),
                'lastError' => 'Die Webapp hat die Verbindung beendet.',
            ]);
            return;
        }

        if ($status !== 200 || !(($body['success'] ?? false) === true)) {
            return;
        }

        $webappWebsite = is_array($body['data']['website'] ?? null) ? $body['data']['website'] : [];
        $webappStatus = (string) ($webappWebsite['status'] ?? '');

        if ($webappStatus === 'DISCONNECTED' || $webappStatus === 'DRAFT') {
            $this->clearStoredApiToken();
            $this->writeConnectionState([
                'status' => self::CONNECT_STATE_NOT_CONNECTED,
                'websiteId' => null,
                'pairingCode' => null,
                'pluginBaseUrl' => rtrim((string) $this->grav['uri']->rootUrl(true), '/'),
                'connectedAt' => null,
                'lastPairingAttemptAt' => gmdate('c'),
                'lastError' => 'Die Webapp meldet den Status ' . $webappStatus . '.',
            ]);
        }
    }

    private function sendJson(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
