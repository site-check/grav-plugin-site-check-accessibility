# Site-Check Accessibility (Grav)

Connects a [Grav](https://getgrav.org) website to the **Site-Check
Accessibility** web app so that accessibility improvements reviewed in the app
are applied directly to your live site — no manual CSS or markup editing.

> **DE:** Verbindet eine Grav-Website mit der **Site-Check Accessibility**-Webapp.
> Barrierefreiheits-Verbesserungen, die in der Webapp geprüft und freigegeben
> wurden, landen automatisch auf der Live-Seite — ohne manuelle Eingriffe im
> Code.

---

## Deutsch

### Was das Plugin macht

Nach dem Verbinden und Scannen deiner Website in der Webapp schreibt das Plugin
die freigegebenen Fixes **serverseitig direkt in das ausgelieferte HTML** (roher
HTTP-Response, kein Client-JavaScript nötig). Nur Elemente, die erst durch
JavaScript der Zielseite entstehen, werden über einen kleinen Client-Fallback
nachgezogen.

- **Bild-Alternativtexte**, ARIA-Label, Kontrast- und CSS-Korrekturen
- Struktur-Korrekturen (z. B. Überschriften-Ebenen, Listen, Beschriftungen)
- Optionales **Accessibility-Overlay** (Assistenzfunktion, kein Ersatz für echte
  Fixes)
- Geführte Website-Verbindung direkt im Grav-Admin

Alle Änderungen sind **nachvollziehbar und jederzeit widerrufbar**: Sie werden
nur ausgeliefert, solange eine aktive Verbindung **und** ein gültiges Abonnement
bestehen. Wird die Website getrennt oder das Abo beendet, entfernt das Plugin die
Fixes automatisch beim nächsten Seitenaufruf.

### Installation

1. Plugin nach `user/plugins/site-check-accessibility/` entpacken (oder über den
   Grav Package Manager installieren).
2. Im Grav-Admin unter **Plugins → Site-Check Accessibility** öffnen.
3. In der [Site-Check-Webapp](https://site-check.de) einen **Pairing-Code**
   erzeugen und im Plugin-Formular eintragen.
4. Website in der Webapp scannen, Fixes prüfen und freigeben — sie erscheinen
   automatisch auf der Live-Seite.

### Voraussetzungen

- Grav **1.7** oder **2.x** (getestet mit 2.2.2)
- PHP **8.0** oder neuer mit der `dom`-Erweiterung (Standard bei den meisten
  Hostern; Grav 2 setzt selbst PHP 8.3+ voraus)

### Konfiguration

Die gesamte Konfiguration läuft über das Connector-Panel unter **Plugins →
Site-Check Accessibility**:

- **Verbindung** — Pairing-Code aus der Webapp eintragen, Verbindungsstatus
  einsehen oder Verbindung trennen.
- **Assistenz-Overlay erlauben** — schaltet das optionale Overlay für die
  Website an/aus.
- **Plugin aktiviert** — globaler Schalter; deaktiviert Connector, Overlay und
  Runtime-Overrides.

### Hinter einem Reverse-Proxy

Endet HTTPS schon an einem vorgeschalteten Proxy (z. B. Docker mit Traefik oder
Caddy, nginx vor Apache, Load Balancer), kennt Grav seine öffentliche Adresse nur
aus den `X-Forwarded-*`-Headern des Proxys. **Grav 2 wertet diese Header ab Werk
nicht aus**, Grav 1.7 übernimmt `X-Forwarded-Proto` und `X-Forwarded-Port`. Grav
meldet sich dann als `http://…` oder mit dem internen Port, und das Verbinden
scheitert mit „pluginBaseUrl passt nicht zur erwarteten Website-URL“. Das Panel
nennt in diesem Fall beide Adressen.

Abhilfe in `user/config/system.yaml`, eine der beiden Varianten:

```yaml
# A: die öffentliche Adresse fest eintragen
custom_base_url: 'https://www.example.com'
```

```yaml
# B: die Header des Proxys übernehmen – nur, wenn der Proxy sie selbst setzt
# und überschreibt, sonst können Besucher sie fälschen
http_x_forwarded:
  protocol: true           # X-Forwarded-Proto
  port: true               # X-Forwarded-Port, falls der Proxy ihn sendet
reverse_proxy_setup: true  # nur bei internem Port wie :8080 ohne X-Forwarded-Port
```

Danach erneut verbinden.

### Datenschutz / Datenübertragung

Das Plugin kommuniziert ausschließlich mit der Site-Check-Webapp
(`https://site-check.de`), um Fixes und den Verbindungsstatus abzugleichen. Es
werden keine Inhalte an Dritte übermittelt. Details:
<https://site-check.de/datenschutz>.

---

## English

### What it does

Once your site is connected and scanned in the web app, the plugin writes the
approved fixes **server-side, straight into the delivered HTML** (raw HTTP
response, no client-side JavaScript required). Only elements created later by the
target site's own JavaScript fall back to a small client script.

- Image **alt text**, ARIA labels, contrast and CSS corrections
- Structural fixes (heading levels, lists, labels, …)
- Optional **accessibility overlay** (an assistive aid, not a replacement for
  real fixes)
- Guided site connection inside the Grav admin

Every change is **traceable and fully reversible**: fixes are only delivered
while an active connection **and** a valid subscription exist. Disconnecting the
site or ending the subscription removes the fixes automatically on the next page
load.

### Installation

1. Unpack the plugin into `user/plugins/site-check-accessibility/` (or install it
   through the Grav Package Manager).
2. In the Grav admin, open **Plugins → Site-Check Accessibility**.
3. Generate a **pairing code** in the [Site-Check web app](https://site-check.de)
   and paste it into the plugin form.
4. Scan your site in the web app, review and approve fixes — they appear on the
   live site automatically.

### Requirements

- Grav **1.7** or **2.x** (tested with 2.2.2)
- PHP **8.0** or newer with the `dom` extension (Grav 2 itself requires PHP 8.3+)

### Configuration

All configuration lives in the connector panel under **Plugins → Site-Check
Accessibility**:

- **Connection** — enter the pairing code from the web app, view the connection
  status, or disconnect.
- **Allow assistance overlay** — toggles the optional overlay for the site.
- **Plugin enabled** — global switch; turns off the connector, overlay and
  runtime overrides.

### Behind a reverse proxy

If HTTPS ends at a proxy in front of Grav (e.g. Docker with Traefik or Caddy,
nginx in front of Apache, a load balancer), Grav only learns its public address
from the proxy's `X-Forwarded-*` headers. **Grav 2 ignores these headers by
default**; Grav 1.7 applies `X-Forwarded-Proto` and `X-Forwarded-Port`. Grav then
reports itself as `http://…` or with the internal port, and connecting fails with
"pluginBaseUrl passt nicht zur erwarteten Website-URL". The panel names both
addresses in that case.

Fix it in `user/config/system.yaml` with one of these:

```yaml
# A: set the public address explicitly
custom_base_url: 'https://www.example.com'
```

```yaml
# B: apply the proxy's headers — only if the proxy sets and overwrites them,
# otherwise visitors can forge them
http_x_forwarded:
  protocol: true           # X-Forwarded-Proto
  port: true               # X-Forwarded-Port, if the proxy sends it
reverse_proxy_setup: true  # only for an internal port such as :8080 without X-Forwarded-Port
```

Then connect again.

---

## License

MIT. See [LICENSE](LICENSE).

The Site-Check web app and backend are a separate, proprietary service; this
connector plugin is the MIT-licensed client that runs on your Grav site.

## Credits

This plugin bundles the following open-source libraries:

- [masterminds/html5](https://github.com/Masterminds/html5-php) — spec-compliant
  HTML5 parser/serializer (MIT).
- [paragonie/sodium_compat](https://github.com/paragonie/sodium_compat) —
  pure-PHP Ed25519 polyfill (ISC).
