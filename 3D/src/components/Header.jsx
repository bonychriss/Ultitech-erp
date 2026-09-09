import React from 'react';
import { 
  Download, 
  RotateCcw, 
  RotateCw, 
  ZoomIn, 
  ZoomOut, 
  Maximize2, 
  Columns, 
  Sun,
  Moon
} from 'lucide-react';
import logo from '../assets/logo.png';

export default function Header({
  canUndo,
  canRedo,
  onUndo,
  onRedo,
  zoom,
  onZoomIn,
  onZoomOut,
  onResetZoom,
  compareMode,
  setCompareMode,
  onOpenExport,
  hasImage,
  theme,
  setTheme
}) {
  return (
    <header className="glass-panel" style={{ borderRadius: '0 0 16px 16px', padding: '12px 24px', margin: '0 0 16px 0', zIndex: 50 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: '16px' }}>
        
        {/* Brand Logo */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <img
            src={logo}
            alt="Bcut"
            style={{
              height: '36px',
              width: 'auto',
              maxWidth: '180px',
              objectFit: 'contain',
              display: 'block'
            }}
          />
        </div>

        {/* Action Controls, Theme Toggle & Export */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
          
          {/* Light / Dark Theme Toggle */}
          <button
            onClick={() => setTheme(theme === 'light' ? 'dark' : 'light')}
            className="btn btn-outline btn-sm"
            title={`Switch to ${theme === 'light' ? 'Dark' : 'Light'} Mode`}
            style={{ padding: '8px' }}
          >
            {theme === 'light' ? <Moon size={16} /> : <Sun size={16} />}
          </button>

          {/* History Undo/Redo */}
          <div style={{ display: 'flex', background: 'var(--bg-subtle)', padding: '3px', borderRadius: '10px', border: '1px solid var(--bg-subtle-border)' }}>
            <button
              onClick={onUndo}
              disabled={!canUndo}
              className="btn btn-outline btn-sm"
              style={{ border: 'none', padding: '6px 8px' }}
              title="Undo (Ctrl+Z)"
            >
              <RotateCcw size={16} />
            </button>
            <button
              onClick={onRedo}
              disabled={!canRedo}
              className="btn btn-outline btn-sm"
              style={{ border: 'none', padding: '6px 8px' }}
              title="Redo (Ctrl+Y)"
            >
              <RotateCw size={16} />
            </button>
          </div>

          {/* View / Compare controls */}
          {hasImage && (
            <>
              <button
                onClick={() => setCompareMode(!compareMode)}
                className={`btn btn-sm ${compareMode ? 'btn-primary' : 'btn-secondary'}`}
                title="Toggle Before/After Split Comparison"
              >
                <Columns size={16} />
                <span>{compareMode ? 'Split View' : 'Compare'}</span>
              </button>

              <div style={{ display: 'flex', alignItems: 'center', background: 'var(--bg-subtle)', padding: '3px', borderRadius: '10px', border: '1px solid var(--bg-subtle-border)' }}>
                <button onClick={onZoomOut} className="btn btn-outline btn-sm" style={{ border: 'none', padding: '6px' }} title="Zoom Out">
                  <ZoomOut size={16} />
                </button>
                <span style={{ fontSize: '0.78rem', padding: '0 8px', fontWeight: '600', minWidth: '45px', textAlign: 'center' }}>
                  {Math.round(zoom * 100)}%
                </span>
                <button onClick={onZoomIn} className="btn btn-outline btn-sm" style={{ border: 'none', padding: '6px' }} title="Zoom In">
                  <ZoomIn size={16} />
                </button>
                <button onClick={onResetZoom} className="btn btn-outline btn-sm" style={{ border: 'none', padding: '6px' }} title="Fit to Screen">
                  <Maximize2 size={14} />
                </button>
              </div>
            </>
          )}

          {/* Export Download Button */}
          <button
            onClick={onOpenExport}
            disabled={!hasImage}
            className="btn btn-primary"
            style={{ gap: '8px' }}
          >
            <Download size={18} />
            <span>Export & Save</span>
          </button>
        </div>

      </div>
    </header>
  );
}
