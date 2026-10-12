/* =========================================================================
   Site-Check Accessibility — Connector Admin Panel (JS)

   Transport-agnostic: reads its endpoints + state from data-* on the root
   [data-sc-panel] element. When endpoints are present it drives connect /
   disconnect / status-poll / overlay-toggle via fetch (live-async). When an
   endpoint is absent the matching <form> keeps its native server POST as a
   no-JS fallback.

   Two boot paths:
   - Light DOM (Grav 1.7 Twig, WordPress, TYPO3, Joomla): the adapter renders the
     panel into the page and the kit picks it up itself on load.
   - Shadow DOM (the Grav 2 admin2 web component): a shadow root is invisible to
     document.querySelectorAll, so that host calls ScConnector.initPanel(root)
     with its own panel element once the markup is attached.
   ========================================================================= */
(function (global) {
  var panels = document.querySelectorAll('[data-sc-panel]');
  for (var i = 0; i < panels.length; i++) { initPanel(panels[i]); }

  global.ScConnector = { initPanel: initPanel };

  function initPanel(panel) {
    if (!panel || panel.dataset.scInit === '1') { return; }
    panel.dataset.scInit = '1';

    var d = panel.dataset;
    var connectEndpoint = d.connectEndpoint || '';
    var disconnectEndpoint = d.disconnectEndpoint || '';
    var statusEndpoint = d.statusEndpoint || '';
    var overlayEndpoint = d.overlayEndpoint || '';
    var webappStatusEndpoint = d.webappStatusEndpoint || '';
    var csrfToken = d.csrf || '';
    var csrfHeader = d.csrfHeader || '';

    var initialStatus = d.initialStatus || 'not_connected';
    var initialNotice = d.initialNotice || '';
    var initialError = d.initialError || '';

    var signal = panel.querySelector('[data-sc-signal]');
    var signalLabel = panel.querySelector('[data-sc-signal-label]');
    var heroLead = panel.querySelector('[data-sc-hero-lead]');
    var connectTitle = panel.querySelector('[data-sc-connect-title]');
    var codeInput = panel.querySelector('[data-sc-code]');
    var codeFb = panel.querySelector('[data-sc-code-fb]');
    var connectBtn = panel.querySelector('[data-sc-connect]');
    var connectBtnLabel = connectBtn ? (connectBtn.textContent || '').trim() : '';
    var disconnectBtn = panel.querySelector('[data-sc-disconnect]');
    var refreshBtn = panel.querySelector('[data-sc-refresh]');
    var note = panel.querySelector('[data-sc-note]');
    var overlayToggle = panel.querySelector('[data-sc-overlay-toggle]');

    // Pre-fill the pairing code from a ?pairingCode= / ?pairing= deep link.
    try {
      var q = new URLSearchParams(window.location.search);
      var qc = q.get('pairingCode') || q.get('pairing') || '';
      if (qc && codeInput && !codeInput.value) { codeInput.value = qc; }
    } catch (e) { /* URLSearchParams unavailable — ignore */ }

    // Maps the four server statuses onto the three visual states of the kit.
    var VIEW = {
      not_connected: {
        state: 'disconnected', tone: 'neutral', label: 'Nicht verbunden',
        lead: 'Site-Check wendet geprüfte Barrierefreiheits-Fixes automatisch an. Verbinde die Website mit deinem Site-Check-Konto, um loszulegen.',
        cTitle: 'Diese Website mit Site-Check verbinden', cBtn: 'Jetzt verbinden'
      },
      pairing_pending: {
        state: 'disconnected', tone: 'neutral', label: 'Wird verbunden …',
        lead: 'Die Website tauscht gerade die Zugangsdaten mit Site-Check aus. Das dauert nur einen Moment.',
        cTitle: 'Diese Website mit Site-Check verbinden', cBtn: 'Jetzt verbinden'
      },
      connected: {
        state: 'connected', tone: 'ok', label: 'Verbunden',
        lead: 'Die Verbindung zur Site-Check-Webapp ist aktiv. Geprüfte Fixes und das Assistenz-Overlay werden ausgeliefert.',
        cTitle: 'Diese Website mit Site-Check verbinden', cBtn: 'Jetzt verbinden'
      },
      error: {
        state: 'interrupted', tone: 'danger', label: 'Verbindung unterbrochen',
        lead: 'Die Verbindung zur Site-Check-Webapp ist unterbrochen — aktuell werden keine Fixes ausgeliefert. Verbinde die Website neu oder prüfe den Status.',
        cTitle: 'Verbindung wiederherstellen', cBtn: 'Erneut verbinden'
      }
    };

    var PAIRING_CODE_PATTERN = /^[0-9a-fA-F]{48}$/;

    /* ---- pairing-code live validation --------------------------------- */
    function validateCode() {
      if (!codeInput || !codeFb) { return; }
      var v = (codeInput.value || '').trim();
      if (!v) {
        codeInput.removeAttribute('data-valid');
        codeFb.removeAttribute('data-tone');
        codeFb.textContent = '';
        return;
      }
      if (PAIRING_CODE_PATTERN.test(v)) {
        codeInput.setAttribute('data-valid', 'true');
        codeFb.setAttribute('data-tone', 'valid');
        codeFb.textContent = 'Format sieht gut aus.';
      } else if (v.length < 48) {
        codeInput.removeAttribute('data-valid');
        codeFb.removeAttribute('data-tone');
        codeFb.textContent = 'Noch ' + (48 - v.length) + ' Zeichen bis zur vollen Länge.';
      } else {
        codeInput.setAttribute('data-valid', 'false');
        codeFb.setAttribute('data-tone', 'invalid');
        codeFb.textContent = 'Der Code sollte genau 48 Hex-Zeichen (0–9, a–f) enthalten.';
      }
    }
    if (codeInput) { codeInput.addEventListener('input', validateCode); validateCode(); }

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

    /* ---- apply a status object to the whole panel --------------------- */
    function applyStatus(connection) {
      var status = (connection && connection.status) || 'not_connected';
      var v = VIEW[status] || VIEW.not_connected;
      panel.setAttribute('data-state', v.state);
      if (signal) { signal.setAttribute('data-tone', v.tone); }
      if (signalLabel) { signalLabel.textContent = v.label; }
      if (heroLead) { heroLead.textContent = v.lead; }
      if (connectTitle) { connectTitle.textContent = v.cTitle; }
      if (connectBtn && v.cBtn && !connectBtn.disabled) { connectBtn.textContent = v.cBtn; connectBtnLabel = v.cBtn; }

      if (connection && connection.lastError) {
        setNote('err', connection.lastError);
      } else if (connection && connection.lastNotice) {
        setNote('warn', connection.lastNotice);
      } else if (status === 'connected') {
        setNote('ok', 'Die Verbindung zur Site-Check-Webapp ist aktiv.');
      } else if (status === 'pairing_pending') {
        setNote('info', 'Die Verbindung wird gerade vorbereitet …');
      } else if (status === 'error') {
        setNote('err', 'Die Verbindung ist unterbrochen. Bitte verbinde die Website neu.');
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
    async function postJson(url, payload) {
      var response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: authHeaders({ 'Content-Type': 'application/json', Accept: 'application/json' }),
        body: JSON.stringify(payload || {})
      });
      var parsed = await parseJson(response);
      var result = parsed.data || {};
      if (!response.ok || !result.success) {
        var msg = (result && result.error && result.error.message) || result.error || 'Anfrage fehlgeschlagen.';
        throw new Error(msg);
      }
      return result;
    }

    async function loadStatus(notify) {
      if (!statusEndpoint) { return; }
      try {
        var response = await fetch(statusEndpoint, { credentials: 'same-origin', headers: authHeaders({ Accept: 'application/json' }) });
        var parsed = await parseJson(response);
        var result = parsed.data || {};
        if (!response.ok || !result.success) { throw new Error((result && result.error && result.error.message) || 'Status konnte nicht geladen werden.'); }
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
          if (result.authorized === false) {
            setNote('warn', 'Die Webapp hat die Verbindung beendet. Bitte verbinde die Website neu.');
          } else {
            setNote('warn', 'Die Webapp meldet einen abweichenden Verbindungsstatus. Der lokale Zustand wurde zurückgesetzt.');
          }
          return;
        }
        if (result.authorized === true && result.webappReachable === false) {
          setNote('warn', 'Die Webapp war gerade nicht erreichbar. Der angezeigte Status stammt lokal aus dem Plugin.');
        }
      } catch (ignored) { /* silent — local state stays authoritative */ }
    }

    /* ---- post-connect polling ----------------------------------------- */
    var pollTimer = null, pollDeadline = 0;
    function stopPoll() { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } pollDeadline = 0; }
    async function runPoll() {
      if (!statusEndpoint) { return; }
      try {
        var response = await fetch(statusEndpoint, { credentials: 'same-origin', headers: authHeaders({ Accept: 'application/json' }) });
        var parsed = await parseJson(response);
        var result = parsed.data || {};
        if (response.ok && result.success) {
          applyStatus(result.connection || {});
          if (result.connection && result.connection.status === 'connected') {
            stopPoll();
            setNote('ok', 'Die Website ist verbunden.');
            return;
          }
        }
      } catch (ignored) { /* keep polling until the deadline */ }
      if (Date.now() >= pollDeadline) {
        stopPoll();
        setNote('warn', 'Wir konnten die Verbindung innerhalb von 2 Minuten nicht bestätigen. Prüfe den Status oder versuche es erneut.');
        return;
      }
      pollTimer = setTimeout(runPoll, 15000);
    }
    function startPoll() { stopPoll(); pollDeadline = Date.now() + 120000; pollTimer = setTimeout(runPoll, 15000); }

    /* ---- connect ------------------------------------------------------- */
    async function doConnect(ev) {
      // No async endpoint wired → let the native <form> submit (server fallback).
      if (!connectEndpoint) { return; }
      if (ev) { ev.preventDefault(); }
      var code = (codeInput && codeInput.value || '').trim();
      if (!code) { setNote('err', 'Bitte füge zuerst den Verbindungscode aus der Webapp ein.'); if (codeInput) { codeInput.focus(); } return; }
      if (!PAIRING_CODE_PATTERN.test(code)) { setNote('err', 'Der Code sieht nicht richtig aus. Er sollte genau 48 Hex-Zeichen (0–9, a–f) enthalten.'); if (codeInput) { codeInput.focus(); } return; }
      setLoading(true);
      setBusyNote('Verbindung läuft, bitte warten …');
      try {
        var result = await postJson(connectEndpoint, { pairingCode: code });
        applyStatus(result.connection || {});
        if (result.connection && result.connection.status === 'connected') {
          setNote('ok', 'Die Website wurde erfolgreich verbunden.');
          stopPoll();
        } else if (result.notice) {
          setNote('warn', result.notice);
          startPoll();
        } else {
          setNote('info', 'Verbindung wird bestätigt, das kann einen Moment dauern …');
          startPoll();
        }
      } catch (error) {
        stopPoll();
        setNote('err', error instanceof Error ? error.message : 'Verbindung konnte nicht hergestellt werden.');
      } finally {
        setLoading(false);
        loadStatus(false);
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
        setNote('err', 'Die Overlay-Einstellung konnte nicht gespeichert werden.');
      } finally {
        overlayToggle.disabled = false;
      }
    }

    /* ---- wire events (async when possible, native form as fallback) ---- */
    bindSubmit(connectBtn, doConnect);
    bindSubmit(disconnectBtn, doDisconnect);
    if (refreshBtn) { refreshBtn.addEventListener('click', function (e) { e.preventDefault(); loadStatus(true); }); }
    if (overlayToggle) { overlayToggle.addEventListener('click', function (e) { e.preventDefault(); toggleOverlay(); }); }

    function bindSubmit(btn, handler) {
      if (!btn) { return; }
      // The connect/disconnect controls are <button type="button"> (no enclosing
      // form), so bind the action to the button's click — doConnect/doDisconnect
      // call preventDefault themselves. Binding to a form's submit never fired.
      btn.addEventListener('click', handler);
    }

    /* ---- boot ---------------------------------------------------------- */
    applyStatus({ status: initialStatus, lastNotice: initialNotice, lastError: initialError });
    loadStatus(false);
    loadWebappStatus();
  }
})(typeof globalThis !== 'undefined' ? globalThis : window);
