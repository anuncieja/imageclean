/** ImageClean Lab 0.1 — bounded container inspection, not a forensic detector. */
export const LIMITS = Object.freeze({ bytes: 30 * 1024 * 1024, pixels: 24_000_000, records: 8192 });
const ascii = (b, a, z) => String.fromCharCode(...b.subarray(a, Math.min(z, a + 4096)));
const view = b => new DataView(b.buffer, b.byteOffset, b.byteLength);
const be32 = (b, p) => view(b).getUint32(p);
const le32 = (b, p) => view(b).getUint32(p, true);
const starts = (b, p, s) => [...s].every((c, i) => b[p + i] === c.charCodeAt(0));
function need(ok, msg) { if (!ok) throw new Error(msg); }
function join(parts) {
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let p = 0; for (const part of parts) { out.set(part, p); p += part.length; } return out;
}
export function crc32(bytes) {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc ^= byte;
    for (let i = 0; i < 8; i++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function exifInfo(payload) {
  const b = starts(payload, 0, 'Exif\0\0') ? payload.subarray(6) : payload;
  const result = { readable: false, orientation: null, gpsPointer: false, fields: {} };
  try {
    need(b.length >= 8, 'TIFF curto');
    const little = starts(b, 0, 'II');
    need(little || starts(b, 0, 'MM'), 'Ordem TIFF inválida');
    const d = view(b), u16 = p => d.getUint16(p, little), u32 = p => d.getUint32(p, little);
    need(u16(2) === 42, 'TIFF inválido');
    const seen = new Set(); let count = 0;
    const names = { 0x010f: 'Fabricante', 0x0110: 'Modelo', 0x0131: 'Software', 0x0132: 'Data', 0x9003: 'Data original', 0x013b: 'Autor', 0x8298: 'Copyright' };
    function walk(p, depth) {
      if (!p) return;
      need(depth <= 4 && !seen.has(p), 'Ciclo ou profundidade EXIF'); seen.add(p);
      need(p + 2 <= b.length, 'IFD fora do arquivo');
      const n = u16(p); count += n;
      need(count <= 1024 && p + 2 + n * 12 + 4 <= b.length, 'IFD truncado');
      for (let i = 0; i < n; i++) {
        const a = p + 2 + i * 12, tag = u16(a), type = u16(a + 2), size = u32(a + 4);
        if (tag === 0x0112) {
          need(type === 3 && size === 1, 'Orientação inválida');
          result.orientation = u16(a + 8);
          need(result.orientation >= 1 && result.orientation <= 8, 'Orientação inválida');
        }
        if (tag === 0x8825) result.gpsPointer = true;
        if (tag === 0x8769 || tag === 0x8825) {
          need(type === 4 && size === 1, 'Ponteiro inválido'); walk(u32(a + 8), depth + 1);
        }
        if (names[tag] && type === 2) {
          need(size <= 65536, 'Campo EXIF grande');
          const off = size <= 4 ? a + 8 : u32(a + 8);
          need(off + size <= b.length, 'Texto EXIF truncado');
          result.fields[names[tag]] = ascii(b, off, off + Math.min(size, 512)).replace(/\0+$/, '');
        }
      }
      const next = u32(p + 2 + n * 12);
      if (next) walk(next, depth + 1);
    }
    walk(u32(4), 0); result.readable = true;
  } catch { result.readable = false; }
  return result;
}
function categoryJPEG(marker, data) {
  if (marker === 0xe1 && starts(data, 0, 'Exif\0\0')) return 'EXIF';
  if (marker === 0xe1 && (starts(data, 0, 'http://ns.adobe.com/xap/1.0/\0') || starts(data, 0, 'http://ns.adobe.com/xmp/extension/\0'))) return 'XMP';
  if (marker === 0xed && starts(data, 0, 'Photoshop 3.0\0')) return 'IPTC/Photoshop';
  if (marker === 0xfe) return 'Comentários';
  if (marker === 0xeb) return 'JUMBF/APP11';
  return null;
}
function jpeg(b) {
  const records = []; let p = 2, width = 0, height = 0, ended = false, trailing = false, scan = false;
  while (p < b.length) {
    need(records.length < LIMITS.records, 'Muitos segmentos JPEG');
    const start = p; need(b[p] === 255, 'Marcador JPEG inválido');
    while (b[p] === 255) p++;
    need(p < b.length, 'JPEG truncado'); const marker = b[p++];
    if (marker === 0xd9) { records.push({ start, end: p, type: 'EOI' }); ended = true; trailing = p !== b.length; break; }
    need(marker !== 0 && marker !== 0xd8 && !(marker >= 0xd0 && marker <= 0xd7), 'Marcador JPEG inesperado');
    if (marker === 0x01) { records.push({ start, end: p, type: 'TEM' }); continue; }
    need(p + 2 <= b.length, 'Segmento JPEG truncado');
    const length = (b[p] << 8) | b[p + 1]; need(length >= 2 && p + length <= b.length, 'Tamanho JPEG inválido');
    const ds = p + 2, end = p + length, data = b.subarray(ds, end), category = categoryJPEG(marker, data);
    records.push({ start, end, ds, de: end, type: `FF${marker.toString(16).toUpperCase()}`, category });
    if ([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf].includes(marker)) {
      need(data.length >= 6, 'SOF inválido'); height = (data[1] << 8) | data[2]; width = (data[3] << 8) | data[4];
    }
    p = end;
    if (marker === 0xda) {
      scan = true; const scanStart = p;
      while (p < b.length) {
        if (b[p] !== 255) { p++; continue; }
        let q = p + 1; while (b[q] === 255) q++;
        need(q < b.length, 'Varredura JPEG truncada');
        if (b[q] === 0 || (b[q] >= 0xd0 && b[q] <= 0xd7)) { p = q + 1; continue; }
        break;
      }
      records.push({ start: scanStart, end: p, type: 'Dados de imagem' });
    }
  }
  need(ended && scan && width && height, 'JPEG incompleto ou não suportado');
  return { records, width, height, animated: false, trailing };
}
function png(b) {
  const records = []; let p = 8, width = 0, height = 0, ended = false, idat = false, animated = false;
  while (p < b.length) {
    need(records.length < LIMITS.records && p + 12 <= b.length, 'PNG truncado ou com muitos blocos');
    const length = be32(b, p), type = ascii(b, p + 4, p + 8), ds = p + 8, de = ds + length, end = de + 4;
    need(end <= b.length, 'Tamanho PNG inválido');
    need(crc32(b.subarray(p + 4, de)) === be32(b, de), `CRC PNG inválido: ${type}`);
    need(records.length || type === 'IHDR', 'PNG sem IHDR inicial');
    let category = type === 'eXIf' ? 'EXIF' : type === 'caBX' ? 'C2PA/caBX' : null;
    if (['tEXt', 'iTXt', 'zTXt'].includes(type)) {
      const zero = b.subarray(ds, de).indexOf(0); need(zero > 0 && zero <= 79, 'Palavra-chave PNG inválida');
      category = ascii(b, ds, ds + zero) === 'XML:com.adobe.xmp' ? 'XMP' : 'Texto PNG';
    }
    records.push({ start: p, end, ds, de, type, category });
    if (type === 'IHDR') { need(length === 13 && records.length === 1, 'IHDR inválido'); width = be32(b, ds); height = be32(b, ds + 4); }
    if (type === 'IDAT') idat = true;
    if (type === 'acTL') animated = true;
    p = end;
    if (type === 'IEND') { need(length === 0, 'IEND inválido'); ended = true; break; }
  }
  need(ended && idat && width && height, 'PNG incompleto');
  return { records, width, height, animated, trailing: p !== b.length };
}
function webp(b) {
  need(b.length >= 20 && le32(b, 4) + 8 === b.length, 'Tamanho RIFF inválido');
  const records = []; let p = 12, width = 0, height = 0, animated = false, image = false;
  while (p < b.length) {
    need(records.length < LIMITS.records && p + 8 <= b.length, 'WebP truncado');
    const type = ascii(b, p, p + 4), length = le32(b, p + 4), ds = p + 8, de = ds + length, end = de + (length % 2);
    need(end <= b.length, 'Bloco WebP truncado');
    const category = type === 'EXIF' ? 'EXIF' : type === 'XMP ' ? 'XMP' : type === 'C2PA' ? 'C2PA' : null;
    records.push({ start: p, end, ds, de, type, category });
    if (type === 'VP8X') {
      need(length === 10 && records.length === 1, 'VP8X inválido');
      width = 1 + b[ds + 4] + (b[ds + 5] << 8) + (b[ds + 6] << 16);
      height = 1 + b[ds + 7] + (b[ds + 8] << 8) + (b[ds + 9] << 16); animated = Boolean(b[ds] & 2);
    }
    if (type === 'VP8 ') {
      image = true; need(length >= 10 && b[ds + 3] === 0x9d && b[ds + 4] === 1 && b[ds + 5] === 0x2a, 'VP8 inválido');
      if (!width) { width = (b[ds + 6] | (b[ds + 7] << 8)) & 0x3fff; height = (b[ds + 8] | (b[ds + 9] << 8)) & 0x3fff; }
    }
    if (type === 'VP8L') {
      image = true; need(length >= 5 && b[ds] === 0x2f, 'VP8L inválido');
      const bits = le32(b, ds + 1);
      if (!width) { width = (bits & 0x3fff) + 1; height = ((bits >>> 14) & 0x3fff) + 1; }
    }
    if (type === 'ANIM' || type === 'ANMF') animated = true;
    p = end;
  }
  need((image || animated) && width && height, 'WebP incompleto');
  return { records, width, height, animated, trailing: false };
}
export function inspect(input) {
  const b = input instanceof Uint8Array ? input : new Uint8Array(input);
  need(b.length > 0 && b.length <= LIMITS.bytes, 'O arquivo deve ter até 30 MiB.');
  let format, parsed;
  if (b[0] === 255 && b[1] === 216) { format = 'JPEG'; parsed = jpeg(b); }
  else if (b.length >= 8 && [137,80,78,71,13,10,26,10].every((x,i) => b[i] === x)) { format = 'PNG'; parsed = png(b); }
  else if (starts(b, 0, 'RIFF') && starts(b, 8, 'WEBP')) { format = 'WebP'; parsed = webp(b); }
  else throw new Error('Formato não suportado. Use JPEG, PNG ou WebP.');
  need(parsed.width * parsed.height <= LIMITS.pixels, 'Limite de 24 milhões de pixels excedido.');
  const metadata = parsed.records.filter(r => r.category).map(r => ({ category: r.category, container: r.type, bytes: r.end - r.start, ...(r.category === 'EXIF' ? { exif: exifInfo(b.subarray(r.ds, r.de)) } : {}) }));
  const provenance = metadata.some(m => ['JUMBF/APP11', 'C2PA/caBX', 'C2PA'].includes(m.category));
  return { format, bytes: b.length, ...parsed, metadata, provenance, c2pa: provenance ? 'Contêiner de procedência encontrado ou possível; assinatura não verificada.' : 'Nenhum contêiner de procedência reconhecido nesta inspeção limitada.', synthid: 'Não verificado: nenhum detector integrado.', warnings: [...(parsed.animated ? ['Imagem animada: somente inspeção nesta versão.'] : []), ...(parsed.trailing ? ['Há dados após o fim da imagem: processamento bloqueado.'] : []), ...(metadata.some(m => m.exif && !m.exif.readable) ? ['EXIF não interpretado integralmente.'] : [])] };
}
export function assertEditable(report) {
  need(!report.provenance, 'Processamento bloqueado: há um contêiner de procedência reconhecido ou possível. A versão 0.1 não revalida assinaturas C2PA.');
  need(!report.animated, 'Imagens animadas têm somente inspeção nesta versão.');
  need(!report.trailing, 'Há dados adicionais após a imagem. Somente inspeção.');
}
export function sanitize(input, categories = ['EXIF']) {
  const b = input instanceof Uint8Array ? input : new Uint8Array(input), before = inspect(b);
  assertEditable(before);
  const allowed = new Set(['EXIF', 'XMP', 'IPTC/Photoshop', 'Comentários', 'Texto PNG']);
  need(categories.every(c => allowed.has(c)), 'Categoria de limpeza inválida.');
  const selected = new Set(categories), exifs = before.metadata.filter(m => m.exif);
  if (selected.has('EXIF')) for (const m of exifs) {
    need(m.exif.readable, 'EXIF não interpretado: remoção bloqueada para proteger a orientação.');
    need(m.exif.orientation === null || m.exif.orientation === 1, 'A orientação EXIF exige transformação visual. Use a conversão orientada.');
  }
  const kept = before.records.filter(r => !selected.has(r.category));
  let bytes;
  if (before.format === 'JPEG') bytes = join([b.subarray(0, 2), ...kept.map(r => b.subarray(r.start, r.end))]);
  else if (before.format === 'PNG') bytes = join([b.subarray(0, 8), ...kept.map(r => b.subarray(r.start, r.end))]);
  else {
    bytes = join([b.subarray(0, 12), ...kept.map(r => b.subarray(r.start, r.end))]);
    view(bytes).setUint32(4, bytes.length - 8, true);
    const interim = inspect(bytes), vp8x = interim.records.find(r => r.type === 'VP8X');
    if (vp8x) {
      if (!interim.metadata.some(m => m.category === 'EXIF')) bytes[vp8x.ds] &= ~8;
      if (!interim.metadata.some(m => m.category === 'XMP')) bytes[vp8x.ds] &= ~4;
    }
  }
  const after = inspect(bytes);
  need(!after.metadata.some(m => selected.has(m.category)), 'A inspeção posterior encontrou uma categoria selecionada.');
  return { bytes, before, after, removed: before.metadata.filter(m => selected.has(m.category)), imageDataUnchanged: true };
}
export async function sha256(bytes) {
  return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), b => b.toString(16).padStart(2, '0')).join('');
}
export function publicReport(report) {
  const { records, ...publicFields } = report; return publicFields;
}
