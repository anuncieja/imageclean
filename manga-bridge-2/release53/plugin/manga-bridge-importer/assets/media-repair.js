(function () {
  'use strict';
  var root = document.getElementById('mbi-media-panel');
  if (!root || !window.MBIMedia) return;
  var running = false, state = {}, epoch = 0, inflight = false, cancelTests = [], starting = false, lastControl = null;
  var status = document.getElementById('mbi-media-state');
  var progress = document.getElementById('mbi-media-progress');
  var rows = document.getElementById('mbi-media-results');
  function controls() {
    root.querySelectorAll('[data-media-mode]').forEach(function (button) { button.disabled = running || starting || inflight; });
    ['mbi-media-known-errors','mbi-media-background'].forEach(function(id) { var b=document.getElementById(id); if(b) b.disabled=running || starting || inflight || !!state.active; });
    document.getElementById('mbi-media-resume').disabled = running || starting || inflight || !!state.stopped;
    document.getElementById('mbi-media-pause').disabled = !running && !state.active;
  }
  async function request(command, extra) {
    var body = new URLSearchParams(Object.assign({action: 'mbi_media_action', nonce: MBIMedia.nonce, command: command}, extra || {}));
    var controller = new AbortController(), timer = setTimeout(function () { controller.abort(); }, 30000);
    try {
      var response = await fetch(MBIMedia.url, {method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal});
      var data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.data && data.data.message || 'A hospedagem não respondeu corretamente. Consulte Logs de erros.');
      return data.data;
    } finally { clearTimeout(timer); }
  }
  function link(url, label) {
    var node = document.createElement('span'); node.textContent = label || '';
    try { var parsed = new URL(url, location.href); if (!url || !['https:','http:'].includes(parsed.protocol)) return node; var a = document.createElement('a'); a.href = parsed.href; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = label || parsed.href; return a; } catch (e) { return node; }
  }
  function paint() {
    var total = Number(state.total || 0), checked = Number(state.checked || 0);
    var label = state.active ? 'Em andamento' : (state.paused ? 'Pausado' : (state.stopped ? 'Parado' : (checked >= total && total ? 'Concluído' : 'Aguardando')));
    var part = state.browser && state.browser.total ? Number(state.validation_cursor || 0) / state.browser.total : (state.background && /^check_/.test(state.media_stage || '') && state.current_images ? Number(state.validation_cursor || 0) / state.current_images : 0);
    progress.value = total ? Math.min(100, (checked + Math.min(1, part)) * 100 / total) : 0;
    status.textContent = label + ' — ' + checked + '/' + total + ' registros; ' + Number(state.repaired || 0) + ' com links atualizados; ' + Number(state.failed || 0) + ' preservados para revisão.';
    if (state.background) { status.textContent += ' Encaminhados à origem: ' + Number(state.detected || 0) + ' (não equivale a falhas confirmadas).'; if (state.current_images) status.textContent += ' Imagem ' + Number(state.image_cursor || state.validation_cursor || 0) + '/' + state.current_images + '.'; if (state.last_tick) status.textContent += ' Último processamento: ' + new Date(state.last_tick*1000).toLocaleTimeString() + '.'; }
    if (state.only_errors) status.textContent += ' Lote limitado ao relatório: ' + Number(state.quarantined || 0) + ' retirados do catálogo e guardados em rascunho; ' + (state.skipped_errors || []).length + ' registros excluídos por alteração ou ausência.';
    if (state.message) status.textContent += ' ' + state.message;
    if (state.active && state.current_record && state.target) {
      status.textContent += ' Agora: ' + state.current_record + ' — ' + (['fetch','fetch_source'].includes(state.media_stage) ? 'consultando a página original' : (state.media_stage === 'triage' ? 'triagem rápida por amostra' : 'verificando imagens'));
      if (state.browser) status.textContent += ' (' + Number(state.validation_cursor || 0) + '/' + state.browser.total + ' imagens validadas).';
    }
    var stats = state.stats || {}, summary = document.getElementById('mbi-media-summary');
    summary.replaceChildren();
    [['Falhas anteriores confirmadas',stats.confirmed],['Corrigidas após falha confirmada',stats.fixed_confirmed],['Atualizados sem falha anterior confirmada',stats.updated_unconfirmed],['Validações completas reaproveitadas',stats.cached],['Sem falha na amostra',stats.sample],['Entrega não verificada',stats.unknown],['Triagem inconclusiva (rede)',stats.inconclusive],['Falha no reparo; links preservados',stats.preserved],['Retirados do catálogo (rascunho)',stats.quarantined]].forEach(function (item) {
      var card = document.createElement('div'); card.style.cssText = 'padding:12px;border:1px solid #dcdcde;background:#f6f7f7;border-radius:4px';
      var number = document.createElement('strong'); number.style.cssText = 'display:block;font-size:22px;margin-bottom:5px'; number.textContent = String(Number(item[1] || 0));
      var label = document.createElement('span'); label.textContent = item[0]; card.appendChild(number); card.appendChild(label); summary.appendChild(card);
    });
    if (state.legacy_checked) { var legacy = document.createElement('p'); legacy.style.cssText='grid-column:1/-1;margin:0'; legacy.textContent = state.legacy_checked + ' registros da versão anterior sem classificação do estado das imagens antes do reparo.'; summary.appendChild(legacy); }
    var current = document.getElementById('mbi-media-current'); current.replaceChildren();
    if (state.target) { current.appendChild(link(state.current_url, state.current_record || 'Capítulo atual')); current.appendChild(document.createTextNode(' — ' + (state.before_label || 'Verificando') + ' — etapa: ' + ({precheck:'triagem inicial',triage:'amostra rápida',fetch:'consultar lista da origem',fetch_source:'consultar capítulo na origem',check_fresh:'validar todas as imagens novas',check_current:'validar imagens atuais'}[state.media_stage] || state.media_stage || 'aguardando'))); }
    var filter = document.getElementById('mbi-media-filter').value;
    rows.replaceChildren();
    (state.rows || []).slice().reverse().filter(function (row) {
      if (filter === 'confirmed') return !!(row.evidence && row.evidence.confirmed);
      if (filter === 'unknown') return ['Inconclusivo','Entrega não verificada'].includes(row.status);
      if (filter === 'repaired') return row.status === 'Reparado';
      if (filter === 'quarantined') return row.status === 'Retirado do catálogo — rascunho';
      if (filter === 'preserved') return row.status === 'Preservado — verificar';
      return true;
    }).forEach(function (row) {
      var tr = document.createElement('tr'), td = document.createElement('td');
      td.appendChild(link(row.url, row.record));
      if (row.origin) { td.appendChild(document.createElement('br')); td.appendChild(link(row.origin, 'Abrir capítulo na origem')); }
      tr.appendChild(td);
      var evidence = row.evidence || {}, error = row.repair_error || {};
      var details = evidence.page ? 'Antes: página ' + evidence.page + ' — ' + evidence.message : 'Nenhuma falha anterior confirmada registrada.';
      if (error.page) details += ' Reparo: página ' + error.page + ' — ' + error.message;
      [row.before || 'Não registrado pela versão anterior',(row.status === 'Reparado' ? 'Links atualizados' : row.status),details,row.detail].forEach(function (value) { var cell = document.createElement('td'); cell.textContent = value || ''; tr.appendChild(cell); });
      rows.appendChild(tr);
    });
    controls();
  }
  function testImage(url) {
    return new Promise(function (resolve) {
      var img = new Image(), settled = false, timer;
      function finish(ok, reason) {
        if (settled) return;
        settled = true; clearTimeout(timer);
        var size = {width: img.naturalWidth || 0, height: img.naturalHeight || 0};
        img.onload = img.onerror = null; img.removeAttribute('src');
        resolve({ok: ok && size.width >= 32 && size.height >= 32, size: size, reason: reason || (size.width < 32 || size.height < 32 ? 'imagem pequena' : '')});
      }
      cancelTests.push(function () { finish(false, 'cancelado'); });
      img.referrerPolicy = 'strict-origin-when-cross-origin';
      img.onload = async function () { try { if (img.decode) await img.decode(); finish(true); } catch (e) { finish(false, 'erro de carregamento'); } };
      img.onerror = function () { finish(false, 'erro de carregamento'); };
      timer = setTimeout(function () { finish(false, 'tempo limite'); }, 15000);
      img.src = url;
    });
  }
  async function loop(token) {
    if (!running || token !== epoch || inflight) return;
    inflight = true;
    try {
      var next;
      if (state.background) { next = await request('status'); } else if (state.browser) {
        var batch = state.browser;
        var tests = batch.urls.map(function(){return {ok:false,size:{width:0,height:0},reason:'não verificada após falha'};}), nextIndex = 0, batchFailed = false; await Promise.all([0,1].map(async function () { while (nextIndex < batch.urls.length && token === epoch && running && !batchFailed) { var index = nextIndex++; tests[index] = await testImage(batch.urls[index]); if (!tests[index].ok) batchFailed = true; } }));
        cancelTests = [];
        if (token !== epoch || !running) return;
        next = await request('browser_result', {run_id: state.id, challenge: batch.challenge, ok: tests.every(function (r) { return r.ok; }) ? '1' : '0', count: tests.length, sizes: JSON.stringify(tests.map(function (r) { return r.size; })), failures: JSON.stringify(tests.map(function (r, i) { return r.ok ? null : {index: i, reason: r.reason}; }).filter(Boolean))});
      } else next = await request('tick', {run_id: state.id});
      if (token !== epoch) return;
      state = next; running = !!state.active; paint();
    } catch (error) {
      if (token !== epoch) return;
      running = false; controls(); status.textContent = 'Execução interrompida: ' + error.message + ' Use Continuar execução existente. Links publicados preservados.';
    } finally {
      inflight = false; controls();
      if (running && token === epoch) setTimeout(function () { loop(token); }, state.next_attempt > Date.now()/1000 ? Math.min(5000, Math.max(500, state.next_attempt*1000-Date.now())) : (state.background ? 5000 : 100));
    }
  }
  root.querySelectorAll('[data-media-mode]').forEach(function (button) {
    button.addEventListener('click', async function () {
      var value = document.getElementById('mbi-media-manga').value;
      var chapterURL = document.getElementById('mbi-media-chapter-url').value.trim();
      if (value === '' && !chapterURL) { status.textContent = 'Selecione primeiro o mangá.'; return; }
      if (inflight || starting) return;
      starting = true; lastControl = null; running = true; controls(); var token = ++epoch;
      try {
        var saved = await request('start', {manga_id: value || '0', chapter_url: chapterURL, mode: button.dataset.mediaMode, scope: document.getElementById('mbi-media-scope').value, verification: document.getElementById('mbi-media-verification').value});
        if (token !== epoch) {
          if (lastControl) { state = await request(lastControl); paint(); }
          return;
        }
        state = saved; paint(); loop(token);
      } catch (error) { if (token === epoch) { running = false; controls(); status.textContent = error.message; } }
      finally { starting = false; controls(); }
    });
  });
  async function control(command) {
    lastControl = command; running = false; var token = ++epoch;
    cancelTests.splice(0).forEach(function (cancel) { cancel(); });
    controls(); status.textContent = 'Confirmando ' + (command === 'pause' ? 'pausa' : 'parada') + '…';
    try {
      var saved = await request(command);
      if (token === epoch) { state = saved; paint(); }
    } catch (error) { if (token === epoch) status.textContent = 'Não foi possível confirmar: ' + error.message; }
  }
  document.getElementById('mbi-media-pause').addEventListener('click', function () { control('pause'); });
  document.getElementById('mbi-media-stop').addEventListener('click', function () { control('stop'); });
  document.getElementById('mbi-media-resume').addEventListener('click', async function () {
    if (inflight || starting) { status.textContent = 'Aguarde a requisição anterior terminar.'; return; }
    running = true; controls(); var token = ++epoch;
    try {
      var saved = await request('resume');
      if (token !== epoch) return;
      state = saved; paint(); loop(token);
    } catch (error) { if (token === epoch) { running = false; controls(); status.textContent = error.message; } }
  });
  document.getElementById('mbi-media-download').addEventListener('click', function () {
    var lines=['registro;link_site;link_origem;situacao_anterior;resultado;pagina_anterior;erro_anterior;url_imagem_anterior;pagina_reparo;erro_reparo;url_imagem_reparo;detalhes'];(state.rows||[]).forEach(function (row) { lines.push([row.record,row.url,row.origin,row.before || 'Não registrado pela versão anterior',row.status,(row.evidence||{}).page,(row.evidence||{}).message,(row.evidence||{}).url,(row.repair_error||{}).page,(row.repair_error||{}).message,(row.repair_error||{}).url,row.detail].map(function(v) { v=String(v==null?'':v);if(/^[=+@\-\t\r]/.test(v))v="'"+v;return '"'+v.replace(/"/g,'""')+'"'; }).join(';')); });
    var url=URL.createObjectURL(new Blob(['\uFEFF'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'})),a=document.createElement('a');a.href=url;a.download='manga-bridge-reparo-direto.csv';a.click();setTimeout(function(){URL.revokeObjectURL(url);},1000);
  });
  document.getElementById('mbi-media-background').addEventListener('click', async function () {
    if (inflight || starting) return;
    starting = true; controls(); var token = ++epoch;
    try { state = await request('start_background', {chapter_url: document.getElementById('mbi-media-chapter-url').value.trim(), covers: document.getElementById('mbi-media-background-covers').checked ? '1' : '0'}); running = !!state.active; paint(); loop(token); }
    catch (error) { status.textContent = error.message; }
    finally { starting = false; controls(); }
  });
  document.getElementById('mbi-media-known-errors').addEventListener('click', async function () {
    if (inflight || starting || state.active) return;
    starting = true; lastControl = null; controls(); var token = ++epoch;
    try { var saved = await request('start_known_errors'); if(token !== epoch) { if(lastControl) { state=await request(lastControl); paint(); } return; } state=saved; running=!!state.active; paint(); loop(token); }
    catch(error) { if(token === epoch) { running=false; status.textContent=error.message; } }
    finally { starting=false; controls(); }
  });
  document.getElementById('mbi-media-filter').addEventListener('change', paint);
  var initialEpoch = epoch;
  request('status').then(function (saved) {
    if (epoch === initialEpoch && !running) { state = saved; paint(); if (state.active && state.background) { running = true; loop(epoch); } else if (state.active) status.textContent += ' Clique em Continuar execução existente para prosseguir.'; }
  }).catch(function (error) { if (epoch === initialEpoch) status.textContent = error.message; });
})();
