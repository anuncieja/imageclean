import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { inspect, sanitize, sha256, publicReport } from '../web/engine.js';
const [action, input, output, categories = 'EXIF'] = process.argv.slice(2);
try {
  if (!input || !['inspect', 'clean'].includes(action)) throw new Error('Uso: node scripts/cli.mjs inspect arquivo | clean entrada saída EXIF,XMP');
  const bytes = new Uint8Array(await readFile(input));
  if (action === 'inspect') console.log(JSON.stringify({ name: path.basename(input), sha256: await sha256(bytes), ...publicReport(inspect(bytes)) }, null, 2));
  else {
    if (!output || path.resolve(input) === path.resolve(output)) throw new Error('Escolha uma saída diferente da entrada.');
    const result = sanitize(bytes, categories.split(','));
    await writeFile(output, result.bytes, { flag: 'wx' });
    console.log(JSON.stringify({ output, sha256: await sha256(result.bytes), before: publicReport(result.before), after: publicReport(result.after), removed: result.removed, imageDataUnchanged: result.imageDataUnchanged }, null, 2));
  }
} catch(error) { console.error(error.message); process.exitCode = 1; }
