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
