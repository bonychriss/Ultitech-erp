import React, { useRef } from 'react';
import { 
  Palette, 
  Image as ImageIcon, 
  Grid, 
  Slash, 
  Upload, 
  Pipette, 
  Sliders, 
  Check, 
  Sun,
  Layers
} from 'lucide-react';

export default function BackgroundSelector({
  bgType,
  setBgType,
  bgColor,
  setBgColor,
  gradientConfig,
  setGradientConfig,
  bgImage,
  setBgImage,
  bgBlur,
  setBgBlur,
  bgOpacity,
  setBgOpacity
}) {
  const bgFileInputRef = useRef(null);

  // Palette Categories
  const COLOR_PALETTES = [
    {
      category: 'Studio Neutrals',
      colors: ['#FFFFFF', '#F8FAFC', '#E2E8F0', '#94A3B8', '#334155', '#0F172A', '#000000']
    },
    {
      category: 'Pastel Soft',
      colors: ['#FEE2E2', '#FEF3C7', '#D1FAE5', '#E0F2FE', '#EDE9FE', '#FCE7F3']
    },
    {
      category: 'Vibrant Trends',
      colors: ['#EF4444', '#F97316', '#F59E0B', '#10B981', '#06B6D4', '#3B82F6', '#8B5CF6', '#EC4899']
    },
    {
      category: 'Dark Cyber & Neon',
      colors: ['#030712', '#18181B', '#1e1b4b', '#14532D', '#701A75', '#831843']
    }
  ];

  // Preset Gradients
  const PRESET_GRADIENTS = [
    { name: 'Sunset Glow', color1: '#f97316', color2: '#ec4899', angle: 135 },
    { name: 'Cyber Neon', color1: '#06b6d4', color2: '#a855f7', angle: 135 },
    { name: 'Deep Ocean', color1: '#0284c7', color2: '#0f172a', angle: 180 },
    { name: 'Soft Studio', color1: '#f8fafc', color2: '#cbd5e1', angle: 180 },
    { name: 'Emerald Forest', color1: '#10b981', color2: '#064e3b', angle: 135 },
    { name: 'Royal Gold', color1: '#f59e0b', color2: '#78350f', angle: 135 }
  ];

  const handleBgImageUpload = (e) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = (event) => {
        const img = new Image();
        img.onload = () => {
          setBgImage(img);
          setBgType('image');
        };
        img.src = event.target.result;
      };
      reader.readAsDataURL(file);
    }
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
      
      {/* Background Type Navigation Tabs */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5, 1fr)', gap: '4px', background: 'var(--bg-subtle)', padding: '4px', borderRadius: '12px', border: '1px solid var(--bg-subtle-border)' }}>
        <button
          onClick={() => setBgType('color')}
          className={`tab-btn ${bgType === 'color' ? 'active' : ''}`}
          style={{ justifyContent: 'center', padding: '8px 2px', borderRadius: '8px', fontSize: '0.74rem' }}
        >
          <Palette size={13} />
          <span>Color</span>
        </button>
        <button
          onClick={() => setBgType('gradient')}
          className={`tab-btn ${bgType === 'gradient' ? 'active' : ''}`}
          style={{ justifyContent: 'center', padding: '8px 2px', borderRadius: '8px', fontSize: '0.74rem' }}
        >
          <Layers size={13} />
          <span>Gradient</span>
        </button>
        <button
          onClick={() => setBgType('image')}
          className={`tab-btn ${bgType === 'image' ? 'active' : ''}`}
          style={{ justifyContent: 'center', padding: '8px 2px', borderRadius: '8px', fontSize: '0.74rem' }}
        >
          <ImageIcon size={13} />
          <span>Image</span>
        </button>
        <button
          onClick={() => setBgType('pattern')}
          className={`tab-btn ${bgType === 'pattern' ? 'active' : ''}`}
          style={{ justifyContent: 'center', padding: '8px 2px', borderRadius: '8px', fontSize: '0.74rem' }}
        >
          <Grid size={13} />
          <span>Studio</span>
        </button>
        <button
          onClick={() => setBgType('transparent')}
          className={`tab-btn ${bgType === 'transparent' ? 'active' : ''}`}
          style={{ justifyContent: 'center', padding: '8px 2px', borderRadius: '8px', fontSize: '0.74rem' }}
        >
          <Slash size={13} />
          <span>Clear</span>
        </button>
      </div>

      {/* 1. Solid Color Selector */}
      {bgType === 'color' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
          
          {/* Custom Color Input & Hex */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', background: 'var(--bg-subtle)', padding: '10px 14px', borderRadius: '12px', border: '1px solid var(--bg-subtle-border)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <input
                type="color"
                value={bgColor}
                onChange={(e) => setBgColor(e.target.value)}
                style={{
                  width: '36px',
                  height: '36px',
                  border: 'none',
                  borderRadius: '8px',
                  cursor: 'pointer',
                  background: 'none'
                }}
              />
              <div>
                <span style={{ fontSize: '0.75rem', color: 'var(--text-muted)', display: 'block' }}>Custom Hex</span>
                <input
                  type="text"
                  value={bgColor}
                  onChange={(e) => setBgColor(e.target.value)}
                  style={{
                    background: 'none',
                    border: 'none',
                    color: 'var(--text-main)',
                    fontFamily: 'monospace',
                    fontSize: '0.9rem',
                    fontWeight: '700',
                    width: '80px',
                    outline: 'none'
                  }}
                />
              </div>
            </div>
            <span style={{ fontSize: '0.75rem', color: 'var(--accent-primary)', fontWeight: '600' }}>Active Color</span>
          </div>

          {/* Preset Swatches by Category */}
          {COLOR_PALETTES.map((cat) => (
            <div key={cat.category}>
              <span style={{ fontSize: '0.72rem', color: 'var(--text-dim)', fontWeight: '700', textTransform: 'uppercase', letterSpacing: '0.05em', display: 'block', marginBottom: '6px' }}>
                {cat.category}
              </span>
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px' }}>
                {cat.colors.map((c) => (
                  <button
                    key={c}
                    onClick={() => setBgColor(c)}
                    style={{
                      width: '32px',
                      height: '32px',
                      borderRadius: '8px',
                      backgroundColor: c,
                      border: bgColor.toLowerCase() === c.toLowerCase() ? '2px solid var(--accent-primary)' : '1px solid rgba(0,0,0,0.15)',
                      boxShadow: bgColor.toLowerCase() === c.toLowerCase() ? '0 0 12px rgba(79,70,229,0.4)' : 'none',
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      transition: 'transform 0.15s ease'
                    }}
                    title={c}
                  >
                    {bgColor.toLowerCase() === c.toLowerCase() && (
                      <Check size={14} color={c === '#FFFFFF' || c === '#F8FAFC' || c === '#E2E8F0' ? '#000' : '#fff'} />
                    )}
                  </button>
                ))}
              </div>
            </div>
          ))}

        </div>
      )}

      {/* 2. Gradient Selector */}
      {bgType === 'gradient' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
          
          {/* Linear vs Radial Toggle */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px' }}>
            <button
              onClick={() => setGradientConfig({ ...gradientConfig, type: 'linear' })}
              className={`btn btn-sm ${gradientConfig.type === 'linear' ? 'btn-primary' : 'btn-secondary'}`}
            >
              Linear Gradient
            </button>
            <button
              onClick={() => setGradientConfig({ ...gradientConfig, type: 'radial' })}
              className={`btn btn-sm ${gradientConfig.type === 'radial' ? 'btn-primary' : 'btn-secondary'}`}
            >
              Radial Spotlight
            </button>
          </div>

          {/* Angle Slider (Linear only) */}
          {gradientConfig.type === 'linear' && (
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', marginBottom: '6px' }}>
                <span style={{ color: 'var(--text-muted)' }}>Gradient Angle</span>
                <span style={{ fontWeight: '700', color: 'var(--accent-primary)' }}>{gradientConfig.angle}°</span>
              </div>
              <input
                type="range"
                min="0"
                max="360"
                value={gradientConfig.angle}
                onChange={(e) => setGradientConfig({ ...gradientConfig, angle: Number(e.target.value) })}
              />
            </div>
          )}

          {/* Color 1 & Color 2 Pickers */}
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
            <div style={{ background: 'var(--bg-subtle)', padding: '8px 12px', borderRadius: '10px', display: 'flex', alignItems: 'center', gap: '8px', border: '1px solid var(--bg-subtle-border)' }}>
              <input
                type="color"
                value={gradientConfig.color1}
                onChange={(e) => setGradientConfig({ ...gradientConfig, color1: e.target.value })}
                style={{ width: '28px', height: '28px', border: 'none', borderRadius: '6px', cursor: 'pointer', background: 'none' }}
              />
              <div>
                <span style={{ fontSize: '0.7rem', color: 'var(--text-dim)', display: 'block' }}>Color 1</span>
                <span style={{ fontSize: '0.8rem', fontWeight: '700', fontFamily: 'monospace', color: 'var(--text-main)' }}>{gradientConfig.color1}</span>
              </div>
            </div>

            <div style={{ background: 'var(--bg-subtle)', padding: '8px 12px', borderRadius: '10px', display: 'flex', alignItems: 'center', gap: '8px', border: '1px solid var(--bg-subtle-border)' }}>
              <input
                type="color"
                value={gradientConfig.color2}
                onChange={(e) => setGradientConfig({ ...gradientConfig, color2: e.target.value })}
                style={{ width: '28px', height: '28px', border: 'none', borderRadius: '6px', cursor: 'pointer', background: 'none' }}
              />
              <div>
                <span style={{ fontSize: '0.7rem', color: 'var(--text-dim)', display: 'block' }}>Color 2</span>
                <span style={{ fontSize: '0.8rem', fontWeight: '700', fontFamily: 'monospace', color: 'var(--text-main)' }}>{gradientConfig.color2}</span>
              </div>
            </div>
          </div>

          {/* Preset Gradients Grid */}
          <div>
            <span style={{ fontSize: '0.72rem', color: 'var(--text-dim)', fontWeight: '700', textTransform: 'uppercase', letterSpacing: '0.05em', display: 'block', marginBottom: '8px' }}>
              Preset Studio Gradients
            </span>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '8px' }}>
              {PRESET_GRADIENTS.map((p) => (
                <button
                  key={p.name}
                  onClick={() => setGradientConfig({ ...gradientConfig, color1: p.color1, color2: p.color2, angle: p.angle })}
                  className="btn btn-outline btn-sm"
                  style={{
                    padding: '8px',
                    background: `linear-gradient(135deg, ${p.color1}, ${p.color2})`,
                    border: '1px solid rgba(0,0,0,0.15)',
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    height: '50px'
                  }}
                >
                  <span style={{ fontSize: '0.72rem', fontWeight: '700', color: '#ffffff', textShadow: '0 1px 3px rgba(0,0,0,0.8)' }}>
                    {p.name}
                  </span>
                </button>
              ))}
            </div>
          </div>

        </div>
      )}

      {/* 3. Custom Image Background */}
      {bgType === 'image' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
          
          <input
            type="file"
            ref={bgFileInputRef}
            onChange={handleBgImageUpload}
            accept="image/*"
            style={{ display: 'none' }}
          />

          <button
            onClick={() => bgFileInputRef.current?.click()}
            className="btn btn-secondary"
            style={{ width: '100%', padding: '12px', gap: '8px' }}
          >
            <Upload size={18} />
            <span>{bgImage ? 'Change Background Photo' : 'Upload Background Photo'}</span>
          </button>

          {bgImage && (
            <>
              {/* Blur Slider */}
              <div>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', marginBottom: '6px' }}>
                  <span style={{ color: 'var(--text-muted)' }}>Background Blur</span>
                  <span style={{ fontWeight: '700', color: 'var(--accent-primary)' }}>{bgBlur}px</span>
                </div>
                <input
                  type="range"
                  min="0"
                  max="30"
                  value={bgBlur}
                  onChange={(e) => setBgBlur(Number(e.target.value))}
                />
              </div>

              {/* Opacity Slider */}
              <div>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', marginBottom: '6px' }}>
                  <span style={{ color: 'var(--text-muted)' }}>Background Opacity</span>
                  <span style={{ fontWeight: '700', color: 'var(--accent-primary)' }}>{bgOpacity}%</span>
                </div>
                <input
                  type="range"
                  min="10"
                  max="100"
                  value={bgOpacity}
                  onChange={(e) => setBgOpacity(Number(e.target.value))}
                />
              </div>
            </>
          )}

        </div>
      )}

      {/* 4. Studio Pattern */}
      {bgType === 'pattern' && (
        <div style={{ background: 'var(--bg-subtle)', padding: '14px', borderRadius: '12px', border: '1px solid var(--bg-subtle-border)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '6px' }}>
            <Grid size={18} color="var(--accent-cyan)" />
            <h4 style={{ fontSize: '0.88rem', fontWeight: '700', margin: 0, color: 'var(--text-main)' }}>Studio Vignette & Grid Mesh</h4>
          </div>
          <p style={{ fontSize: '0.78rem', color: 'var(--text-muted)', margin: 0 }}>
            Adds a sleek studio radial lighting backdrop with geometric grid overlay ideal for product shots.
          </p>
        </div>
      )}

      {/* 5. Transparent Mode */}
      {bgType === 'transparent' && (
        <div style={{ background: 'var(--bg-subtle)', padding: '14px', borderRadius: '12px', border: '1px solid var(--bg-subtle-border)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '6px' }}>
            <Slash size={18} color="var(--accent-emerald)" />
            <h4 style={{ fontSize: '0.88rem', fontWeight: '700', margin: 0, color: 'var(--text-main)' }}>Transparent Cutout Mode</h4>
          </div>
          <p style={{ fontSize: '0.78rem', color: 'var(--text-muted)', margin: 0 }}>
            Background color removed completely. Image will export as a clean transparent PNG cutout.
          </p>
        </div>
      )}

    </div>
  );
}
