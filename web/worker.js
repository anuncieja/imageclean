import { inspect, sanitize, sha256, publicReport } from './engine.js';
self.onmessage = async ({ data }) => {
  try {
    const bytes = new Uint8Array(data.bytes);
    if (data.action === 'inspect') {
      self.postMessage({ id: data.id, report: publicReport(inspect(bytes)), hash: await sha256(bytes) });
    } else if (data.action === 'sanitize') {
      const result = sanitize(bytes, data.categories);
      self.postMessage({ id: data.id, report: publicReport(result.after), removed: result.removed, hash: await sha256(result.bytes), bytes: result.bytes.buffer }, [result.bytes.buffer]);
    } else throw new Error('Operação desconhecida.');
  } catch (error) { self.postMessage({ id: data.id, error: error.message }); }
};
