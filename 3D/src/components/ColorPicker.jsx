import React, { useEffect, useRef, useState } from 'react';

function clamp(n, min, max) {
  return Math.min(max, Math.max(min, n));
}

function hexToRgb(hex) {
  let h = (hex || '#000000').replace('#', '');
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  const n = parseInt(h, 16);
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function rgbToHex(r, g, b) {
  return (
    '#' +
    [r, g, b]
      .map((x) => clamp(Math.round(x), 0, 255).toString(16).padStart(2, '0'))
      .join('')
  );
}

function rgbToHsv(r, g, b) {
  r /= 255;
  g /= 255;
  b /= 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const d = max - min;
  let h = 0;
  const s = max === 0 ? 0 : d / max;
  const v = max;
  if (d !== 0) {
    if (max === r) h = ((g - b) / d + (g < b ? 6 : 0)) * 60;
    else if (max === g) h = ((b - r) / d + 2) * 60;
    else h = ((r - g) / d + 4) * 60;
  }
  return { h, s, v };
}

function hsvToRgb(h, s, v) {
  const c = v * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = v - c;
  let r = 0;
  let g = 0;
  let b = 0;
  if (h < 60) [r, g, b] = [c, x, 0];
  else if (h < 120) [r, g, b] = [x, c, 0];
  else if (h < 180) [r, g, b] = [0, c, x];
  else if (h < 240) [r, g, b] = [0, x, c];
  else if (h < 300) [r, g, b] = [x, 0, c];
  else [r, g, b] = [c, 0, x];
  return {
    r: (r + m) * 255,
    g: (g + m) * 255,
    b: (b + m) * 255
  };
}

function hsvToHex(h, s, v) {
  const { r, g, b } = hsvToRgb(h, s, v);
  return rgbToHex(r, g, b);
}

function hueColor(h) {
  return hsvToHex(h, 1, 1);
}

export default function ColorPicker({ color, onChange }) {
  const svRef = useRef(null);
  const hueRef = useRef(null);
  const dragRef = useRef(null);
  const hsvRef = useRef({ h: 0, s: 1, v: 1 });

  const parsed = rgbToHsv(...Object.values(hexToRgb(color || '#F8FAFC')));
  const [h, setH] = useState(parsed.h);
  const [s, setS] = useState(parsed.s);
  const [v, setV] = useState(parsed.v);

  hsvRef.current = { h, s, v };

  useEffect(() => {
    const next = rgbToHsv(...Object.values(hexToRgb(color || '#F8FAFC')));
    const currentHex = hsvToHex(h, s, v).toLowerCase();
    if (currentHex !== (color || '').toLowerCase()) {
      setH(next.s === 0 ? h : next.h);
      setS(next.s);
      setV(next.v);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [color]);

  const emit = (nh, ns, nv) => {
    onChange(hsvToHex(nh, ns, nv));
  };

  const readSv = (clientX, clientY) => {
    const el = svRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const ns = clamp((clientX - rect.left) / rect.width, 0, 1);
    const nv = 1 - clamp((clientY - rect.top) / rect.height, 0, 1);
    setS(ns);
    setV(nv);
    emit(hsvRef.current.h, ns, nv);
  };

  const readHue = (clientX) => {
    const el = hueRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const nh = clamp((clientX - rect.left) / rect.width, 0, 1) * 360;
    setH(nh);
    emit(nh, hsvRef.current.s, hsvRef.current.v);
  };

  useEffect(() => {
    const onMove = (e) => {
      if (!dragRef.current) return;
      if (dragRef.current === 'sv') readSv(e.clientX, e.clientY);
      if (dragRef.current === 'hue') readHue(e.clientX);
    };
    const onUp = () => {
      dragRef.current = null;
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    return () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
    };
  }, []);

  const hex = hsvToHex(h, s, v);

  return (
    <div className="color-picker">
      <div className="color-picker-stage">
        <div className="color-picker-preview" style={{ backgroundColor: hex }} />
        <div
          ref={svRef}
          className="color-picker-sv"
          style={{ backgroundColor: hueColor(h) }}
          onPointerDown={(e) => {
            dragRef.current = 'sv';
            readSv(e.clientX, e.clientY);
          }}
        >
          <div className="color-picker-sv-white" />
          <div className="color-picker-sv-black" />
          <div
            className="color-picker-sv-thumb"
            style={{ left: `${s * 100}%`, top: `${(1 - v) * 100}%` }}
          />
        </div>
      </div>

      <div
        ref={hueRef}
        className="color-picker-hue"
        onPointerDown={(e) => {
          dragRef.current = 'hue';
          readHue(e.clientX);
        }}
      >
        <div
          className="color-picker-hue-thumb"
          style={{ left: `${(h / 360) * 100}%`, backgroundColor: hueColor(h) }}
        />
      </div>

      <input
        type="text"
        value={hex.toUpperCase()}
        onChange={(e) => {
          const val = e.target.value.startsWith('#') ? e.target.value : `#${e.target.value}`;
          if (/^#[0-9A-Fa-f]{6}$/.test(val)) onChange(val);
        }}
        spellCheck={false}
        className="color-picker-hex"
      />
    </div>
  );
}
