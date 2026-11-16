#!/usr/bin/env node
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { validateJob } from './validate.mjs';
import { persistJob } from './queue.mjs';

function valueFor(name) { const i = process.argv.indexOf(name); return i >= 0 ? process.argv[i+1] : null; }
const input = valueFor('--input');
const output = valueFor('--out');
if (!input || !output) { console.error('Usage: node src/cli.mjs --input job.json --out report.json'); process.exit(2); }
try {
  const path = resolve(input);
  const { size } = await import('node:fs/promises').then(fs => fs.stat(path));
  if (size > 5 * 1024 * 1024) throw new Error('INPUT_TOO_LARGE');
  const payload = JSON.parse(await readFile(path, 'utf8'));
  const result = validateJob(payload);
  // Non-destructive dry run: never publishes, never contacts the origin or WordPress.
  const digest = result.digest || (await import('node:crypto')).createHash('sha256').update(JSON.stringify(payload)).digest('hex');
  const report = { jobId: payload.jobId || 'invalid', stage: 'validation_only', status: result.status,
    issues: result.issues, digest, allowPublish: false, chapters: result.normalized?.manga.chapters.length ?? 0,
    images: result.normalized?.manga.chapters.reduce((n,c)=>n+c.images.length,0) ?? 0 };
  await mkdir(dirname(resolve(output)), { recursive: true });
  await writeFile(resolve(output), JSON.stringify(report, null, 2) + '\n', { flag: 'wx' });
  if (result.status === 'ready') await persistJob(resolve(dirname(output), '.state'), payload.jobId, digest, 'ready');
  console.log(JSON.stringify(report));
  if (result.status !== 'ready') process.exitCode = 1;
} catch (err) { console.error('Pipeline error: ' + err.message); process.exitCode = 1; }
