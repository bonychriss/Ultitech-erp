/**
 * Advanced Image Processing Utilities for Chroma-Key, Magic Wand, Brush, and Canvas Effects
 */

// Convert Hex string (#RRGGBB) to RGB object
export function hexToRgb(hex) {
  if (!hex) return { r: 0, g: 0, b: 0 };
  let cleanHex = hex.replace(/^#/, '');
  if (cleanHex.length === 3) {
    cleanHex = cleanHex.split('').map(c => c + c).join('');
  }
  const num = parseInt(cleanHex, 16);
  return {
    r: (num >> 16) & 255,
    g: (num >> 8) & 255,
    b: num & 255
  };
}

// Convert RGB to Hex string
export function rgbToHex(r, g, b) {
  return "#" + [r, g, b].map(x => {
    const hex = Math.min(255, Math.max(0, Math.round(x))).toString(16);
    return hex.length === 1 ? "0" + hex : hex;
  }).join('');
}

// Color Euclidean distance in normalized RGB space (0 to 1)
export function colorDistanceRGB(r1, g1, b1, r2, g2, b2) {
  const dr = (r1 - r2) / 255;
  const dg = (g1 - g2) / 255;
  const db = (b1 - b2) / 255;
  // Weighted RGB distance matching human vision sensitivity (r: 0.3, g: 0.59, b: 0.11)
  return Math.sqrt(0.3 * dr * dr + 0.59 * dg * dg + 0.11 * db * db);
}

/**
 * Chroma Key / Color Removal Algorithm
 * @param {HTMLCanvasElement} sourceCanvas 
 * @param {string} targetHex - e.g. "#00FF00"
 * @param {number} tolerance - 0 to 100
 * @param {number} fuzziness - 0 to 100
 * @param {boolean} desaturateSpill - remove edge color tint
 * @returns {HTMLCanvasElement} canvas with processed alpha channel
 */
export function removeColorKey(sourceCanvas, targetHex, tolerance = 30, fuzziness = 20, desaturateSpill = true) {
  const width = sourceCanvas.width;
  const height = sourceCanvas.height;

  const outputCanvas = document.createElement('canvas');
  outputCanvas.width = width;
  outputCanvas.height = height;

  const ctx = outputCanvas.getContext('2d');
  ctx.drawImage(sourceCanvas, 0, 0);

  const imgData = ctx.getImageData(0, 0, width, height);
  const data = imgData.data;

  const targetRgb = hexToRgb(targetHex);
  const tolNorm = tolerance / 100; // e.g., 0.3
  const fuzzNorm = Math.max(0.001, fuzziness / 100); // smooth ramp range

  for (let i = 0; i < data.length; i += 4) {
    const r = data[i];
    const g = data[i + 1];
    const b = data[i + 2];
    const a = data[i + 3];

    if (a === 0) continue;

    const dist = colorDistanceRGB(r, g, b, targetRgb.r, targetRgb.g, targetRgb.b);

    if (dist <= tolNorm) {
      // Completely remove color
      data[i + 3] = 0;
    } else if (dist < tolNorm + fuzzNorm) {
      // Soft transition ramp
      const alphaFactor = (dist - tolNorm) / fuzzNorm;
      data[i + 3] = Math.round(a * alphaFactor);

      // Spill suppression (desaturate green/blue tint on edges)
      if (desaturateSpill) {
        const avg = (r + g + b) / 3;
        data[i] = Math.round(r * alphaFactor + avg * (1 - alphaFactor));
        data[i + 1] = Math.round(g * alphaFactor + avg * (1 - alphaFactor));
        data[i + 2] = Math.round(b * alphaFactor + avg * (1 - alphaFactor));
      }
    }
  }

  ctx.putImageData(imgData, 0, 0);
  return outputCanvas;
}

/**
 * Flood Fill / Magic Wand Selector
 * Removes connected pixels of target color starting at (startX, startY)
 */
export function magicWandPurge(sourceCanvas, startX, startY, tolerance = 25) {
  const width = sourceCanvas.width;
  const height = sourceCanvas.height;

  const outputCanvas = document.createElement('canvas');
  outputCanvas.width = width;
  outputCanvas.height = height;

  const ctx = outputCanvas.getContext('2d');
  ctx.drawImage(sourceCanvas, 0, 0);

  const imgData = ctx.getImageData(0, 0, width, height);
  const data = imgData.data;

  const startPos = (Math.floor(startY) * width + Math.floor(startX)) * 4;
  const startR = data[startPos];
  const startG = data[startPos + 1];
  const startB = data[startPos + 2];
  const startA = data[startPos + 3];

  if (startA === 0) return outputCanvas;

  const tolNorm = tolerance / 100;
  const visited = new Uint8Array(width * height);
  const queue = [Math.floor(startX), Math.floor(startY)];

  visited[Math.floor(startY) * width + Math.floor(startX)] = 1;

  while (queue.length > 0) {
    const y = queue.pop();
    const x = queue.pop();
    const idx = (y * width + x) * 4;

    data[idx + 3] = 0; // set alpha to 0

    // Check 4 neighbors
    const neighbors = [
      [x + 1, y], [x - 1, y], [x, y + 1], [x, y - 1]
    ];

    for (let i = 0; i < neighbors.length; i++) {
      const nx = neighbors[i][0];
      const ny = neighbors[i][1];

      if (nx >= 0 && nx < width && ny >= 0 && ny < height) {
        const vIdx = ny * width + nx;
        if (!visited[vIdx]) {
          visited[vIdx] = 1;
          const nDataIdx = (ny * width + nx) * 4;
          const nr = data[nDataIdx];
          const ng = data[nDataIdx + 1];
          const nb = data[nDataIdx + 2];
          const na = data[nDataIdx + 3];

          if (na > 0) {
            const dist = colorDistanceRGB(nr, ng, nb, startR, startG, startB);
            if (dist <= tolNorm) {
              queue.push(nx, ny);
            }
          }
        }
      }
    }
  }

  ctx.putImageData(imgData, 0, 0);
  return outputCanvas;
}

/**
 * Interactive Touch-up Brush (Eraser or Restore)
 */
export function applyBrush(workCanvas, originalCanvas, x, y, radius, hardness, mode = 'erase') {
  const ctx = workCanvas.getContext('2d');
  ctx.save();

  const gradient = ctx.createRadialGradient(x, y, radius * (hardness / 100), x, y, radius);
  
  if (mode === 'erase') {
    gradient.addColorStop(0, 'rgba(0, 0, 0, 1)');
    gradient.addColorStop(1, 'rgba(0, 0, 0, 0)');

    ctx.globalCompositeOperation = 'destination-out';
    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2);
    ctx.fill();
  } else if (mode === 'restore') {
    // To restore, sample from original canvas into a temp brush shape and composite over
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = workCanvas.width;
    tempCanvas.height = workCanvas.height;
    const tempCtx = tempCanvas.getContext('2d');

    // Create radial mask
    tempCtx.fillStyle = 'black';
    tempCtx.beginPath();
    tempCtx.arc(x, y, radius, 0, Math.PI * 2);
    tempCtx.fill();

    // Mask with original pixels
    tempCtx.globalCompositeOperation = 'source-in';
    tempCtx.drawImage(originalCanvas, 0, 0);

    // Draw back onto workCanvas
    ctx.globalCompositeOperation = 'source-over';
    ctx.drawImage(tempCanvas, 0, 0);
  }

  ctx.restore();
}

/**
 * Creates Subject Sticker Outline Effect
 */
export function drawSubjectOutline(ctx, subjectCanvas, x, y, width, height, strokeWidth = 5, strokeColor = '#FFFFFF') {
  if (strokeWidth <= 0) return;

  const tempCanvas = document.createElement('canvas');
  tempCanvas.width = width;
  tempCanvas.height = height;
  const tempCtx = tempCanvas.getContext('2d');

  // Draw subject silhouette dilated multiple times to create outer boundary
  const dSteps = 12;
  tempCtx.save();
  for (let i = 0; i < dSteps; i++) {
    const angle = (i / dSteps) * Math.PI * 2;
    const dx = Math.cos(angle) * strokeWidth;
    const dy = Math.sin(angle) * strokeWidth;
    tempCtx.drawImage(subjectCanvas, dx, dy, width, height);
  }
  tempCtx.restore();

  // Color the silhouette with strokeColor
  tempCtx.globalCompositeOperation = 'source-in';
  tempCtx.fillStyle = strokeColor;
  tempCtx.fillRect(0, 0, width, height);

  // Draw outline behind the subject
  ctx.drawImage(tempCanvas, x, y, width, height);
}

/**
 * Renders the final composite canvas:
 * 1. Background layer (Solid Color, Gradient, Custom Image, Pattern)
 * 2. Subject Effects (Drop Shadow, Sticker Outline)
 * 3. Subject image with position & scale adjustments
 */
export function renderCompositeCanvas({
  canvas,
  subjectCanvas,
  originalCanvas,
  bgType, // 'color', 'gradient', 'image', 'pattern', 'transparent'
  bgColor,
  gradientConfig,
  bgImage,
  bgBlur,
  bgOpacity,
  subjectPosition, // { x, y, scale, rotation }
  subjectEffects, // { shadow: { enabled, blur, offsetX, offsetY, opacity, color }, outline: { enabled, size, color }, adjustments: { brightness, contrast, saturation } }
  showGrid = false
}) {
  if (!canvas || !subjectCanvas) return;

  const ctx = canvas.getContext('2d');
  const width = canvas.width;
  const height = canvas.height;

  ctx.clearRect(0, 0, width, height);

  // 1. Draw Checkerboard background if grid enabled or background is transparent
  if (bgType === 'transparent' || showGrid) {
    const checkSize = 16;
    for (let y = 0; y < height; y += checkSize) {
      for (let x = 0; x < width; x += checkSize) {
        const isEven = ((x / checkSize) + (y / checkSize)) % 2 === 0;
        ctx.fillStyle = isEven ? '#1e293b' : '#0f172a';
        ctx.fillRect(x, y, checkSize, checkSize);
      }
    }
  }

  // 2. Render Custom Background (if not transparent)
  if (bgType !== 'transparent') {
    ctx.save();

    if (bgType === 'color') {
      ctx.fillStyle = bgColor || '#ffffff';
      ctx.fillRect(0, 0, width, height);
    } else if (bgType === 'gradient') {
      const { type = 'linear', color1 = '#3b82f6', color2 = '#8b5cf6', angle = 135 } = gradientConfig || {};
      let grad;
      if (type === 'linear') {
        const rad = (angle * Math.PI) / 180;
        const x1 = width / 2 - Math.cos(rad) * width / 2;
        const y1 = height / 2 - Math.sin(rad) * height / 2;
        const x2 = width / 2 + Math.cos(rad) * width / 2;
        const y2 = height / 2 + Math.sin(rad) * height / 2;
        grad = ctx.createLinearGradient(x1, y1, x2, y2);
      } else {
        grad = ctx.createRadialGradient(width / 2, height / 2, 10, width / 2, height / 2, Math.max(width, height) / 1.2);
      }
      grad.addColorStop(0, color1);
      grad.addColorStop(1, color2);
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, width, height);
    } else if (bgType === 'image' && bgImage) {
      ctx.globalAlpha = bgOpacity !== undefined ? bgOpacity / 100 : 1;
      if (bgBlur && bgBlur > 0) {
        ctx.filter = `blur(${bgBlur}px)`;
      }
      // Cover fit
      const bgRatio = bgImage.width / bgImage.height;
      const canvasRatio = width / height;
      let drawW, drawH, drawX, drawY;
      if (bgRatio > canvasRatio) {
        drawH = height;
        drawW = height * bgRatio;
        drawX = (width - drawW) / 2;
        drawY = 0;
      } else {
        drawW = width;
        drawH = width / bgRatio;
        drawX = 0;
        drawY = (height - drawH) / 2;
      }
      ctx.drawImage(bgImage, drawX, drawY, drawW, drawH);
    } else if (bgType === 'pattern') {
      // Modern Mesh / Studio Radial Vignette pattern
      const grad = ctx.createRadialGradient(width / 2, height / 2, 0, width / 2, height / 2, Math.max(width, height) / 1.4);
      grad.addColorStop(0, '#334155');
      grad.addColorStop(1, '#0f172a');
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, width, height);

      // Subtle Grid overlay
      ctx.strokeStyle = 'rgba(255, 255, 255, 0.05)';
      ctx.lineWidth = 1;
      const gridSize = 40;
      for (let gx = 0; gx < width; gx += gridSize) {
        ctx.beginPath();
        ctx.moveTo(gx, 0);
        ctx.lineTo(gx, height);
        ctx.stroke();
      }
      for (let gy = 0; gy < height; gy += gridSize) {
        ctx.beginPath();
        ctx.moveTo(0, gy);
        ctx.lineTo(width, gy);
        ctx.stroke();
      }
    }

    ctx.restore();
  }

  // 3. Prepare Subject Transformations
  const { x = 0, y = 0, scale = 1, rotation = 0, flipH = false, flipV = false } = subjectPosition || {};
  const subW = subjectCanvas.width * scale;
  const subH = subjectCanvas.height * scale;
  const subX = (width - subW) / 2 + x;
  const subY = (height - subH) / 2 + y;

  ctx.save();
  const centerX = subX + subW / 2;
  const centerY = subY + subH / 2;
  ctx.translate(centerX, centerY);
  ctx.rotate((rotation * Math.PI) / 180);
  ctx.scale(flipH ? -1 : 1, flipV ? -1 : 1);
  ctx.translate(-centerX, -centerY);

  // Apply Subject Color Filters (Brightness, Contrast, Saturation)
  if (subjectEffects?.adjustments) {
    const { brightness = 100, contrast = 100, saturation = 100 } = subjectEffects.adjustments;
    ctx.filter = `brightness(${brightness}%) contrast(${contrast}%) saturate(${saturation}%)`;
  }

  // 4. Render Subject Drop Shadow
  if (subjectEffects?.shadow?.enabled) {
    const { blur = 20, offsetX = 10, offsetY = 15, opacity = 40, color = '#000000' } = subjectEffects.shadow;
    ctx.save();
    ctx.shadowColor = hexToRgba(color, opacity / 100);
    ctx.shadowBlur = blur;
    ctx.shadowOffsetX = offsetX;
    ctx.shadowOffsetY = offsetY;
    ctx.drawImage(subjectCanvas, subX, subY, subW, subH);
    ctx.restore();
  }

  // 5. Render Subject Sticker Outline
  if (subjectEffects?.outline?.enabled) {
    const { size = 6, color = '#FFFFFF' } = subjectEffects.outline;
    drawSubjectOutline(ctx, subjectCanvas, subX, subY, subW, subH, size, color);
  }

  // 6. Render Subject
  ctx.drawImage(subjectCanvas, subX, subY, subW, subH);
  ctx.restore();
}

function hexToRgba(hex, alpha = 1) {
  const { r, g, b } = hexToRgb(hex);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

export function cropCanvas(source, rect) {
  const x = Math.max(0, Math.round(rect.x));
  const y = Math.max(0, Math.round(rect.y));
  const w = Math.max(1, Math.round(rect.w));
  const h = Math.max(1, Math.round(rect.h));
  const out = document.createElement('canvas');
  out.width = w;
  out.height = h;
  out.getContext('2d').drawImage(source, x, y, w, h, 0, 0, w, h);
  return out;
}

export function imageToCanvas(img) {
  const canvas = document.createElement('canvas');
  canvas.width = img.width;
  canvas.height = img.height;
  canvas.getContext('2d').drawImage(img, 0, 0);
  return canvas;
}

export function makeThumb(source, maxSize = 160, png = false) {
  const w = source.width || 1;
  const h = source.height || 1;
  const scale = maxSize / Math.max(w, h, 1);
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(w * scale));
  canvas.height = Math.max(1, Math.round(h * scale));
  canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
  return png ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', 0.72);
}

/**
 * Remove white/light fringe left on AI cutouts (text edges, soft borders).
 * Decontaminates edge colors and tightens soft alpha so replacement backgrounds look clean.
 */
export function cleanCutoutEdges(sourceCanvas) {
  const width = sourceCanvas.width;
  const height = sourceCanvas.height;
  const out = document.createElement('canvas');
  out.width = width;
  out.height = height;
  const ctx = out.getContext('2d');
  ctx.drawImage(sourceCanvas, 0, 0);

  const img = ctx.getImageData(0, 0, width, height);
  const data = img.data;
  const alpha = new Uint8ClampedArray(width * height);
  for (let p = 0, i = 0; i < data.length; i += 4, p++) alpha[p] = data[i + 3];

  const at = (x, y) => alpha[y * width + x];
  const clamp = (v) => Math.max(0, Math.min(255, Math.round(v)));

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const p = y * width + x;
      const i = p * 4;
      let a = data[i + 3];
      if (a === 0) continue;

      let r = data[i];
      let g = data[i + 1];
      let b = data[i + 2];

      const maxC = Math.max(r, g, b);
      const minC = Math.min(r, g, b);
      const lum = 0.299 * r + 0.587 * g + 0.114 * b;
      const sat = maxC - minC;

      let nearClear = a < 245;
      for (let dy = -1; dy <= 1 && !nearClear; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
          if (!dx && !dy) continue;
          const nx = x + dx;
          const ny = y + dy;
          if (nx < 0 || ny < 0 || nx >= width || ny >= height || at(nx, ny) < 32) {
            nearClear = true;
            break;
          }
        }
      }

      // How white / light-grey this pixel looks (fringe signature)
      const whiteAmt = Math.max(0, Math.min(1, (lum - 185) / 70)) * (1 - Math.min(1, sat / 48));

      // Soft nearly-white edge pixels are almost always leftover background
      if (nearClear && whiteAmt > 0.35 && a < 240) {
        a = clamp(a * (1 - whiteAmt * 0.95));
        if (whiteAmt > 0.7 && a < 160) a = 0;
      } else if (nearClear && whiteAmt > 0.2) {
        // Decontaminate: remove white matte contribution from edge colors
        const remove = whiteAmt * (a < 250 ? 0.92 : 0.55);
        const keep = Math.max(0.08, 1 - remove);
        r = clamp((r - 255 * remove) / keep);
        g = clamp((g - 255 * remove) / keep);
        b = clamp((b - 255 * remove) / keep);
        a = clamp(a * (1 - whiteAmt * 0.45));
      } else if (a > 0 && a < 220 && nearClear) {
        // Tighten soft non-white edges slightly (less halo)
        a = clamp(a * (0.65 + 0.35 * (a / 255)));
      }

      // Recover foreground if still semi-transparent over assumed light matte
      if (a > 8 && a < 250 && nearClear) {
        const af = a / 255;
        r = clamp((r - 255 * (1 - af)) / af);
        g = clamp((g - 255 * (1 - af)) / af);
        b = clamp((b - 255 * (1 - af)) / af);
      }

      data[i] = r;
      data[i + 1] = g;
      data[i + 2] = b;
      data[i + 3] = a;
    }
  }

  // Second pass: kill tiny leftover light speckles on the edge
  const alpha2 = new Uint8ClampedArray(width * height);
  for (let p = 0, i = 0; i < data.length; i += 4, p++) alpha2[p] = data[i + 3];

  for (let y = 1; y < height - 1; y++) {
    for (let x = 1; x < width - 1; x++) {
      const p = y * width + x;
      const i = p * 4;
      const a = data[i + 3];
      if (a === 0 || a > 210) continue;

      const lum = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
      const sat = Math.max(data[i], data[i + 1], data[i + 2]) - Math.min(data[i], data[i + 1], data[i + 2]);
      if (lum < 200 || sat > 40) continue;

      let solidNeighbors = 0;
      for (let dy = -1; dy <= 1; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
          if (!dx && !dy) continue;
          if (alpha2[(y + dy) * width + (x + dx)] > 220) solidNeighbors++;
        }
      }
      // Isolated soft white fringe with few solid neighbors
      if (solidNeighbors <= 2) data[i + 3] = 0;
    }
  }

  ctx.putImageData(img, 0, 0);
  return out;
}

export async function removeBackgroundFromCanvas(sourceCanvas, onProgress) {
  try {
    const imgly = await import('@imgly/background-removal');
    const inputBlob = await new Promise((resolve, reject) => {
      sourceCanvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Could not read image'))), 'image/png');
    });
    const blob = await imgly.removeBackground(inputBlob, {
      progress: (key, current, total) => {
        const pct = total ? Math.round((current / total) * 100) : 0;
        onProgress?.(key, pct);
      }
    });
    const url = URL.createObjectURL(blob);
    try {
      const img = await new Promise((resolve, reject) => {
        const el = new Image();
        el.onload = () => resolve(el);
        el.onerror = reject;
        el.src = url;
      });
      const out = document.createElement('canvas');
      out.width = sourceCanvas.width;
      out.height = sourceCanvas.height;
      out.getContext('2d').drawImage(img, 0, 0);
      return cleanCutoutEdges(out);
    } finally {
      URL.revokeObjectURL(url);
    }
  } catch (err) {
    console.error('AI background removal failed, falling back to color key:', err);
    const ctx = sourceCanvas.getContext('2d');
    const corner = ctx.getImageData(5, 5, 1, 1).data;
    const hex = `#${((1 << 24) + (corner[0] << 16) + (corner[1] << 8) + corner[2]).toString(16).slice(1)}`;
    return cleanCutoutEdges(removeColorKey(sourceCanvas, hex, 30, 20, true));
  }
}
