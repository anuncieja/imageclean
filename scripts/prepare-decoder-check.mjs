import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { sanitize } from '../web/engine.js';
const root=new URL('../',import.meta.url);await mkdir(new URL('results/',root),{recursive:true});
for(const name of ['metadata.jpg','progressive.jpg','metadata.png','metadata.webp']){
  const bytes=new Uint8Array(await readFile(new URL(`test/fixtures/${name}`,root)));
  await writeFile(new URL(`results/${name}`,root),sanitize(bytes,['EXIF','XMP','Texto PNG']).bytes);
}
console.log('Quatro saídas preparadas para decodificação independente.');
