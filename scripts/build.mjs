import { cp, mkdir, rm } from 'node:fs/promises';
const destination = new URL('../dist/', import.meta.url);
await rm(destination, { recursive: true, force: true });
await mkdir(destination, { recursive: true });
await cp(new URL('../web/', import.meta.url), destination, { recursive: true });
console.log('Build estático pronto em dist/.');
