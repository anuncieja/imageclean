import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { validateJob, normalizedHttps } from '../src/validate.mjs';
import { persistJob } from '../src/queue.mjs';
import { fetchBounded } from '../src/network.mjs';

const sample = JSON.parse(await readFile(new URL('../fixtures/demo-job.json', import.meta.url)));
const clone = o => JSON.parse(JSON.stringify(o));

test('complete manifest is ready with stable digest and keys', () => {
  const one = validateJob(sample), two = validateJob(sample);
  assert.equal(one.status, 'ready'); assert.equal(one.digest, two.digest);
  assert.equal(one.normalized.manga.chapters.length, 2);
});
test('blocks incomplete/paginated catalog instead of publishing it', () => {
  const data = clone(sample); data.manga.chapters.pop();
  assert.equal(validateJob(data).issues[0].code, 'CHAPTER_LIST_PARTIAL');
});
test('blocks duplicate chapter numbering', () => {
  const data = clone(sample); data.manga.chapters[1].number = '1';
  assert.equal(validateJob(data).status, 'blocked');
});
test('blocks repeated and missing pages', () => {
  const data = clone(sample); data.manga.chapters[0].images[1] = data.manga.chapters[0].images[0];
  assert.ok(validateJob(data).issues.some(e => e.code === 'IMAGE_URL_INVALID_OR_DUPLICATE'));
  data.manga.chapters[0].images = [];
  assert.ok(validateJob(data).issues.some(e => e.code === 'IMAGE_LIST_INVALID'));
});
test('blocks unsafe and invalid URLs', () => {
  for (const url of ['http://example.org/a', 'https://localhost/a', 'https://127.0.0.1/a', 'https://user:pass@example.org/a', 'file:///etc/passwd']) {
    assert.equal(normalizedHttps(url), null);
  }
});
test('strict idempotency: same job id + same digest cannot create duplicates', async () => {
  const dir = await mkdtemp(join(tmpdir(), 'mb2-test-'));
  try {
    const digest = 'a'.repeat(64);
    assert.equal((await persistJob(dir, 'job-123', digest, 'ready')).written, true);
    assert.equal((await persistJob(dir, 'job-123', digest, 'ready')).written, false);
    await assert.rejects(() => persistJob(dir, 'job-123', 'b'.repeat(64), 'ready'), /JOB_ID_CONFLICT/);
  } finally { await rm(dir, { recursive: true, force: true }); }
});
test('network rejects unapproved hosts without making a request', async () => {
  let called = false;
  await assert.rejects(() => fetchBounded('https://example.org/a', { allowedHosts: ['other.example'], fetchImpl: async () => { called = true; } }), /HOST_NOT_ALLOWED/);
  assert.equal(called, false);
});
test('network limits request size and refuses redirects', async () => {
  const redirect = async () => new Response('', { status: 302, headers: { location: 'https://example.org' } });
  await assert.rejects(() => fetchBounded('https://example.org/a', { allowedHosts: ['example.org'], fetchImpl: redirect }), /REDIRECT_BLOCKED/);
  const oversized = async () => new Response('x'.repeat(32), { status: 200 });
  await assert.rejects(() => fetchBounded('https://example.org/a', { allowedHosts: ['example.org'], fetchImpl: oversized, maxBytes: 16 }), /BODY_TOO_LARGE/);
});
test('network returns bounded content without retry loops', async () => {
  let calls = 0;
  const fake = async () => { calls++; return new Response('ok', { status: 200 }); };
  assert.equal(await fetchBounded('https://example.org/a', { allowedHosts: ['example.org'], fetchImpl: fake }), 'ok');
  assert.equal(calls, 1);
});
