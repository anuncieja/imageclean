import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { inspect, sanitize, crc32, sha256, publicReport } from '../web/engine.js';
const fixture = async name => new Uint8Array(await readFile(new URL(`./fixtures/${name}`, import.meta.url)));
const concat = (...parts) => { const out = new Uint8Array(parts.reduce((a,b) => a+b.length,0)); let p=0; for(const b of parts){out.set(b,p);p+=b.length;} return out; };
function imagePayload(bytes, report) {
  const relevant = report.records.filter(r => report.format === 'JPEG' ? r.type === 'Dados de imagem' : report.format === 'PNG' ? ['IDAT','IHDR','tRNS','iCCP','sRGB','gAMA','cHRM'].includes(r.type) : ['VP8 ', 'VP8L', 'ALPH', 'ICCP'].includes(r.type));
  return concat(...relevant.map(r => bytes.subarray(r.start,r.end)));
}
for (const name of ['metadata.jpg', 'progressive.jpg', 'metadata.png', 'metadata.webp']) {
  test(`${name}: inspect fields, sanitize and preserve coded image payload`, async () => {
    const input = await fixture(name), before = inspect(input);
    assert.equal(before.width,48); assert.equal(before.height,32);
    const exif = before.metadata.find(m=>m.category==='EXIF').exif;
    assert.equal(exif.readable,true); assert.equal(exif.orientation,1); assert.equal(exif.gpsPointer,true);
    assert.equal(exif.fields.Software,'ImageClean Lab Test');
    const clean = sanitize(input,['EXIF','XMP','Texto PNG']);
    assert.ok(clean.bytes.length<input.length); assert.equal(clean.after.metadata.length,0);
    assert.deepEqual(imagePayload(input,before), imagePayload(clean.bytes,clean.after));
    assert.equal(await sha256(clean.bytes), await sha256(sanitize(clean.bytes,['EXIF','XMP','Texto PNG']).bytes));
  });
}
test('selective categories preserve XMP and PNG text', async()=>{
  const clean=sanitize(await fixture('metadata.png'),['EXIF']);
  assert.ok(clean.after.metadata.some(m=>m.category==='XMP'));
  assert.ok(clean.after.metadata.some(m=>m.category==='Texto PNG'));
});
test('WebP feature flags and RIFF length are repaired', async()=>{
  const out=sanitize(await fixture('metadata.webp'),['EXIF','XMP']);
  const data=new DataView(out.bytes.buffer), vp8x=out.after.records.find(r=>r.type==='VP8X');
  assert.equal(data.getUint32(4,true)+8,out.bytes.length);
  assert.equal(out.bytes[vp8x.ds]&12,0);
});
test('orientation 6 is inspected and EXIF removal blocked',async()=>{
  const bytes=await fixture('rotated.jpg');
  assert.equal(inspect(bytes).metadata.find(m=>m.exif).exif.orientation,6);
  assert.throws(()=>sanitize(bytes,['EXIF']),/orientação/i);
  assert.doesNotThrow(()=>sanitize(bytes,['Comentários']));
});
test('PNG C2PA-like container blocks transformations without asserting valid signature',async()=>{
  const bytes=await fixture('provenance.png'),report=inspect(bytes);
  assert.equal(report.provenance,true); assert.match(report.c2pa,/não verificada/);
  assert.throws(()=>sanitize(bytes,['EXIF']),/procedência/);
});
test('APP11 is treated conservatively, not certified as C2PA',async()=>{
  const b=await fixture('metadata.jpg'),out=concat(b.subarray(0,2),Uint8Array.from([255,235,0,6,74,80,0,1]),b.subarray(2));
  assert.equal(inspect(out).provenance,true); assert.throws(()=>sanitize(out),/procedência/);
});
test('plain image is unchanged',async()=>{
  const b=await fixture('plain.png'); assert.equal(inspect(b).metadata.length,0); assert.deepEqual(sanitize(b).bytes,b);
});
test('animation recognized and blocked',async()=>{
  for(const name of ['animated.webp','animated.png']){const b=await fixture(name);assert.equal(inspect(b).animated,true);assert.throws(()=>sanitize(b),/animadas/);}
});
test('corrupt PNG checksum rejected',async()=>{
  const b=await fixture('plain.png'); b[20]^=1; assert.throws(()=>inspect(b),/CRC/);
});
test('excessive dimensions rejected before image decode',async()=>{
  const b=await fixture('plain.png'),v=new DataView(b.buffer);v.setUint32(16,100000);v.setUint32(20,100000);v.setUint32(29,crc32(b.subarray(12,29)));assert.throws(()=>inspect(b),/pixels/);
});
test('trailing data detected and blocks cleaning',async()=>{
  for(const name of ['plain.png','metadata.jpg']){const b=concat(await fixture(name),Uint8Array.of(42));assert.equal(inspect(b).trailing,true);assert.throws(()=>sanitize(b),/adicionais/);}
});
test('truncation, invalid signatures and unsupported bytes rejected',async()=>{
  assert.throws(()=>inspect(new Uint8Array()),/30 MiB/);
  assert.throws(()=>inspect(new TextEncoder().encode('<script>bad</script>')),/Formato/);
  for(const name of ['metadata.jpg','metadata.png','metadata.webp']){const b=await fixture(name);assert.throws(()=>inspect(b.subarray(0,b.length-7)));}
});
test('invalid cleanup category rejected',async()=>assert.throws(()=>sanitize(awaitedPlain,['C2PA']),/Categoria/));
const awaitedPlain=await fixture('plain.png');
test('SHA256 and serializable reports',async()=>{
  assert.equal(await sha256(Uint8Array.of(97,98,99)),'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
  assert.equal('records' in publicReport(inspect(awaitedPlain)),false);
  assert.match(publicReport(inspect(awaitedPlain)).synthid,/Não verificado/);
});
test('bounded random malformed inputs never hang',()=>{
  let seed=17;for(let n=0;n<400;n++){const b=new Uint8Array(1+n%127);for(let i=0;i<b.length;i++){seed=(seed*1664525+1013904223)>>>0;b[i]=seed>>>24;}try{inspect(b);}catch(e){assert.ok(e instanceof Error);}}
});
