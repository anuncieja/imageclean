import { createHash } from 'node:crypto';
import { isIP } from 'node:net';

const MAX_CHAPTERS = 1000;
const MAX_PAGES = 400;
const MAX_TOTAL_PAGES = 100000;
const hash = value => createHash('sha256').update(value).digest('hex');
const saneText = (value, max) => typeof value === 'string' && value.trim().length > 0 && value.length <= max;

export function normalizedHttps(value) {
  if (typeof value !== 'string' || value.length > 2048) return null;
  try {
    const url = new URL(value);
    const host = url.hostname.toLowerCase();
    if (url.protocol !== 'https:' || url.username || url.password || !host ||
        host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local') ||
        host.endsWith('.internal') || isIP(host) !== 0) return null;
    url.hash = '';
    return url.href;
  } catch { return null; }
}

export function validateJob(raw) {
  const issues = [];
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    return { status: 'blocked', issues: [{ code: 'INVALID_INPUT' }] };
  }
  const id = raw.jobId;
  if (typeof id !== 'string' || !/^[a-zA-Z0-9_-]{3,80}$/.test(id)) {
    issues.push({ code: 'INVALID_JOB_ID' });
  }
  if (raw.schemaVersion !== 1) issues.push({ code: 'SCHEMA_UNSUPPORTED' });
  const m = raw.manga;
  if (!m || typeof m !== 'object' || Array.isArray(m)) {
    issues.push({ code: 'MANGA_MISSING' });
    return { status: 'blocked', issues };
  }
  const title = saneText(m.title, 250) ? m.title.trim() : null;
  if (!title) issues.push({ code: 'TITLE_MISSING' });
  const sourceUrl = normalizedHttps(m.sourceUrl);
  if (!sourceUrl) issues.push({ code: 'SOURCE_URL_INVALID' });
  const expected = m.expectedChapters;
  if (!Number.isSafeInteger(expected) || expected < 1 || expected > MAX_CHAPTERS) {
    issues.push({ code: 'EXPECTED_COUNT_INVALID' });
  }
  const chapters = Array.isArray(m.chapters) ? m.chapters : null;
  if (!chapters || chapters.length > MAX_CHAPTERS || chapters.length === 0) {
    issues.push({ code: 'CHAPTER_LIST_INVALID' });
  }
  if (chapters && Number.isInteger(expected) && chapters.length !== expected) {
    issues.push({ code: 'CHAPTER_LIST_PARTIAL', found: chapters.length, expected });
  }
  const validated = [];
  const numbers = new Set();
  const urls = new Set();
  let pages = 0;
  for (const [i, chapter] of (chapters || []).slice(0, MAX_CHAPTERS).entries()) {
    if (!chapter || typeof chapter !== 'object' || Array.isArray(chapter)) {
      issues.push({ code: 'CHAPTER_INVALID', index: i });
      continue;
    }
    const number = typeof chapter.number === 'string' ? chapter.number.trim() : String(chapter.number ?? '');
    const chapterUrl = normalizedHttps(chapter.sourceUrl);
    if (!/^[\d]+(?:\.[\d]+)?$/.test(number) || number.length > 15 || numbers.has(number)) {
      issues.push({ code: 'CHAPTER_NUMBER_INVALID_OR_DUPLICATE', index: i });
    }
    numbers.add(number);
    if (!chapterUrl || urls.has(chapterUrl)) issues.push({ code: 'CHAPTER_URL_INVALID_OR_DUPLICATE', index: i });
    if (chapterUrl) urls.add(chapterUrl);
    const images = chapter.images;
    if (!Array.isArray(images) || images.length < 1 || images.length > MAX_PAGES) {
      issues.push({ code: 'IMAGE_LIST_INVALID', index: i });
      continue;
    }
    pages += images.length;
    const checked = images.map(normalizedHttps);
    if (checked.includes(null) || new Set(checked).size !== checked.length) {
      issues.push({ code: 'IMAGE_URL_INVALID_OR_DUPLICATE', index: i });
    }
    if (chapterUrl) validated.push({ number, sourceUrl: chapterUrl,
      images: checked, chapterKey: hash(chapterUrl).slice(0, 24) });
  }
  if (pages > MAX_TOTAL_PAGES) issues.push({ code: 'PAGE_LIMIT_EXCEEDED' });
  if (issues.length > 0) return { status: 'blocked', issues };
  const normalized = { schemaVersion: 1, jobId: id, manga: {
    title, sourceUrl, expectedChapters: expected, chapters: validated,
    mangaKey: hash(sourceUrl).slice(0, 24)
  }};
  return { status: 'ready', issues: [], normalized, digest: hash(JSON.stringify(normalized)) };
}
