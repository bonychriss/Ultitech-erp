/**
 * Ultimate General Trading logo ? transparent PNG via BCUT pipeline.
 *
 * 1) Rasterize the brand SVG (high-res)
 * 2) Composite on white (matches the "white_page" source)
 * 3) Remove background with @imgly/background-removal (same AI as BCUT)
 * 4) Fallback: white chroma-key (BCUT removeColorKey logic) if AI fails
 *
 * Usage: node scripts/process-ultimate-logo.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Resvg } from '@resvg/resvg-js';
import { createRequire } from 'node:module';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const repoRoot = path.resolve(root, '..');
const require = createRequire(import.meta.url);

const svgPath = path.join(
  repoRoot,
  'images',
  'ULTIMATE GENERAL LOGO.white_page-0001-cropped.svg'
);
const outDir = path.join(root, 'public');
const outCutout = path.join(outDir, 'ultimate-logo-cutout.png');
const outRepo = path.join(repoRoot, 'images', 'ultimate-logo-cutout.png');
const outLetterhead = path.join(repoRoot, 'letterhead', 'stamps', 'ultimate-logo-cutout.png');

function hexToRgb(hex) {
  let clean = String(hex || '').replace(/^#/, '');
  if (clean.length === 3) clean = clean.split('').map((c) => c + c).join('');
  const num = parseInt(clean, 16);
  return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 };
}

function colorDistanceRGB(r1, g1, b1, r2, g2, b2) {
  const dr = (r1 - r2) / 255;
  const dg = (g1 - g2) / 255;
  const db = (b1 - b2) / 255;
  return Math.sqrt(0.3 * dr * dr + 0.59 * dg * dg + 0.11 * db * db);
}

/** Decode PNG ? { width, height, data: RGBA Buffer } using pngjs from imgly deps if present */
function decodePng(buf) {
  let PNG;
  try {
    PNG = require('pngjs').PNG;
  } catch {
    // pngjs may be nested under imgly
    PNG = require(path.join(root, 'node_modules', 'pngjs', 'lib', 'png.js')).PNG;
  }
  const png = PNG.sync.read(buf);
  return { width: png.width, height: png.height, data: png.data };
}

function encodePng({ width, height, data }) {
  const PNG = require('pngjs').PNG;
  const png = new PNG({ width, height });
  Buffer.from(data).copy(png.data);
  return PNG.sync.write(png);
}

function removeColorKeyRgba(rgba, width, height, targetHex = '#FFFFFF', tolerance = 28, fuzziness = 18) {
  const out = Buffer.from(rgba);
  const target = hexToRgb(targetHex);
  const tolNorm = tolerance / 100;
  const fuzzNorm = Math.max(0.001, fuzziness / 100);

  for (let i = 0; i < out.length; i += 4) {
    const r = out[i];
    const g = out[i + 1];
    const b = out[i + 2];
    const a = out[i + 3];
    if (a === 0) continue;

    const dist = colorDistanceRGB(r, g, b, target.r, target.g, target.b);
    if (dist <= tolNorm) {
      out[i + 3] = 0;
    } else if (dist < tolNorm + fuzzNorm) {
      const alphaFactor = (dist - tolNorm) / fuzzNorm;
      out[i + 3] = Math.round(a * alphaFactor);
      const avg = (r + g + b) / 3;
      out[i] = Math.round(r * alphaFactor + avg * (1 - alphaFactor));
      out[i + 1] = Math.round(g * alphaFactor + avg * (1 - alphaFactor));
      out[i + 2] = Math.round(b * alphaFactor + avg * (1 - alphaFactor));
    }
  }
  return { width, height, data: out };
}

/** Soft white fringe cleanup (BCUT cleanCutoutEdges, simplified). */
function cleanWhiteFringe({ width, height, data }) {
  const out = Buffer.from(data);
  for (let i = 0; i < out.length; i += 4) {
    const a = out[i + 3];
    if (a === 0 || a === 255) continue;
    const r = out[i];
    const g = out[i + 1];
    const b = out[i + 2];
    const min = Math.min(r, g, b);
    const max = Math.max(r, g, b);
    const whiteAmt = min / 255;
    const lowSat = max - min < 28;
    if (whiteAmt > 0.82 && lowSat && a < 250) {
      const remove = whiteAmt * (a < 200 ? 0.95 : 0.55);
      const keep = Math.max(0.08, 1 - remove);
      out[i] = Math.max(0, Math.min(255, Math.round((r - 255 * remove) / keep)));
      out[i + 1] = Math.max(0, Math.min(255, Math.round((g - 255 * remove) / keep)));
      out[i + 2] = Math.max(0, Math.min(255, Math.round((b - 255 * remove) / keep)));
      if (whiteAmt > 0.92 && a < 140) out[i + 3] = 0;
    }
  }
  return { width, height, data: out };
}

function compositeOnWhite(rgba) {
  const { width, height, data } = rgba;
  const out = Buffer.alloc(width * height * 4);
  for (let i = 0; i < data.length; i += 4) {
    const a = data[i + 3] / 255;
    out[i] = Math.round(data[i] * a + 255 * (1 - a));
    out[i + 1] = Math.round(data[i + 1] * a + 255 * (1 - a));
    out[i + 2] = Math.round(data[i + 2] * a + 255 * (1 - a));
    out[i + 3] = 255;
  }
  return { width, height, data: out };
}

async function removeWithImgly(pngBuffer) {
  // Prefer Node build if present; else browser package with Blob/File polyfills is unreliable.
  let removeBackground;
  try {
    ({ removeBackground } = await import('@imgly/background-removal-node'));
  } catch {
    ({ removeBackground } = await import('@imgly/background-removal'));
  }

  const blob = new Blob([pngBuffer], { type: 'image/png' });
  const result = await removeBackground(blob, {
    progress: (key, current, total) => {
      const pct = total ? Math.round((current / total) * 100) : 0;
      process.stdout.write(`\rAI remove: ${key} ${pct}%   `);
    },
  });

  if (Buffer.isBuffer(result)) return result;
  if (result instanceof ArrayBuffer) return Buffer.from(result);
  if (typeof result?.arrayBuffer === 'function') {
    return Buffer.from(await result.arrayBuffer());
  }
  throw new Error('Unexpected imgly result type');
}

function writeOutputs(pngBuf) {
  fs.mkdirSync(outDir, { recursive: true });
  fs.writeFileSync(outCutout, pngBuf);
  fs.writeFileSync(outRepo, pngBuf);
  fs.mkdirSync(path.dirname(outLetterhead), { recursive: true });
  fs.writeFileSync(outLetterhead, pngBuf);
  console.log('\nWrote:');
  console.log(' ', outCutout);
  console.log(' ', outRepo);
  console.log(' ', outLetterhead);
}

async function main() {
  if (!fs.existsSync(svgPath)) {
    console.error('Missing logo SVG:', svgPath);
    process.exit(1);
  }

  console.log('Rasterizing SVG�');
  const svg = fs.readFileSync(svgPath);
  const resvg = new Resvg(svg, {
    fitTo: { mode: 'width', value: 1600 },
    background: 'rgba(0,0,0,0)',
  });
  const rendered = resvg.render();
  const transparentFromSvg = Buffer.from(rendered.asPng());

  // If SVG already has no page fill, this may already be a perfect cutout.
  // Still run BCUT-style white removal from a white composite for clean edges.
  console.log('Applying BCUT white-key + fringe cleanup�');
  const decoded = decodePng(transparentFromSvg);
  const onWhite = compositeOnWhite(decoded);
  let cut = removeColorKeyRgba(onWhite.data, onWhite.width, onWhite.height, '#FFFFFF', 26, 16);
  cut = cleanWhiteFringe(cut);
  let outPng = encodePng(cut);

  // Try AI path when Blob is available (Node 20+)
  try {
    if (typeof Blob !== 'undefined') {
      console.log('Trying BCUT AI background removal�');
      const whitePng = encodePng(onWhite);
      const aiPng = await removeWithImgly(whitePng);
      // Prefer AI only if it produced a non-trivial alpha channel
      const aiDecoded = decodePng(aiPng);
      let opaque = 0;
      let transparent = 0;
      for (let i = 3; i < aiDecoded.data.length; i += 4) {
        if (aiDecoded.data[i] < 16) transparent++;
        else opaque++;
      }
      if (transparent > opaque * 0.05 && opaque > 1000) {
        outPng = encodePng(cleanWhiteFringe(aiDecoded));
        console.log('\nUsing AI cutout.');
      } else {
        console.log('\nAI result looked solid; keeping chroma-key cutout.');
      }
    }
  } catch (err) {
    console.log('\nAI removal skipped:', err.message || err);
    console.log('Using BCUT chroma-key cutout.');
  }

  writeOutputs(outPng);
  console.log('Done.');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
