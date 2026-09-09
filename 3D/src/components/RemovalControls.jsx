import React, { useState, useEffect } from 'react';
import { RotateCcw, RotateCw, FlipHorizontal, Crop, Check, X } from 'lucide-react';
import ColorPicker from './ColorPicker';

const PRESET_COLORS = [
  '#FFFFFF',
  '#F8FAFC',
  '#E2E8F0',
  '#3B82F6',
  '#10B981',
  '#F59E0B',
  '#EF4444',
  '#8B5CF6',
  '#0F172A',
  '#000000'
];

function SimpleSlider({ label, value, min, max, onChange }) {
  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.78rem', marginBottom: '4px' }}>
        <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>{label}</span>
      </div>
      <input
        type="range"
        min={min}
        max={max}
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
      />
    </div>
  );
}

export default function RemovalControls({
  bgColor,
  setBgColor,
  bgType,
  setBgType,
  subjectEffects,
  setSubjectEffects,
  subjectPosition,
  setSubjectPosition,
  setRemovalMode,
  brushMode,
  setBrushMode,
  brushSize,
  setBrushSize,
  onResetEdits,
  cropActive,
  onStartCrop,
  onApplyCrop,
  onCancelCrop
}) {
  const { brightness, contrast, saturation } = subjectEffects.adjustments;
  const [panel, setPanel] = useState('background');

  useEffect(() => {
    setRemovalMode(panel === 'adjust' ? 'brush' : 'ai');
    if (panel !== 'turn') onCancelCrop?.();
  }, [panel, setRemovalMode]);

  const setAdjustment = (key, value) => {
    setSubjectEffects({
      ...subjectEffects,
      adjustments: { ...subjectEffects.adjustments, [key]: value }
    });
  };

  const resetEdits = () => {
    onResetEdits?.();
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>

      <div style={{
        display: 'grid',
        gridTemplateColumns: '1fr 1fr 1fr',
        gap: '4px',
        background: 'var(--bg-subtle)',
        padding: '4px',
        borderRadius: '12px',
        border: '1px solid var(--bg-subtle-border)'
      }}>
        {[
          { id: 'background', label: 'Background' },
          { id: 'adjust', label: 'Adjust' },
          { id: 'turn', label: 'Turn' }
        ].map((tab) => (
          <button
            key={tab.id}
            type="button"
            onClick={() => setPanel(tab.id)}
            className={`tab-btn ${panel === tab.id ? 'active' : ''}`}
            style={{
              justifyContent: 'center',
              padding: '8px 4px',
              borderRadius: '8px',
              fontSize: '0.78rem',
              fontWeight: 400,
              borderBottom: 'none',
              background: panel === tab.id ? 'var(--bg-card)' : 'transparent',
              color: panel === tab.id ? 'var(--accent-primary)' : 'var(--text-muted)'
            }}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {panel === 'background' && (
      <div>
        <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', margin: '0 0 10px 0' }}>
          Replace background with
        </p>

        <ColorPicker
          color={bgColor}
          onChange={(hex) => {
            setBgColor(hex);
            setBgType('color');
          }}
        />

        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px', margin: '12px 0' }}>
          {PRESET_COLORS.map((c) => (
            <button
              key={c}
              onClick={() => { setBgColor(c); setBgType('color'); }}
              title={c}
              style={{
                width: '28px',
                height: '28px',
                borderRadius: '50%',
                backgroundColor: c,
                border: bgType === 'color' && bgColor.toLowerCase() === c.toLowerCase()
                  ? '2px solid var(--accent-primary)'
                  : '1px solid rgba(0,0,0,0.15)',
                cursor: 'pointer',
                padding: 0
              }}
            />
          ))}
        </div>

        <button
          onClick={() => setBgType('transparent')}
          className={`btn btn-sm ${bgType === 'transparent' ? 'btn-primary' : 'btn-outline'}`}
          style={{ fontWeight: '400', width: '100%' }}
        >
          Transparent
        </button>
      </div>
      )}

      {panel === 'adjust' && (
      <div>
        <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', margin: '0 0 12px 0' }}>
          Adjust photo
        </p>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
          <SimpleSlider
            label="Brightness"
            min={50}
            max={150}
            value={brightness}
            onChange={(v) => setAdjustment('brightness', v)}
          />
          <SimpleSlider
            label="Contrast"
            min={50}
            max={150}
            value={contrast}
            onChange={(v) => setAdjustment('contrast', v)}
          />
          <SimpleSlider
            label="Color"
            min={0}
            max={200}
            value={saturation}
            onChange={(v) => setAdjustment('saturation', v)}
          />
          <div>
            <p style={{ fontSize: '0.78rem', color: 'var(--text-muted)', margin: '4px 0 8px 0' }}>
              Brush — paint on the photo
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', marginBottom: '10px' }}>
              <button
                type="button"
                className={`btn btn-sm ${brushMode === 'erase' ? 'btn-primary' : 'btn-outline'}`}
                style={{ fontWeight: 400 }}
                onClick={() => setBrushMode('erase')}
              >
                Erase
              </button>
              <button
                type="button"
                className={`btn btn-sm ${brushMode === 'restore' ? 'btn-primary' : 'btn-outline'}`}
                style={{ fontWeight: 400 }}
                onClick={() => setBrushMode('restore')}
              >
                Restore
              </button>
            </div>
            <SimpleSlider
              label="Brush size"
              min={8}
              max={80}
              value={brushSize}
              onChange={setBrushSize}
            />
          </div>
        </div>
      </div>
      )}

      {panel === 'turn' && (
      <div>
        <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', margin: '0 0 12px 0' }}>
          Turn photo
        </p>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', marginBottom: '8px' }}>
          <button
            type="button"
            className="btn btn-outline btn-sm"
            style={{ fontWeight: 400 }}
            onClick={() => setSubjectPosition({
              ...subjectPosition,
              rotation: ((subjectPosition.rotation || 0) - 90 + 360) % 360
            })}
          >
            <RotateCcw size={14} />
            Rotate left
          </button>
          <button
            type="button"
            className="btn btn-outline btn-sm"
            style={{ fontWeight: 400 }}
            onClick={() => setSubjectPosition({
              ...subjectPosition,
              rotation: ((subjectPosition.rotation || 0) + 90) % 360
            })}
          >
            <RotateCw size={14} />
            Rotate right
          </button>
        </div>
        <button
          type="button"
          className="btn btn-outline btn-sm"
          style={{ fontWeight: 400, width: '100%' }}
          onClick={() => setSubjectPosition({
            ...subjectPosition,
            flipH: !subjectPosition.flipH
          })}
        >
          <FlipHorizontal size={14} />
          Flip
        </button>

        <div style={{ marginTop: '12px' }}>
          <p style={{ fontSize: '0.8rem', color: 'var(--text-muted)', margin: '0 0 8px 0' }}>
            Crop
          </p>
          {!cropActive ? (
            <button
              type="button"
              className="btn btn-outline btn-sm"
              style={{ fontWeight: 400, width: '100%' }}
              onClick={onStartCrop}
            >
              <Crop size={14} />
              Crop photo
            </button>
          ) : (
            <>
              <p style={{ fontSize: '0.75rem', color: 'var(--text-muted)', margin: '0 0 8px 0' }}>
                Drag the box on the photo, then keep this area.
              </p>
              <button
                type="button"
                className="btn btn-primary btn-sm"
                style={{ fontWeight: 400, width: '100%', marginBottom: '8px' }}
                onClick={onApplyCrop}
              >
                <Check size={14} />
                Keep this area
              </button>
              <button
                type="button"
                className="btn btn-outline btn-sm"
                style={{ fontWeight: 400, width: '100%' }}
                onClick={onCancelCrop}
              >
                <X size={14} />
                Cancel crop
              </button>
            </>
          )}
        </div>
      </div>
      )}

      <button
        type="button"
        className="btn btn-outline btn-sm"
        style={{ fontWeight: 400, width: '100%', color: 'var(--text-muted)' }}
        onClick={resetEdits}
      >
        <RotateCcw size={14} />
        Reset edits
      </button>

    </div>
  );
}
