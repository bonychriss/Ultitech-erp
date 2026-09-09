import React from 'react';
import { 
  Sparkles, 
  Layers, 
  Sun, 
  Move, 
  RotateCcw, 
  Sliders,
  Check
} from 'lucide-react';

export default function SubjectEffects({
  subjectEffects,
  setSubjectEffects,
  subjectPosition,
  setSubjectPosition,
  onResetPosition
}) {
  const { outline, shadow, adjustments } = subjectEffects;

  const updateOutline = (key, value) => {
    setSubjectEffects({
      ...subjectEffects,
      outline: { ...outline, [key]: value }
    });
  };

  const updateShadow = (key, value) => {
    setSubjectEffects({
      ...subjectEffects,
      shadow: { ...shadow, [key]: value }
    });
  };

  const updateAdjustments = (key, value) => {
    setSubjectEffects({
      ...subjectEffects,
      adjustments: { ...adjustments, [key]: value }
    });
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
      
      {/* 1. Sticker Outline Effect */}
      <div style={{ background: 'rgba(30, 41, 59, 0.6)', padding: '14px', borderRadius: '12px', border: '1px solid rgba(255,255,255,0.08)' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: outline.enabled ? '12px' : '0' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Sparkles size={16} color="#f43f5e" />
            <h4 style={{ fontSize: '0.88rem', fontWeight: '700', margin: 0 }}>Sticker Border / Outline</h4>
          </div>
          <input
            type="checkbox"
            checked={outline.enabled}
            onChange={(e) => updateOutline('enabled', e.target.checked)}
            style={{ width: '16px', height: '16px', accentColor: 'var(--accent-rose)' }}
          />
        </div>

        {outline.enabled && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', marginTop: '10px' }}>
            
            {/* Outline Size */}
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
                <span style={{ color: 'var(--text-muted)' }}>Outline Width</span>
                <span style={{ fontWeight: '700', color: '#f43f5e' }}>{outline.size}px</span>
              </div>
              <input
                type="range"
                min="1"
                max="25"
                value={outline.size}
                onChange={(e) => updateOutline('size', Number(e.target.value))}
              />
            </div>

            {/* Outline Color */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <span style={{ fontSize: '0.78rem', color: 'var(--text-muted)' }}>Border Color</span>
              <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                {['#FFFFFF', '#000000', '#F59E0B', '#6366F1', '#EC4899'].map((c) => (
                  <button
                    key={c}
                    onClick={() => updateOutline('color', c)}
                    style={{
                      width: '22px',
                      height: '22px',
                      borderRadius: '50%',
                      backgroundColor: c,
                      border: outline.color.toLowerCase() === c.toLowerCase() ? '2px solid #818cf8' : '1px solid rgba(255,255,255,0.3)',
                      cursor: 'pointer'
                    }}
                  />
                ))}
                <input
                  type="color"
                  value={outline.color}
                  onChange={(e) => updateOutline('color', e.target.value)}
                  style={{ width: '22px', height: '22px', border: 'none', background: 'none', cursor: 'pointer' }}
                />
              </div>
            </div>

          </div>
        )}
      </div>

      {/* 2. Drop Shadow Effect */}
      <div style={{ background: 'rgba(30, 41, 59, 0.6)', padding: '14px', borderRadius: '12px', border: '1px solid rgba(255,255,255,0.08)' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: shadow.enabled ? '12px' : '0' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Layers size={16} color="#38bdf8" />
            <h4 style={{ fontSize: '0.88rem', fontWeight: '700', margin: 0 }}>Studio Drop Shadow</h4>
          </div>
          <input
            type="checkbox"
            checked={shadow.enabled}
            onChange={(e) => updateShadow('enabled', e.target.checked)}
            style={{ width: '16px', height: '16px', accentColor: 'var(--accent-primary)' }}
          />
        </div>

        {shadow.enabled && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', marginTop: '10px' }}>
            
            {/* Blur */}
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
                <span style={{ color: 'var(--text-muted)' }}>Shadow Softness</span>
                <span style={{ fontWeight: '700', color: '#38bdf8' }}>{shadow.blur}px</span>
              </div>
              <input
                type="range"
                min="0"
                max="50"
                value={shadow.blur}
                onChange={(e) => updateShadow('blur', Number(e.target.value))}
              />
            </div>

            {/* Distance Y */}
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
                <span style={{ color: 'var(--text-muted)' }}>Shadow Distance</span>
                <span style={{ fontWeight: '700', color: '#38bdf8' }}>{shadow.offsetY}px</span>
              </div>
              <input
                type="range"
                min="0"
                max="50"
                value={shadow.offsetY}
                onChange={(e) => updateShadow('offsetY', Number(e.target.value))}
              />
            </div>

            {/* Opacity */}
            <div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
                <span style={{ color: 'var(--text-muted)' }}>Shadow Opacity</span>
                <span style={{ fontWeight: '700', color: '#38bdf8' }}>{shadow.opacity}%</span>
              </div>
              <input
                type="range"
                min="5"
                max="100"
                value={shadow.opacity}
                onChange={(e) => updateShadow('opacity', Number(e.target.value))}
              />
            </div>

          </div>
        )}
      </div>

      {/* 3. Subject Image Adjustments */}
      <div style={{ background: 'rgba(30, 41, 59, 0.6)', padding: '14px', borderRadius: '12px', border: '1px solid rgba(255,255,255,0.08)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px' }}>
          <Sun size={16} color="#f59e0b" />
          <h4 style={{ fontSize: '0.88rem', fontWeight: '700', margin: 0 }}>Subject Color Tuning</h4>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
          {/* Brightness */}
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
              <span style={{ color: 'var(--text-muted)' }}>Brightness</span>
              <span style={{ fontWeight: '700', color: '#f59e0b' }}>{adjustments.brightness}%</span>
            </div>
            <input
              type="range"
              min="50"
              max="150"
              value={adjustments.brightness}
              onChange={(e) => updateAdjustments('brightness', Number(e.target.value))}
            />
          </div>

          {/* Contrast */}
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
              <span style={{ color: 'var(--text-muted)' }}>Contrast</span>
              <span style={{ fontWeight: '700', color: '#f59e0b' }}>{adjustments.contrast}%</span>
            </div>
            <input
              type="range"
              min="50"
              max="150"
              value={adjustments.contrast}
              onChange={(e) => updateAdjustments('contrast', Number(e.target.value))}
            />
          </div>

          {/* Saturation */}
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
              <span style={{ color: 'var(--text-muted)' }}>Saturation</span>
              <span style={{ fontWeight: '700', color: '#f59e0b' }}>{adjustments.saturation}%</span>
            </div>
            <input
              type="range"
              min="0"
              max="200"
              value={adjustments.saturation}
              onChange={(e) => updateAdjustments('saturation', Number(e.target.value))}
            />
          </div>
        </div>
      </div>

      {/* 4. Subject Position & Scale */}
      <div style={{ background: 'rgba(30, 41, 59, 0.6)', padding: '14px', borderRadius: '12px', border: '1px solid rgba(255,255,255,0.08)' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '12px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Move size={16} color="#10b981" />
            <h4 style={{ fontSize: '0.88rem', fontWeight: '700', margin: 0 }}>Subject Scale & Position</h4>
          </div>
          <button onClick={onResetPosition} className="btn btn-outline btn-sm" style={{ padding: '2px 6px', fontSize: '0.72rem' }}>
            <RotateCcw size={12} /> Reset
          </button>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
          {/* Scale */}
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
              <span style={{ color: 'var(--text-muted)' }}>Scale Factor</span>
              <span style={{ fontWeight: '700', color: '#10b981' }}>{Math.round(subjectPosition.scale * 100)}%</span>
            </div>
            <input
              type="range"
              min="0.3"
              max="2.5"
              step="0.05"
              value={subjectPosition.scale}
              onChange={(e) => setSubjectPosition({ ...subjectPosition, scale: Number(e.target.value) })}
            />
          </div>

          {/* Rotation */}
          <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
              <span style={{ color: 'var(--text-muted)' }}>Rotation Angle</span>
              <span style={{ fontWeight: '700', color: '#10b981' }}>{subjectPosition.rotation}°</span>
            </div>
            <input
              type="range"
              min="-180"
              max="180"
              value={subjectPosition.rotation}
              onChange={(e) => setSubjectPosition({ ...subjectPosition, rotation: Number(e.target.value) })}
            />
          </div>
        </div>
      </div>

    </div>
  );
}
