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
