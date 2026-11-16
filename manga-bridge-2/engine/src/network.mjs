import { normalizedHttps } from './validate.mjs';

// NOT connected to the WordPress site. A strict hostname allowlist and explicit per-call bound are mandatory.
export async function fetchBounded(url, { allowedHosts, fetchImpl = fetch, timeoutMs = 8000, maxBytes = 1048576 } = {}) {
  const safe = normalizedHttps(url);
  if (!safe) throw new Error('UNSAFE_URL');
  const parsed = new URL(safe);
  if (!Array.isArray(allowedHosts) || !allowedHosts.includes(parsed.hostname)) throw new Error('HOST_NOT_ALLOWED');
  if (!Number.isInteger(timeoutMs) || timeoutMs < 100 || timeoutMs > 15000) throw new Error('INVALID_TIMEOUT');
  if (!Number.isInteger(maxBytes) || maxBytes < 1 || maxBytes > 2097152) throw new Error('INVALID_SIZE_LIMIT');
  const response = await fetchImpl(safe, { method: 'GET', redirect: 'manual',
    signal: AbortSignal.timeout(timeoutMs), headers: { 'User-Agent': 'MangaBridge2/0.1 (+controlled diagnostic)', 'Accept': 'text/html,application/json' } });
  if (response.status >= 300 && response.status < 400) throw new Error('REDIRECT_BLOCKED');
  if (!response.ok) throw new Error('HTTP_' + response.status);
  const len = Number(response.headers.get('content-length'));
  if (Number.isFinite(len) && len > maxBytes) throw new Error('BODY_TOO_LARGE');
  const reader = response.body?.getReader();
  if (!reader) throw new Error('EMPTY_BODY');
  let total = 0;
  const chunks = [];
  try {
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > maxBytes) throw new Error('BODY_TOO_LARGE');
      chunks.push(value);
    }
  } finally { await reader.cancel().catch(() => {}); }
  return Buffer.concat(chunks).toString('utf8');
}
