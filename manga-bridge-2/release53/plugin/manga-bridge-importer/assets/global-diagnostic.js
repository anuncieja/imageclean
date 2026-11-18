(function () {
  'use strict';
  const panel = document.getElementById('mbi-global-panel');
  if (!panel || typeof MBIDiagnostic === 'undefined') return;
  let state = {}, busy = false, stopped = false, generation = 0, timer, polling = false;
  const status = document.getElementById('mbi-diag-status');
  async function request(command, runId) {
    const form = new URLSearchParams({ action: 'mbi_global_diagnostic', nonce: MBIDiagnostic.nonce, command, run_id: runId || '' });
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(MBIDiagnostic.url, { method: 'POST', credentials: 'same-origin', body: form, signal: controller.signal });
      const json = await response.json();
      if (!response.ok || !json.success) throw new Error(json.data?.message || 'Falha na comunicação. O catálogo foi preservado.');
      return json.data;
    } finally { clearTimeout(timeout); }
  }
  function render(next) {
    state = next;
    document.getElementById('mbi-diag-progress').value = next.percent || 0;
    const stages = { inventory: 'Inventariando todo o catálogo', home: 'Conferindo imagens e cache do HTML da página inicial', network: 'Testando amostras de origem e entrega', done: 'Diagnóstico concluído. Baixe o relatório JSON.', stopped: 'Diagnóstico parado. O relatório parcial está disponível.' };
    status.textContent = (next.paused ? 'Diagnóstico pausado' : stages[next.stage] || 'Aguardando diagnóstico') + (next.active && next.current ? ' — ' + next.current : '');
    document.getElementById('mbi-diag-counts').textContent = `${next.checked || 0}/${next.total || 0} registros • ${next.urls || 0} links inventariados • ${next.issues || 0} registros com inconsistências • ${next.http_checked || 0} testes HTTP • ${next.http_failed || 0} testes com falha`;
    const tbody = document.getElementById('mbi-diag-rows'); tbody.replaceChildren();
    for (const row of next.rows || []) {
      const tr = document.createElement('tr');
      for (const key of ['title', 'result', 'detail']) { const td = document.createElement('td'); td.textContent = row[key] || ''; tr.appendChild(td); }
      tbody.appendChild(tr);
    }
    panel.querySelector('[data-diag="start"]').disabled = !!next.active;
    panel.querySelector('[data-diag="pause"]').disabled = !next.active;
    panel.querySelector('[data-diag="resume"]').disabled = !next.paused;
  }
  function schedule() { clearTimeout(timer); if (state.active && !stopped) timer = setTimeout(tick, 600); }
  async function tick() {
    if (busy || stopped || !state.active) return;
    const expected = generation; busy = true;
    try { const next = await request('tick', state.id); if (expected === generation && !stopped) render(next); }
    catch (error) { if (expected === generation) { stopped = true; status.textContent = `${error.message} Clique em Continuar para tentar novamente.`; panel.querySelector('[data-diag="resume"]').disabled = false; } }
    finally { busy = false; schedule(); }
  }
  for (const button of panel.querySelectorAll('[data-diag]')) button.addEventListener('click', async () => {
    const command = button.dataset.diag;
    if (busy && !['stop', 'pause'].includes(command)) return;
    generation++; const expected = generation; stopped = true; clearTimeout(timer);
    button.disabled = true;
    try {
      // A resume after a client timeout must re-enable the server state as well.
      const next = await request(command, state.id);
      if (generation !== expected) return;
      render(next); stopped = !next.active; schedule();
    } catch (error) { status.textContent = error.message; button.disabled = false; }
  });
  request('status').then(next => { render(next); stopped = !next.active; schedule(); }).catch(error => { status.textContent = error.message; });
  setInterval(async () => {
    if (!busy || stopped || polling) return;
    polling = true; const expected = generation;
    try { const next = await request('status'); if (expected === generation && !stopped && busy) render(next); }
    catch (_) { /* The processing request owns visible errors and retry controls. */ }
    finally { polling = false; }
  }, 2000);
})();
