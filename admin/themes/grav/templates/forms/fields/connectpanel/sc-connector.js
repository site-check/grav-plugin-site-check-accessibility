/* =========================================================================
   site-check Accessibility — Connector Admin Panel (JS)

   Transport-agnostic: reads its endpoints + state from data-* on the root
   [data-sc-panel] element and drives connect / disconnect / status-poll /
   overlay-toggle via fetch.

   Connecting (protocol v2, since 1.1.0, docs/connector-pairing.md):
   1. „Mit site-check verbinden“ asks the plugin for a start URL
      (connect-start: the plugin issues a one-time `state`) and goes there.
   2. site-check.de sends the browser back to this page with
      ?sitecheck_code=…&sitecheck_state=…; the kit posts both to the plugin's
      connect endpoint, which checks the state before exchanging the code.
   3. Connected: „Verbunden ✓“, and the tab closes itself when site-check
      opened it (the two-tab flow), otherwise „Zu site-check“.
   A legacy ?pairingCode= deep link from older webapps still works by click
   (transition until 1.2.0); it never connects on its own.

   Two boot paths:
   - Light DOM (Grav 1.7 Twig, WordPress, TYPO3, Joomla): the adapter renders the
     panel into the page and the kit picks it up itself on load.
   - Shadow DOM (the Grav 2 admin2 web component): a shadow root is invisible to
     document.querySelectorAll, so that host calls ScConnector.initPanel(root)
     with its own panel element once the markup is attached.
   ========================================================================= */
(function (global) {
  var RETURN_CODE_PARAM = 'sitecheck_code';
  var RETURN_STATE_PARAM = 'sitecheck_state';
  var LEGACY_CODE_PARAMS = ['pairingCode', 'pairing'];
  var CONNECTED_TAB_TITLE = '✓ Verbunden – site-check';
  var CLOSE_DELAY_MS = 1200;

  // TYPO3 (and other backends) render the module in an iframe of the same origin.
  // Navigating, the tab title and closing belong to the whole tab: site-check
  // refuses to be framed, so leaving the iframe alone showed a broken frame
  // (hand test 14.10.2026). A foreign or missing top falls back to this window.
  function tabWindow() {
    try {
      if (window.top && window.top !== window && window.top.location.origin === window.location.origin) {
        return window.top;
      }
    } catch (e) { /* cross-origin top */ }
    return window;
  }
  var STORED_ERROR_PREFIX = 'Letzter Versuch: ';

  function initPanel(panel) {
    if (!panel || panel.dataset.scInit === '1') { return; }
    panel.dataset.scInit = '1';

    var d = panel.dataset;
    var connectStartEndpoint = d.connectStartEndpoint || '';
    var connectEndpoint = d.connectEndpoint || '';
    var disconnectEndpoint = d.disconnectEndpoint || '';
    var statusEndpoint = d.statusEndpoint || '';
    var overlayEndpoint = d.overlayEndpoint || '';
    var webappStatusEndpoint = d.webappStatusEndpoint || '';
    var webappBaseUrl = (d.webappBaseUrl || '').replace(/\/+$/, '');
    var csrfToken = d.csrf || '';
    var csrfHeader = d.csrfHeader || '';

    var initialStatus = d.initialStatus || 'not_connected';
    var initialNotice = d.initialNotice || '';
    var initialError = d.initialError || '';

    var signal = panel.querySelector('[data-sc-signal]');
    var signalLabel = panel.querySelector('[data-sc-signal-label]');
    var heroLead = panel.querySelector('[data-sc-hero-lead]');
    var connectLead = panel.querySelector('[data-sc-connect-lead]');
    var connectBtn = panel.querySelector('[data-sc-connect]');
    var disconnectBtn = panel.querySelector('[data-sc-disconnect]');
    var refreshBtn = panel.querySelector('[data-sc-refresh]');
    var note = panel.querySelector('[data-sc-note]');
    var overlayToggle = panel.querySelector('[data-sc-overlay-toggle]');
    var dashboardLink = panel.querySelector('[data-sc-dashboard-link]');

    var params = readParams();
    var returnCode = params.get(RETURN_CODE_PARAM) || '';
    var returnState = params.get(RETURN_STATE_PARAM) || '';
    var legacyCode = '';
    for (var p = 0; p < LEGACY_CODE_PARAMS.length && !legacyCode; p++) { legacyCode = params.get(LEGACY_CODE_PARAMS[p]) || ''; }

    // Maps the four server statuses onto the three visual states of the kit.
    // Not connected, P-01 says it all in one sentence (L8): the hero stays quiet.
    var VIEW = {
      not_connected: {
        state: 'disconnected', tone: 'neutral', label: 'Nicht verbunden',
        lead: ''
      },
      pairing_pending: {
        state: 'disconnected', tone: 'neutral', label: 'Wird verbunden …',
        lead: ''
      },
      connected: {
        state: 'connected', tone: 'ok', label: 'Verbunden',
        lead: 'Geprüfte Fixes und das Assistenz-Widget werden ausgeliefert.'
      },
      error: {
        state: 'interrupted', tone: 'danger', label: 'Verbindung unterbrochen',
        lead: 'Aktuell werden keine Fixes ausgeliefert.'
      }
    };

    var LABEL_CONNECT = 'Mit site-check verbinden';
    var LABEL_RETRY = 'Noch einmal';
    var LEAD_CONNECT = 'Verbinde diese Website mit site-check, damit Barrierefreiheits-Fehler automatisch behoben werden.';
    var TEXT_BUSY = 'Wird verbunden …';
    var TEXT_FAILED = 'Das hat nicht geklappt.';

    function readParams() {
      try { return new URLSearchParams(window.location.search); } catch (e) { return { get: function () { return null; } }; }
    }

    /** Removes our one-time parameters so a reload does not post them again. */
    function cleanUrl() {
      try {
        var url = new URL(window.location.href);
        [RETURN_CODE_PARAM, RETURN_STATE_PARAM].concat(LEGACY_CODE_PARAMS).forEach(function (k) { url.searchParams.delete(k); });
        window.history.replaceState(window.history.state, '', url.toString());
      } catch (e) { /* history unavailable — harmless */ }
    }

    /* ---- status banner ------------------------------------------------- */
    function setNote(tone, text) {
      if (!note) { return; }
      if (!text) { note.className = 'scc-note'; note.textContent = ''; return; }
      note.className = 'scc-note scc-note--' + tone + ' is-on';
      note.textContent = text;
    }
    function setBusyNote(text) {
      if (!note) { return; }
      note.className = 'scc-note scc-note--info is-on';
      note.innerHTML = '<span class="scc-spinner"></span>' + escapeHtml(text);
    }
    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function setLoading(loading) {
      [connectBtn, disconnectBtn, refreshBtn].forEach(function (b) { if (b) { b.disabled = loading; } });
    }
    function setConnectLabel(text) { if (connectBtn) { connectBtn.textContent = text; } }

    /** P-03 „Zu site-check“ leads to this website's dashboard (W-50 on the first visit). */
    function setDashboardLink(websiteId) {
      if (!dashboardLink || !webappBaseUrl || !websiteId) { return; }
      dashboardLink.setAttribute('href', webappBaseUrl + '/app/websites/' + encodeURIComponent(websiteId) + '?verbunden=1');
    }

    /* ---- apply a status object to the whole panel --------------------- */
    function applyStatus(connection) {
      var status = (connection && connection.status) || 'not_connected';
      var v = VIEW[status] || VIEW.not_connected;
      panel.setAttribute('data-state', v.state);
      if (signal) { signal.setAttribute('data-tone', v.tone); }
      if (signalLabel) { signalLabel.textContent = v.label; }
      if (heroLead) { heroLead.textContent = v.lead; heroLead.hidden = !v.lead; }
      if (connection && connection.websiteId) { setDashboardLink(connection.websiteId); }
      if (connectLead) { connectLead.textContent = LEAD_CONNECT; }

      // A stored error is from an earlier attempt: after a reload it looked like
      // the answer to a click that never happened (hand test 14.10.2026).
      if (connection && connection.lastError) {
        setNote('err', STORED_ERROR_PREFIX + connection.lastError);
      } else if (connection && connection.lastNotice && status !== 'connected') {
        setNote('warn', connection.lastNotice);
      } else {
        setNote(null, '');
      }
    }

    /* ---- fetch helpers ------------------------------------------------- */
    function authHeaders(base) {
      var h = base || {};
      if (csrfToken && csrfHeader) { h[csrfHeader] = csrfToken; }
      return h;
    }
    async function parseJson(response) {
      var raw = await response.text();
      var data = {};
      try { data = raw ? JSON.parse(raw) : {}; } catch (e) { data = { __rawText: raw }; }
      return { data: data, raw: raw };
    }
    function errorMessage(result, fallback) {
      var e = result && result.error;
      if (e && typeof e === 'object' && typeof e.message === 'string') { return e.message; }
      if (typeof e === 'string' && e) { return e; }
      return fallback;
    }
    async function postJson(url, payload) {
      var response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: authHeaders({ 'Content-Type': 'application/json', Accept: 'application/json' }),
        body: JSON.stringify(payload || {})
      });
      var parsed = await parseJson(response);
      var result = parsed.data || {};
      if (!response.ok || !result.success) { throw new Error(errorMessage(result, TEXT_FAILED)); }
      return result;
    }

    async function loadStatus(notify) {
      if (!statusEndpoint) { return; }
      try {
        var response = await fetch(statusEndpoint, { credentials: 'same-origin', headers: authHeaders({ Accept: 'application/json' }) });
        var parsed = await parseJson(response);
        var result = parsed.data || {};
        if (!response.ok || !result.success) { throw new Error(errorMessage(result, 'Status konnte nicht geladen werden.')); }
        applyStatus(result.connection || {});
        if (notify) { setNote('info', 'Der Verbindungsstatus wurde aktualisiert.'); }
      } catch (error) {
        setNote('err', error instanceof Error ? error.message : 'Status konnte nicht geladen werden.');
      }
    }

    async function loadWebappStatus() {
      if (!webappStatusEndpoint) { return; }
      try {
        var response = await fetch(webappStatusEndpoint, { credentials: 'same-origin', headers: authHeaders({ Accept: 'application/json' }) });
        var parsed = await parseJson(response);
        var result = parsed.data || {};
        if (!response.ok || !result.success) { return; }
        if (result.reconciled === true) {
          await loadStatus(false);
          setNote('warn', 'Die Verbindung wurde beendet. Bitte verbinde die Website neu.');
        }
      } catch (ignored) { /* silent — local state stays authoritative */ }
    }

    /* ---- connected: say so, then hand the customer back ---------------- */
    function finishConnected() {
      applyStatus({ status: 'connected' });
      var tab = tabWindow();
      try { tab.document.title = CONNECTED_TAB_TITLE; } catch (e) { /* ignore */ }
      // Only a tab that a script opened may close itself: the two-tab flow.
      if (tab.opener) {
        setTimeout(function () { try { tab.close(); } catch (e) { /* stays open, „Zu site-check“ remains */ } }, CLOSE_DELAY_MS);
      }
    }

    function showFailed(message) {
      panel.setAttribute('data-state', 'disconnected');
      setNote('err', message && message !== TEXT_FAILED ? TEXT_FAILED + ' ' + message : TEXT_FAILED);
      setConnectLabel(LABEL_RETRY);
    }

    async function exchange(payload) {
      setLoading(true);
      setBusyNote(TEXT_BUSY);
      try {
        var result = await postJson(connectEndpoint, payload);
        if (result.connection && result.connection.status === 'connected') {
          setNote(null, '');
          setDashboardLink(result.connection.websiteId);
          finishConnected();
        } else {
          await loadStatus(false);
        }
      } catch (error) {
        showFailed(error instanceof Error ? error.message : '');
      } finally {
        setLoading(false);
      }
    }

    /* ---- „Mit site-check verbinden“ ------------------------------------- */
    async function startConnect(ev) {
      if (ev) { ev.preventDefault(); }
      // Transition: an old webapp sent a code along; use it on the explicit click.
      if (legacyCode && connectEndpoint) {
        var code = legacyCode;
        legacyCode = '';
        return exchange({ pairingCode: code });
      }
      if (!connectStartEndpoint) { return; }
      setLoading(true);
      setBusyNote(TEXT_BUSY);
      try {
        var result = await postJson(connectStartEndpoint, {});
        if (!result.url) { throw new Error(''); }
        tabWindow().location.assign(result.url);
      } catch (error) {
        setLoading(false);
        showFailed(error instanceof Error ? error.message : '');
      }
    }

    /* ---- disconnect ---------------------------------------------------- */
    async function doDisconnect(ev) {
      if (!disconnectEndpoint) { return; }
      if (ev) { ev.preventDefault(); }
      setLoading(true);
      setNote('info', 'Verbindung wird getrennt …');
      try {
        var result = await postJson(disconnectEndpoint, {});
        applyStatus(result.connection || {});
        setNote('ok', 'Die Verbindung wurde getrennt.');
        setConnectLabel(LABEL_CONNECT);
      } catch (error) {
        setNote('err', error instanceof Error ? error.message : 'Verbindung konnte nicht getrennt werden.');
      } finally {
        setLoading(false);
        loadStatus(false);
      }
    }

    /* ---- overlay toggle ------------------------------------------------ */
    async function toggleOverlay() {
      if (!overlayToggle) { return; }
      var next = overlayToggle.getAttribute('aria-pressed') !== 'true';
      if (!overlayEndpoint) { overlayToggle.setAttribute('aria-pressed', next ? 'true' : 'false'); return; }
      overlayToggle.disabled = true;
      overlayToggle.setAttribute('aria-pressed', next ? 'true' : 'false');
      try {
        await postJson(overlayEndpoint, { enabled: next });
      } catch (error) {
        overlayToggle.setAttribute('aria-pressed', next ? 'false' : 'true'); // revert
        setNote('err', 'Die Widget-Einstellung konnte nicht gespeichert werden.');
      } finally {
        overlayToggle.disabled = false;
      }
    }

    /* ---- wire events ---------------------------------------------------- */
    if (connectBtn) { connectBtn.addEventListener('click', startConnect); }
    if (disconnectBtn) { disconnectBtn.addEventListener('click', doDisconnect); }
    if (refreshBtn) { refreshBtn.addEventListener('click', function (e) { e.preventDefault(); loadStatus(true); }); }
    if (overlayToggle) { overlayToggle.addEventListener('click', function (e) { e.preventDefault(); toggleOverlay(); }); }

    /* ---- boot ---------------------------------------------------------- */
    applyStatus({ status: initialStatus, lastNotice: initialNotice, lastError: initialError });
    setConnectLabel(LABEL_CONNECT);

    if (returnCode && returnState && connectEndpoint) {
      // Back from site-check.de: connect on our own, the customer already said yes there.
      cleanUrl();
      exchange({ pairingCode: returnCode, state: returnState });
      return;
    }
    if (legacyCode) { cleanUrl(); }
    loadStatus(false);
    loadWebappStatus();
  }
  // Boot last: initPanel reads the constants above, which a `var` only holds
  // once its line has run (calling it from the top threw before the click
  // handlers were wired, so „Mit site-check verbinden“ did nothing).
  global.ScConnector = { initPanel: initPanel };
  var panels = document.querySelectorAll('[data-sc-panel]');
  for (var i = 0; i < panels.length; i++) { initPanel(panels[i]); }
})(typeof globalThis !== 'undefined' ? globalThis : window);
