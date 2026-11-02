# v1.3.0
## 11/02/2026

1. [](#new)
    * **New fix type `element_wrap` (FA-138).** An invisible list around entries that share one parent: menu links without a list get a `<div role="list">` with each link in its own `<span role="listitem">`, and `li` elements standing directly in a `nav` get the wrapper and `role="listitem"` themselves. Wrapper and items carry `display:contents` and `data-sc-acc-wrapped="1"`, so nothing moves on screen; separators such as „|“ stay behind their link. Applied server-side into the delivered HTML, with a client fallback for menus the site's own JavaScript builds later. An entry that does not match exactly one element, or entries with different parents, leave the fix unapplied instead of wrapping something else; a page that is already wrapped is never wrapped twice. The healthcheck reports the new `element-wrap` feature.
    * **Media tracks (`media_track`).** New override type that delivers reviewed WebVTT captions and audio descriptions to `<video>` and `<audio>` elements. Three parts: the `.vtt` file is stored under the protected data directory when the signed cache is written (idempotent, unchanged files are not rewritten), the new route `/plugin/sw-acc-connector/media-track?file=…` serves it as `text/vtt`, and a `<track>` element is written into the media element server-side, behind any `<source>` children and before the fallback text, so the browser sees it while it builds the element. Captions are marked `default` unless the author already ships a default track; descriptions are not, because an audio description that starts talking unasked surprises everyone who does not need it. Idempotent via `data-sc-track-id`.
    * **Player runtime bootstrap.** Media elements that received a track are marked `data-sc-media`, and the player runtime (`/api/player/runtime.js`) is loaded only on pages that actually contain such an element. The runtime enhances the media, it does not replace it: the description toggle, the transcript and the adjustable captions come from it, but the captions themselves work in the browser's own controls without it.
    * **Healthcheck.** Reports the new `media-track` feature, so the webapp can tell whether a target site is on a version that can receive media tracks.

2. [](#improved)
    * Track file names and contents are validated fail-closed before they touch the disk: two path segments of UUID-like characters with a `.vtt` extension, a `WEBVTT` header, a 256 KB size cap and a rejection of any `<script>`, `<iframe>`, `<object>`, `<embed>` or `<style>` in the payload. The delivery route validates the name again and checks the resolved path against the storage directory.
    * A `<track>` is only offered for a file that is actually present. A track pointing at a missing file shows an empty caption entry in the player, which is worse than no entry at all.
    * Tracks are bound to the page they were reviewed for: the page key is derived from the item's `pageUrl` and matched fail-closed, so a track never lands on another page whose selector happens to match. The media handler also reports its counters (items seen, matched, skipped per reason) to the opt-in `apply-debug` log, like every other handler, so "the track does not arrive" is answerable without guesswork.

3. [](#bugfix)
    * **The page title is only ever the one in `head` (FA-136).** A title fix looked for the first `<title>` in the document. On a page without a title in `head` that was the `<title>` of an inline SVG (a logo), so the fix renamed the logo and the page still had no title. The plugin now writes only `head > title` and creates it there when it is missing.
    * **Replace and remove resolve against the unchanged page (FA-143).** A pseudo-list fix replaces one paragraph with a list and removes the others, all addressed by position (`p:nth-of-type(N)`). The removes ran after the replace and hit the paragraph after the list. Both are now resolved first and applied afterwards, and the client fallback for removals applies each item once.

> **Re-deployment required.** Without it the webapp can push media tracks, but nothing on the target page will use them.

> **Known limit.** The `<track>` elements come from the signed cache, like every other override. If that cache becomes invalid (signature check fails, `validUntil` passed, the webapp stays silent past the 24 h grace, or the subscription ends), the connector delivers no overrides at all, and the tracks disappear from the markup with them. The stored `.vtt` files stay on disk and remain reachable through the route, so the captions are not lost, but embedding them would then have to happen in the theme.

> Found in the site-check test lab (criterion 1.3.1). Re-deployment needed for the new fix type: the web app sends `element_wrap` only to sites on version 1.3.0 or later.

# v1.2.1
## 10/28/2026

1. [](#improved)
    * **Widget loader no longer holds the page.** The assistance widget's loader (`/api/overlay/runtime.js`) is embedded with `async` instead of `defer`. A deferred script holds the page's `DOMContentLoaded` until it has arrived from site-check.de, and with it every slider or gallery that only then shows its hero image (LCP). The loader now waits for the page's `load` event itself before it fetches the widget. Nothing else changes; older versions keep working.

> **Re-deployment recommended** for better page speed values. Without it the widget works as before.

# v1.2.0
## 10/19/2026

1. [](#new)
    * **Media tracks (`media_track`).** New override type that delivers reviewed WebVTT captions and audio descriptions to `<video>` and `<audio>` elements. Three parts: the `.vtt` file is stored under the protected data directory when the signed cache is written (idempotent, unchanged files are not rewritten), the new route `/plugin/sw-acc-connector/media-track?file=…` serves it as `text/vtt`, and a `<track>` element is written into the media element server-side, behind any `<source>` children and before the fallback text, so the browser sees it while it builds the element. Captions are marked `default` unless the author already ships a default track; descriptions are not, because an audio description that starts talking unasked surprises everyone who does not need it. Idempotent via `data-sc-track-id`.
    * **Player runtime bootstrap.** Media elements that received a track are marked `data-sc-media`, and the player runtime (`/api/player/runtime.js`) is loaded only on pages that actually contain such an element. The runtime enhances the media, it does not replace it: the description toggle, the transcript and the adjustable captions come from it, but the captions themselves work in the browser's own controls without it.
    * **Healthcheck.** Reports the new `media-track` feature, so the webapp can tell whether a target site is on a version that can receive media tracks.

2. [](#improved)
    * Track file names and contents are validated fail-closed before they touch the disk: two path segments of UUID-like characters with a `.vtt` extension, a `WEBVTT` header, a 256 KB size cap and a rejection of any `<script>`, `<iframe>`, `<object>`, `<embed>` or `<style>` in the payload. The delivery route validates the name again and checks the resolved path against the storage directory.
    * A `<track>` is only offered for a file that is actually present. A track pointing at a missing file shows an empty caption entry in the player, which is worse than no entry at all.
    * Tracks are bound to the page they were reviewed for: the page key is derived from the item's `pageUrl` and matched fail-closed, so a track never lands on another page whose selector happens to match. The media handler also reports its counters (items seen, matched, skipped per reason) to the opt-in `apply-debug` log, like every other handler, so "the track does not arrive" is answerable without guesswork.

> **Re-deployment required.** Without it the webapp can push media tracks, but nothing on the target page will use them.

> **Known limit.** The `<track>` elements come from the signed cache, like every other override. If that cache becomes invalid (signature check fails, `validUntil` passed, the webapp stays silent past the 24 h grace, or the subscription ends), the connector delivers no overrides at all, and the tracks disappear from the markup with them. The stored `.vtt` files stay on disk and remain reachable through the route, so the captions are not lost, but embedding them would then have to happen in the theme.

# v1.1.1
## 10/15/2026

1. [](#improved)
    * **"Assistance widget" instead of "overlay".** The connector panel, plugin description and README now call site-check's optional visitor aid the assistance widget ("Assistenz-Widget erlauben"). Only wording changes; settings, endpoints and behaviour stay the same.

# v1.1.0
## 10/10/2026

1. [](#new)
    * **Connect with one button.** The pairing-code field is gone. „Mit site-check verbinden“ asks the plugin (`POST /plugin/sw-acc-connector/connect-start`, admin only) for a URL to site-check.de with a one-time `state` (32 bytes, 60 minutes, only its hash is stored in the plugin data dir). site-check.de sends the browser back to the plugin page with the code and the `state`; the code is exchanged only if the `state` is the one the plugin issued. Afterwards the tab closes itself when site-check opened it. The return address follows the admin in use (classic admin or admin2, configured route). A legacy `?pairingCode=` link keeps working until 1.2.0, on an explicit click only.
    * **Public `GET /plugin/sw-acc-connector/presence`** reports plugin, version and `connectFlow: 2`, so site-check sees the plugin while the customer installs it.
    * New `lib/ConnectHandshake.php` with `tests/connect-handshake-test.php`.
    * **„site-check ist installiert.“** After installation the classic admin shows this message once, with a „Jetzt verbinden“ link to the plugin page; never when the site is already connected (marker `install-notice-shown` in the plugin data dir).
    * **„Zu site-check“ opens this website** in site-check (`/app/websites/{websiteId}?verbunden=1`) once the connection knows its website; `connect-status` and the panel state carry `websiteId`.
    * **Connect panel texts (P-01).** The registration hint is gone, the connect card reads „Verbinde diese Website mit site-check, damit Barrierefreiheits-Fehler automatisch behoben werden.“, the hero lead stays empty until connected, and the brand is written „site-check“ throughout the panel.
1. [](#bugfix)
    * Error messages from the web app sent as an object no longer show up as "Array".
    * **Customer texts when connecting fails.** A text sent by site-check with an error `code` is shown as it is. An expired or used connect attempt reads „Das hat zu lange gedauert. Klicke noch einmal auf „Mit site-check verbinden“.“; no answer, a timeout or an unreadable answer reads „site-check antwortet gerade nicht. Versuch es gleich noch einmal.“; internal problems read „Das hat nicht geklappt.“. Raw cURL/exception messages and the proxy/`custom_base_url` diagnosis go to the Grav log only, never to the panel or `lastError`.
    * **A failed register call no longer ends a working connection in an error.** After a successful exchange the plugin asks site-check once (`plugin-status`) and finishes as connected when site-check reports the website as connected.
1. [](#new)
    * **Several trusted signing keys.** Besides the current sync signing key, the plugin now has a slot for the next one. An envelope is accepted when it verifies with any trusted key, so the web app can rotate its signing key without a window in which sites refuse its pushes. While the slot is empty, only the current key is trusted, exactly as before.
    * **The healthcheck reports `connectorLineage` and `trustedSigningKeyIds`.** `connectorLineage` names the connector product line (`site-check`); `trustedSigningKeyIds` lists the ids of all trusted keys (first 16 hex chars of the SHA-256 over the key's SPKI DER), so the web app can see which sites already trust a new key before it switches.

# v1.0.4
## 10/08/2026

1. [](#bugfix)
    * **An older sync state can no longer replace the current one.** A validly signed but older envelope used to be accepted on `/sync-push`: the plugin stored the unsigned `issuedAt` from the request body as the reference, so the replayed state replaced the current overrides and was delivered from then on. The plugin now takes `issuedAt` from the signed payload and refuses an older one with `409`; the same check guards the background refresh.
    * **An envelope signed for another website is refused.** The signed `websiteId` must be the connected one (`409` otherwise), and a cache written for another website is never delivered. A site paired before the connection state carried a `websiteId` keeps working.
    * **Alt-text pre-flight checks what the delivery will do.** With an image URL and a selector, the delivery writes `alt` only at a node of the selector whose `src` is that image. The pre-flight only asked whether any `<img>` on the page carried it, so an alt fix on an image-map `<area>` was reported as applied and changed nothing. The pre-flight now asks the delivery's question and refuses such a fix.

> Found in the site-check test lab (criterion 1.1.1, apply and verify on a real Grav). Re-deployment recommended: the first two items close a replay gap for anyone holding the site's API token and an intercepted envelope.

# v1.0.3
## 09/29/2026

1. [](#new)
    * **Grav 2 support.** The pairing panel now also renders in Grav 2's admin (admin2) as a native field, so a Grav 2 site can connect, see its connection status and switch the overlay. Grav 1.7 keeps its classic admin panel. Declares GPM compatibility with Grav 1.7 and 2.0 again (covers Grav 2.x; tested with 2.2.2).
2. [](#improved)
    * When connecting fails because the web app does not recognise the site's address, the panel now names the address Grav reports. Behind a reverse proxy whose `X-Forwarded-*` headers Grav does not apply (the Grav 2 default), it also names the public address and the setting that fixes it: `custom_base_url` or `system.http_x_forwarded`. See the README.
3. [](#bugfix)
    * The page inventory the web app uses to count a site's published pages no longer fails with "Internal error" on every Grav version: it is now answered once Grav has loaded its pages.

# v1.0.2
## 08/17/2026

1. [](#bugfix)
    * Withdrew the declared GPM compatibility with **Grav 2.0**. The connector's runtime does work on Grav 2 (its own HTTP routes, the injector path and status detection were verified against 2.0.19), but the pairing panel does not render there: it is a `connectpanel` custom field exposed via `onAdminTwigTemplatePaths`, an event only the classic admin plugin fires, while Grav 2's admin2 loads custom fields as web components from `<plugin>/admin-next/fields/` and shows a raw JSON textarea for unknown types. A Grav 2 customer could install the plugin and then had no way to pair. Grav 1.7 is unaffected.
    * Existing Grav 2 installations keep working: `compatibility` is GPM metadata only and no runtime gate. Grav 2 support will return together with a native admin2 field.

# v1.0.1
## 08/04/2026

1. [](#improved)
    * Maintenance / store-consistency release — no change to pairing, fixes or overlay behaviour.
    * Unified the connector admin panel with the other Site-Check connectors (shared UI kit); cosmetic only.
    * Pairing response is now read from the canonical `connection.*` fields; removed a dead legacy parsing branch.
    * Normalised the PHP log label to `[site-check]` and set author/copyright metadata to "site-works AG".

# v1.0.0
## 08/03/2026

1. [](#new)
    * Initial public release of the **Site-Check Accessibility** connector for Grav.
    * Connects a Grav site to the Site-Check Accessibility web app through a guided pairing flow in the Grav admin (branded connector panel).
    * Applies approved accessibility fixes **server-side, straight into the delivered HTML** (raw HTTP response, no client-side JavaScript required): image alt text, ARIA labels, contrast and CSS corrections, and structural fixes (heading levels, lists, labels). Elements created later by the target site's own JavaScript fall back to a small client script.
    * Optional **accessibility overlay** (an assistive aid, not a replacement for real fixes), toggleable directly from the connector panel.
    * Fixes are signed (Ed25519) and cached with a staleness + grace window; they are delivered **only while an active connection and a valid subscription exist** and are removed automatically on disconnect or subscription end.
2. [](#improved)
    * **Fail-open** injector path: `onOutputGenerated` and the `ServerHtmlMutator` engine catch every error and serve the unmodified original page (never a 500); an `ext-dom` guard protects the structure-/selector-based paths.
    * Bundles a spec-compliant HTML5 parser/serializer (`masterminds/html5`, MIT) so modern HTML5 round-trips without corruption, and `paragonie/sodium_compat` (ISC) as an Ed25519 polyfill for hosts without the native sodium extension.
    * Declares GPM compatibility with both **Grav 1.7 and Grav 2.0**; verified against the stable plugin, event and Twig APIs used by the connector.
