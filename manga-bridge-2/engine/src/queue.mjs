import { mkdir, open, readFile, rename, unlink, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

// Single-writer filesystem proof of concept. A production scheduler needs a shared transactional store.
export async function persistJob(dir, jobId, digest, status) {
  if (!/^[a-zA-Z0-9_-]{3,80}$/.test(jobId)) throw new Error('Invalid job ID');
  if (!/^[a-f0-9]{64}$/.test(digest)) throw new Error('Invalid digest');
  await mkdir(dir, { recursive: true, mode: 0o700 });
  const path = join(dir, jobId + '.json');
  let existing;
  try { existing = JSON.parse(await readFile(path, 'utf8')); }
  catch (e) { if (e.code !== 'ENOENT') throw e; }
  if (existing) {
    if (existing.digest !== digest) throw new Error('JOB_ID_CONFLICT');
    return { written: false, job: existing };
  }
  // Exclusively acquire a lock so concurrent runners cannot publish the same job twice.
  const lockPath = path + '.lock';
  let handle;
  try { handle = await open(lockPath, 'wx', 0o600); }
  catch (e) { if (e.code === 'EEXIST') throw new Error('JOB_BUSY'); throw e; }
  try {
    try {
      existing = JSON.parse(await readFile(path, 'utf8'));
      if (existing.digest !== digest) throw new Error('JOB_ID_CONFLICT');
      return { written: false, job: existing };
    } catch (e) { if (e.code !== 'ENOENT') throw e; }
    const job = { jobId, digest, status, updatedAt: new Date().toISOString() };
    const tmp = join(dir, jobId + '.tmp-' + process.pid);
    try { await writeFile(tmp, JSON.stringify(job, null, 2) + '\n', { mode: 0o600 }); await rename(tmp, path); }
    finally { await unlink(tmp).catch(() => {}); }
    return { written: true, job };
  } finally { await handle.close(); await unlink(lockPath); }
}
