(() => {
  'use strict';
  const root = document.getElementById('mbi-cover-refresh');
  if (!root || typeof MBICovers === 'undefined') return;
  let state = {}, busy = false, generation = 0, timer = null, polling = false;
  let cancelImage = null;
  const status = document.getElementById('mbi-cover-status');
  async function request(command, runId, extra = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 18000);
    try {
      const response = await fetch(MBICovers.url, {method: 'POST', credentials: 'same-origin', signal: controller.signal,
        body: new URLSearchParams({action: 'mbi_cover_refresh', nonce: MBICovers.nonce, command, run_id: runId || '', ...extra})});
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.data?.message || 'A hospedagem não concluiu a requisição.');
      return result.data;
    } finally { clearTimeout(timeout); }
  }
  function validateImage(url) {
    return new Promise(resolve => {
      const img = new Image();
      let done = false;
      const finish = (ok, cancelled = false) => {
        if (done) return;
        done = true; clearTimeout(timeout); cancelImage = null;
        img.onload = null; img.onerror = null;
        const width = img.naturalWidth || 0, height = img.naturalHeight || 0;
        if (!ok) img.removeAttribute('src');
        resolve({ok: ok ? '1' : '0', width: String(width), height: String(height), cancelled});
      };
      const timeout = setTimeout(() => finish(false), 8000);
      cancelImage = () => finish(false, true);
      img.referrerPolicy = 'strict-origin-when-cross-origin';
      // An image load needs no CORS permission and follows the normal public
      // delivery path. HTML/403 responses cannot produce usable dimensions.
      img.onload = () => finish(img.naturalWidth >= 32 && img.naturalHeight >= 32);
      img.onerror = () => finish(false);
      img.src = url;
    });
  }
  function render(value) {
    state = value || {};
    const checked = state.checked || 0, total = state.total || 0;
    document.getElementById('mbi-cover-progress').value = total ? 100 * checked / total : 0;
    status.textContent = state.paused ? 'Pausada. Clique em Continuar.' : state.stage === 'stopped' ? 'Parada. As capas já recuperadas foram mantidas.' : state.stage === 'done' ? 'Concluída. Confira as capas não recuperadas abaixo.' : state.current || 'Aguardando.';
    document.getElementById('mbi-cover-counts').textContent = `${checked}/${total} obras verificadas · ${state.repaired || 0} capas recuperadas · ${state.unchanged || 0} já atualizadas · ${state.failed || 0} preservadas com falha`;
    const rows = document.getElementById('mbi-cover-rows'); rows.replaceChildren();
    for (const row of (state.rows || []).slice().reverse()) {
      const tr = document.createElement('tr');
      for (const key of ['title', 'result', 'detail']) { const td = document.createElement('td'); td.textContent = row[key] || ''; tr.appendChild(td); }
      rows.appendChild(tr);
    }
    root.querySelector('[data-cover-command="start"]').disabled = !!state.active || busy;
  }
  function schedule(g) {
    clearTimeout(timer);
    if (g === generation && state.active && !state.paused) timer = setTimeout(() => tick(g), 150);
  }
  async function tick(g) {
    if (g !== generation || busy || !state.active) return;
    busy = true;
    const poll = setInterval(async () => {
      if (polling || g !== generation) return;
      polling = true;
      try { const value = await request('status'); if (g === generation) render(value); } catch (_) {} finally { polling = false; }
    }, 2000);
    try {
      const snapshot = state;
      let value;
      if (snapshot.stage === 'browser' && snapshot.browser?.url && snapshot.browser?.challenge) {
        const result = await validateImage(snapshot.browser.url);
        if (result.cancelled || g !== generation) return;
        value = await request('browser_result', snapshot.id, {challenge: snapshot.browser.challenge, ok: result.ok, width: result.width, height: result.height});
      } else { value = await request('tick', snapshot.id); }
      if (g === generation) render(value);
    } catch (error) {
      if (g === generation) { state.active = false; status.textContent = `${error.name === 'AbortError' ? 'A requisição demorou além do limite.' : error.message} Clique em Continuar; as capas concluídas foram preservadas.`; }
    } finally {
      clearInterval(poll); busy = false;
      if (g === generation) schedule(g);
      else if (state.active && !state.paused) schedule(generation);
      root.querySelector('[data-cover-command="start"]').disabled = !!state.active || busy;
    }
  }
  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-cover-command]'); if (!button) return;
    const command = button.dataset.coverCommand;
    if (busy && (command === 'start' || command === 'resume')) { status.textContent = 'Aguarde a etapa anterior encerrar antes de continuar.'; return; }
    if (command === 'start' && state.active) return;
    clearTimeout(timer); const g = ++generation;
    if ((command === 'pause' || command === 'stop') && cancelImage) cancelImage();
    // Pause/stop remain usable while an external request is running.
    const wasBusy = busy; if (!wasBusy) busy = true; button.disabled = true;
    try { const value = await request(command); if (g === generation) { render(value); if (!wasBusy) { busy = false; schedule(g); } } }
    catch (error) { if (g === generation) status.textContent = error.message; }
    finally { button.disabled = false; if (!wasBusy) busy = false; root.querySelector('[data-cover-command="start"]').disabled = !!state.active || busy; }
  });
  request('status').then(render).catch(error => { status.textContent = error.message; });
})();
