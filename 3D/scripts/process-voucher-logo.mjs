/**
 * BCUT-style logo prep: white background -> transparent, plus a dark-theme variant.
 *
 * Usage: node scripts/process-voucher-logo.mjs [input] [outDir]
 * Default input: ../assets/images/Untitled.jpg, default outDir: ../assets/images
 * Outputs: <name>-cutout.png (original colors) and <name>-dark.png (dark ink turned light)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const repoRoot = path.resolve(root, '..');
const require = createRequire(import.meta.url);
const { PNG } = require('pngjs');
const jpeg = require('jpeg-js');

const inputArg = path.resolve(process.argv[2] || path.join(repoRoot, 'assets', 'images', 'Untitled.jpg'));
const outArg = process.argv[3] ? path.resolve(process.argv[3]) : '';
const MAX_WIDTH = 1200;

function decode(file) {
  const buf = fs.readFileSync(file);
  if (buf[0] === 0xff && buf[1] === 0xd8) {
    const img = jpeg.decode(buf, { useTArray: true, maxMemoryUsageInMB: 1024 });
    return { width: img.width, height: img.height, data: Buffer.from(img.data) };
  }
  const png = PNG.sync.read(buf);
  return { width: png.width, height: png.height, data: Buffer.from(png.data) };
}

function downscale({ width, height, data }) {
  if (width <= MAX_WIDTH) return { width, height, data };
  const f = Math.ceil(width / MAX_WIDTH);
  const w = Math.floor(width / f);
  const h = Math.floor(height / f);
  const out = Buffer.alloc(w * h * 4);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      let r = 0, g = 0, b = 0, a = 0;
      for (let dy = 0; dy < f; dy++) {
        for (let dx = 0; dx < f; dx++) {
          const i = ((y * f + dy) * width + (x * f + dx)) * 4;
          r += data[i]; g += data[i + 1]; b += data[i + 2]; a += data[i + 3];
        }
      }
      const n = f * f;
      const o = (y * w + x) * 4;
      out[o] = r / n; out[o + 1] = g / n; out[o + 2] = b / n; out[o + 3] = a / n;
    }
  }
  return { width: w, height: h, data: out };
}

// "Color to alpha" against white: exact soft edges, no white fringe.
function whiteToAlpha({ width, height, data }) {
  const out = Buffer.alloc(data.length);
  for (let i = 0; i < data.length; i += 4) {
    const r = data[i], g = data[i + 1], b = data[i + 2];
    const a = Math.max(255 - r, 255 - g, 255 - b) / 255;
    if (a < 0.04) continue;
    out[i] = Math.max(0, Math.min(255, Math.round((r - 255 * (1 - a)) / a)));
    out[i + 1] = Math.max(0, Math.min(255, Math.round((g - 255 * (1 - a)) / a)));
    out[i + 2] = Math.max(0, Math.min(255, Math.round((b - 255 * (1 - a)) / a)));
    out[i + 3] = Math.round(a * 255);
  }
  return { width, height, data: out };
}

// Dark/neutral ink becomes light; saturated brand colors are kept.
function toDarkTheme({ width, height, data }) {
  const out = Buffer.from(data);
  for (let i = 0; i < out.length; i += 4) {
    if (out[i + 3] === 0) continue;
    const r = out[i], g = out[i + 1], b = out[i + 2];
    const max = Math.max(r, g, b), min = Math.min(r, g, b);
    const sat = max === 0 ? 0 : (max - min) / max;
    const lum = 0.299 * r + 0.587 * g + 0.114 * b;
    if ((sat < 0.3 && lum < 150) || lum < 70) {
      const t = 1 - lum / (lum < 70 && sat >= 0.3 ? 70 : 150);
      out[i] = Math.round(r + (233 - r) * t);
      out[i + 1] = Math.round(g + (237 - g) * t);
      out[i + 2] = Math.round(b + (239 - b) * t);
    }
  }
  return { width, height, data: out };
}

function trim({ width, height, data }) {
  let x0 = width, y0 = height, x1 = -1, y1 = -1;
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (data[(y * width + x) * 4 + 3] > 12) {
        if (x < x0) x0 = x; if (x > x1) x1 = x;
        if (y < y0) y0 = y; if (y > y1) y1 = y;
      }
    }
  }
  if (x1 < 0) return { width, height, data };
  const pad = 4;
  x0 = Math.max(0, x0 - pad); y0 = Math.max(0, y0 - pad);
  x1 = Math.min(width - 1, x1 + pad); y1 = Math.min(height - 1, y1 + pad);
  const w = x1 - x0 + 1, h = y1 - y0 + 1;
  const out = Buffer.alloc(w * h * 4);
  for (let y = 0; y < h; y++) {
    data.copy(out, y * w * 4, ((y0 + y) * width + x0) * 4, ((y0 + y) * width + x1 + 1) * 4);
  }
  return { width: w, height: h, data: out };
}

function write(file, { width, height, data }) {
  const png = new PNG({ width, height });
  data.copy(png.data);
  fs.writeFileSync(file, PNG.sync.write(png, { colorType: 6 }));
  console.log('Wrote', file, `${width}x${height}`);
}

function processFile(input) {
  const outDir = outArg || path.dirname(input);
  const name = path.basename(input, path.extname(input));
  fs.mkdirSync(outDir, { recursive: true });
  const cutout = trim(whiteToAlpha(downscale(decode(input))));
  write(path.join(outDir, `${name}-cutout.png`), cutout);
  write(path.join(outDir, `${name}-dark.png`), toDarkTheme(cutout));
}

if (!fs.existsSync(inputArg)) {
  console.error('Missing input:', inputArg);
  process.exit(1);
}

if (fs.statSync(inputArg).isDirectory()) {
  const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = path.join(dir, e.name);
    return e.isDirectory() ? walk(p) : [p];
  });
  walk(inputArg)
    .filter((f) => /\.(png|jpe?g)$/i.test(f) && !/-(cutout|dark)\.png$/i.test(f))
    .forEach((f) => {
      try { processFile(f); } catch (err) { console.error('Skipped', f, err.message); }
    });
} else {
  processFile(inputArg);
}
