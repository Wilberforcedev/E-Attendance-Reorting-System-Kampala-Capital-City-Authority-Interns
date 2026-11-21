import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const output = path.join(root, 'dist');
const sources = [
  ['ears-system/frontend', 'ears-system/frontend'],
  ['ears-system/frontend/assets', 'assets'],
];

for (const [source] of sources) {
  try {
    await fs.access(path.join(root, source));
  } catch {
    throw new Error(`Required frontend directory is missing: ${source}`);
  }
}

await fs.rm(output, { recursive: true, force: true });
await fs.mkdir(output, { recursive: true });

for (const [source, destination] of sources) {
  await fs.cp(path.join(root, source), path.join(output, destination), { recursive: true });
}

console.log(`Production static build created at ${path.relative(root, output)}`);
