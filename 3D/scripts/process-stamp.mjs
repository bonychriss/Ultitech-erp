/**
 * BCUT-style stamp cleanup: remove paper background, keep blue ink.
 * Usage: node scripts/process-stamp.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const require = createRequire(import.meta.url);

// Prefer already-processed white stamp from letterhead; this script documents the pipeline.
const whiteSrc = path.resolve(root, '../letterhead/stamps/ultimate-stamp-white.png');
const cutoutSrc = path.resolve(root, '../letterhead/stamps/ultimate-stamp-cutout.png');
const publicDir = path.join(root, 'public');

for (const src of [whiteSrc, cutoutSrc]) {
  if (!fs.existsSync(src)) {
    console.error('Missing:', src);
    process.exit(1);
  }
  const dest = path.join(publicDir, path.basename(src));
  fs.copyFileSync(src, dest);
  console.log('Copied', path.basename(src), '->', dest);
}

console.log('Open BCUT (npm run dev) and load public/ultimate-stamp-white.png to refine further if needed.');
