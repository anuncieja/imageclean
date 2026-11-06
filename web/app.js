import { LIMITS, assertEditable } from './engine.js';
const $ = id => document.getElementById(id);
let current = null, result = null, mode = 'clean', busy = false, serial = 0, history = [], workerFailed = false;
const worker = new Worker(new URL('./worker.js', import.meta.url), { type: 'module' });
const pending = new Map();
worker.onmessage = ({ data }) => { const p = pending.get(data.id); if (!p) return; pending.delete(data.id); clearTimeout(p.timer); data.error ? p.reject(new Error(data.error)) : p.resolve(data); };
function failWorker(message) { workerFailed = true; worker.terminate(); for (const p of pending.values()) { clearTimeout(p.timer); p.reject(new Error(message)); } pending.clear(); }
worker.onerror = () => failWorker('O analisador encontrou um erro. Recarregue a página.');
function run(action, bytes, extra = {}) {
  return new Promise((resolve, reject) => {
    if (workerFailed) { reject(new Error('O analisador foi interrompido. Recarregue a página.')); return; }
    const id = ++serial, copy = bytes.slice();
    const timer = setTimeout(() => failWorker('A operação demorou demais e foi interrompida. Recarregue a página.'), 45000);
    pending.set(id, { resolve, reject, timer }); worker.postMessage({ id, action, bytes: copy.buffer, ...extra }, [copy.buffer]);
  });
}
const size = n => n >= 1024 * 1024 ? `${(n / 1024 / 1024).toFixed(2)} MiB` : `${(n / 1024).toFixed(1)} KiB`;
function status(text, error = false) { $('status').textContent = text; $('status').classList.toggle('error', error); }
function setBusy(value) {
  busy = value; $('file').disabled = value; $('demo').disabled = value;
  $('process').disabled = value || !current;
  $('process').firstChild.textContent = value ? 'Processando… ' : 'Processar imagem ';
  for (const el of document.querySelectorAll('.tab, #clean-options input, #convert-options input, #convert-options select')) el.disabled = value;
}
function clearResult() {
  if (result?.url) URL.revokeObjectURL(result.url); result = null;
  $('status-badge').textContent = current ? 'Inspecionado' : 'Aguardando imagem';
  $('status-badge').className = current ? 'badge ok' : 'badge';
  $('output').hidden = true; $('output').removeAttribute('src'); $('empty-output').hidden = false;
  $('summary').hidden = true; $('after-size').textContent = '—';
  $('download-image').disabled = true; $('download-report').disabled = !current;
}
function row(category, container, bytes) {
  const el = document.createElement('div'); el.className = 'metadata-row';
  for (const [tag, content] of [['strong', category], ['span', container], ['small', size(bytes)]]) { const part = document.createElement(tag); part.textContent = content; el.append(part); }
  return el;
}
function showMetadata(report) {
  const target = $('metadata'); target.replaceChildren(); target.className = '';
  if (!report.metadata.length) { target.className = 'metadata-empty'; target.textContent = 'Nenhuma categoria de metadados suportada encontrada. Isso não comprova ausência de marcas invisíveis ou de outros dados.'; }
  for (const m of report.metadata) {
    target.append(row(m.category, m.container, m.bytes));
    if (m.exif) {
      const details = document.createElement('p'); details.className = 'exif-details';
      details.textContent = [m.exif.readable ? 'EXIF interpretado parcialmente (campos suportados)' : 'EXIF não interpretado', m.exif.orientation ? `Orientação: ${m.exif.orientation}` : '', m.exif.gpsPointer ? 'Ponteiro GPS encontrado; coordenadas não extraídas' : '', ...Object.entries(m.exif.fields).map(([k,v]) => `${k}: ${v}`)].filter(Boolean).join(' · ');
      target.append(details);
    }
  }
}
async function loadFile(file) {
  if (busy || !file) return;
  setBusy(true);
  if (current?.url) URL.revokeObjectURL(current.url);
  current = null; clearResult(); $('original').hidden = true; $('original').removeAttribute('src'); $('empty-original').hidden = false;
  $('filename').textContent = 'Inspecionando…'; $('stat-format').textContent = '—'; $('stat-dimensions').textContent = '—'; $('stat-metadata').textContent = '—'; $('before-size').textContent = '—'; $('c2pa').textContent = 'Não verificado.'; $('metadata').replaceChildren();
  status('Lendo a estrutura do arquivo neste dispositivo…');
  try {
    if (file.size > LIMITS.bytes) throw new Error('O arquivo deve ter até 30 MiB.');
    const bytes = new Uint8Array(await file.arrayBuffer()), analysis = await run('inspect', bytes);
    if (analysis.report.animated) throw new Error('Imagens animadas ainda não são aceitas na interface. Use uma imagem estática.');
    const url = URL.createObjectURL(new Blob([bytes], { type: mime(analysis.report.format) }));
    try { const image = new Image(); image.src = url; await image.decode(); } catch { URL.revokeObjectURL(url); throw new Error('O navegador não conseguiu decodificar esta imagem.'); }
    current = { name: file.name, bytes, url, report: analysis.report, hash: analysis.hash };
    $('original').src = url; $('original').hidden = false; $('empty-original').hidden = true;
    $('filename').textContent = file.name; $('before-size').textContent = size(bytes.length);
    $('stat-format').textContent = analysis.report.format; $('stat-dimensions').textContent = `${analysis.report.width} × ${analysis.report.height}`; $('stat-metadata').textContent = analysis.report.metadata.length;
    $('c2pa').textContent = analysis.report.c2pa; showMetadata(analysis.report);
    $('status-badge').textContent = 'Inspecionado'; $('status-badge').className = 'badge ok';
    status(analysis.report.warnings.length ? analysis.report.warnings.join(' ') : 'Inspeção concluída. Escolha as categorias e processe para comparar.');
    $('download-report').disabled = false;
  } catch (e) { $('filename').textContent = 'Arquivo não aceito'; $('status-badge').textContent = 'Revisar arquivo'; $('status-badge').className = 'badge'; status(e.message, true); }
  finally { $('file').value = ''; setBusy(false); }
}
function mime(format) { return { JPEG: 'image/jpeg', PNG: 'image/png', WebP: 'image/webp' }[format]; }
function outputName(format) { const base = current.name.replace(/\.[^.]+$/, '').replace(/[^\p{L}\p{N}_.-]/gu, '_').slice(0, 100) || 'imagem'; return `${base}-processada.${{ JPEG: 'jpg', PNG: 'png', WebP: 'webp' }[format]}`; }
async function convert() {
  assertEditable(current.report);
  const max = Number($('max-side').value); if (!Number.isInteger(max) || max < 64 || max > 6000) throw new Error('Informe um lado maior entre 64 e 6000 pixels.');
  const bitmap = await createImageBitmap(new Blob([current.bytes], { type: mime(current.report.format) }), { imageOrientation: 'from-image' });
  try {
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas'); canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const ctx = canvas.getContext('2d'); if (!ctx) throw new Error('Canvas indisponível.');
    const type = $('format').value; if (type === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); }
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((resolve, reject) => canvas.toBlob(b => b ? resolve(b) : reject(new Error('Conversão indisponível.')), type, Number($('quality').value) / 100));
    if (blob.type !== type) throw new Error('Este navegador não oferece o formato de saída selecionado.');
    const bytes = new Uint8Array(await blob.arrayBuffer()); return { bytes, ...(await run('inspect', bytes)), removed: null };
  } finally { bitmap.close(); }
}
$('process').addEventListener('click', async () => {
  if (!current || busy) return; clearResult(); setBusy(true); status('Processando e verificando o arquivo resultante…');
  try {
    let processed;
    if (mode === 'clean') {
      const categories = [...document.querySelectorAll('#clean-options input[value]:checked')].map(el => el.value);
      if (!categories.length) throw new Error('Selecione pelo menos uma categoria.');
      if (categories.some(c => c !== 'EXIF') && !$('ack').checked) throw new Error('Revise e confirme a remoção das categorias que podem conter autoria.');
      const data = await run('sanitize', current.bytes, { categories }); processed = { ...data, bytes: new Uint8Array(data.bytes) };
    } else processed = await convert();
    const blob = new Blob([processed.bytes], { type: mime(processed.report.format) }), url = URL.createObjectURL(blob);
    try { const check = new Image(); check.src = url; await check.decode(); } catch { URL.revokeObjectURL(url); throw new Error('A saída não foi decodificada pelo navegador. Download bloqueado.'); }
    result = { ...processed, url, name: outputName(processed.report.format), operation: mode, date: new Date().toISOString(), settings: mode === 'clean' ? { categories: [...document.querySelectorAll('#clean-options input[value]:checked')].map(el => el.value) } : { format: $('format').value, maxSide: Number($('max-side').value), quality: Number($('quality').value) / 100 } };
    $('output').src = url; $('output').hidden = false; $('empty-output').hidden = true; $('after-size').textContent = size(result.bytes.length);
    const difference = current.bytes.length ? ((1 - result.bytes.length / current.bytes.length) * 100).toFixed(1) : '0';
    $('summary').textContent = mode === 'clean' ? `${result.removed.length} contêiner(es) removido(s). Nova inspeção: categorias selecionadas não encontradas. Dados codificados da imagem preservados. Variação de tamanho: ${difference}% menor. Isso não representa verificação de SynthID.` : `Conversão para ${result.report.format}: ${result.report.width} × ${result.report.height}. Variação de tamanho: ${difference}% menor (valor negativo indica aumento). Qualidade e perfis de cores podem mudar.`;
    $('summary').hidden = false; $('download-image').disabled = false; $('status-badge').textContent = 'Resultado verificado';
    status('A saída foi reinspecionada e decodificada pelo navegador. Você já pode baixar o arquivo e o relatório.');
    history.unshift({ name: current.name, operation: mode === 'clean' ? 'Limpeza' : 'Conversão', bytes: result.bytes.length, date: result.date }); history = history.slice(0, 20); renderHistory();
  } catch (e) { status(e.message, true); }
  finally { setBusy(false); }
});
function download(blob, name) { const url = URL.createObjectURL(blob), a = document.createElement('a'); a.href = url; a.download = name; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 30000); }
$('download-image').addEventListener('click', () => { if (result) download(new Blob([result.bytes], { type: mime(result.report.format) }), result.name); });
$('download-report').addEventListener('click', () => {
  if (!current) return;
  const report = { app: 'ImageClean Lab', version: '0.1.0', generatedAt: new Date().toISOString(), original: { name: current.name, sha256: current.hash, inspection: current.report }, result: result ? { name: result.name, sha256: result.hash, inspection: result.report, operation: result.operation, settings: result.settings, removedContainers: result.removed, date: result.date } : null, limitations: ['Análise estrutural limitada a contêineres reconhecidos.', 'Assinaturas C2PA não validadas.', 'SynthID não verificado.', 'Ausência de rótulos não garantida.', 'Nenhuma imagem foi enviada a serviços externos por esta aplicação.'], observations: { platform: null, publishedAt: null, aiLabelObserved: null, evidence: null } };
  download(new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' }), 'imageclean-relatorio.json');
});
function renderHistory() {
  const target = $('history-list'); target.replaceChildren(); target.className = history.length ? '' : 'history-empty';
  if (!history.length) { target.textContent = 'Os processamentos concluídos aparecerão aqui.'; return; }
  for (const item of history) { const div = document.createElement('div'); div.className = 'history-row'; for (const value of [item.name, item.operation, size(item.bytes)]) { const span = document.createElement('span'); span.textContent = value; div.append(span); } const time = document.createElement('time'); time.dateTime = item.date; time.textContent = new Date(item.date).toLocaleTimeString('pt-BR'); div.append(time); target.append(div); }
}
$('clear').addEventListener('click', () => { history = []; renderHistory(); });
for (const [id, selectedMode] of [['tab-clean', 'clean'], ['tab-convert', 'convert']]) $(id).addEventListener('click', () => { if (busy) return; mode = selectedMode; $('clean-options').hidden = mode !== 'clean'; $('convert-options').hidden = mode !== 'convert'; for (const [tab, m] of [['tab-clean','clean'],['tab-convert','convert']]) { $(tab).classList.toggle('active', mode === m); $(tab).setAttribute('aria-pressed', mode === m); } });
$('clean-options').addEventListener('change', () => { $('ack-row').hidden = ![...document.querySelectorAll('#clean-options input[value]:checked')].some(el => el.value !== 'EXIF'); $('ack').checked = false; });
$('quality').addEventListener('input', () => { $('quality-label').textContent = `${$('quality').value}%`; });
$('file').addEventListener('change', () => loadFile($('file').files[0]));
for (const event of ['dragenter','dragover']) $('dropzone').addEventListener(event, e => { e.preventDefault(); if (!busy) $('dropzone').classList.add('dragover'); });
for (const event of ['dragleave','drop']) $('dropzone').addEventListener(event, e => { e.preventDefault(); $('dropzone').classList.remove('dragover'); });
$('dropzone').addEventListener('drop', e => { if (e.dataTransfer.files.length > 1) status('A versão 0.1 processa uma imagem por vez.'); else loadFile(e.dataTransfer.files[0]); });
$('demo').addEventListener('click', async () => { if (busy) return; try { const response = await fetch('./demo.jpg'); if (!response.ok) throw new Error('Arquivo de demonstração indisponível.'); const blob = await response.blob(); await loadFile(new File([blob], 'amostra-sintetica.jpg', { type: 'image/jpeg' })); } catch(e) { status(e.message, true); } });
window.addEventListener('pagehide', () => { if (current?.url) URL.revokeObjectURL(current.url); if (result?.url) URL.revokeObjectURL(result.url); worker.terminate(); });
