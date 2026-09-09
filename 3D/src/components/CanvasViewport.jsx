import React, { useRef, useEffect, useLayoutEffect, useState } from 'react';
import { renderCompositeCanvas } from '../utils/imageProcessing';
import ScanOverlay from './ScanOverlay';

export default function CanvasViewport({
  originalCanvas,
  subjectCanvas,
  bgType,
  bgColor,
  gradientConfig,
  bgImage,
  bgBlur,
  bgOpacity,
  subjectPosition,
  setSubjectPosition,
  subjectEffects,
  removalMode,
  wandTolerance,
  onMagicWandPurge,
  brushMode,
  brushSize,
  brushHardness,
  onApplyBrushStroke,
  onBrushStrokeEnd,
  zoom,
  compareMode,
  isProcessingAI,
  aiProgress,
  cropActive,
  cropRect,
  setCropRect
}) {
  const compositeCanvasRef = useRef(null);
  const compareOriginalRef = useRef(null);
  const compareFrameRef = useRef(null);
  const containerRef = useRef(null);

  const [compareSliderPos, setCompareSliderPos] = useState(50);
  const [isPaintingBrush, setIsPaintingBrush] = useState(false);
  const [mouseCanvasPos, setMouseCanvasPos] = useState(null);
  const [fit, setFit] = useState({ w: 800, h: 600 });
  const cropDragRef = useRef(null);
  const compareDragRef = useRef(false);

  const clampCrop = (rect, maxW, maxH) => {
    let { x, y, w, h } = rect;
    w = Math.max(24, w);
    h = Math.max(24, h);
    x = Math.max(0, Math.min(x, maxW - w));
    y = Math.max(0, Math.min(y, maxH - h));
    if (x + w > maxW) w = maxW - x;
    if (y + h > maxH) h = maxH - y;
    return { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h) };
  };

  // Render composite whenever properties change
  useEffect(() => {
    if (!compositeCanvasRef.current || !subjectCanvas) return;
    renderCompositeCanvas({
      canvas: compositeCanvasRef.current,
      subjectCanvas,
      originalCanvas,
      bgType,
      bgColor,
      gradientConfig,
      bgImage,
      bgBlur,
      bgOpacity,
      subjectPosition,
      subjectEffects,
      showGrid: bgType === 'transparent'
    });
  }, [
    subjectCanvas,
    originalCanvas,
    bgType,
    bgColor,
    gradientConfig,
    bgImage,
    bgBlur,
    bgOpacity,
    subjectPosition,
    subjectEffects
  ]);

  useEffect(() => {
    const onMove = (e) => {
      if (!cropDragRef.current || !originalCanvas || !cropActive) return;
      const canvas = compositeCanvasRef.current;
      if (!canvas) return;
      const rect = canvas.getBoundingClientRect();
      const x = (e.clientX - rect.left) * (canvas.width / rect.width);
      const y = (e.clientY - rect.top) * (canvas.height / rect.height);
      const { type, startX, startY, startRect } = cropDragRef.current;
      const dx = x - startX;
      const dy = y - startY;
      let next = { ...startRect };
      if (type === 'move') {
        next.x = startRect.x + dx;
        next.y = startRect.y + dy;
      } else {
        if (type.includes('w')) {
          next.x = startRect.x + dx;
          next.w = startRect.w - dx;
        }
        if (type.includes('e')) next.w = startRect.w + dx;
        if (type.includes('n')) {
          next.y = startRect.y + dy;
          next.h = startRect.h - dy;
        }
        if (type.includes('s')) next.h = startRect.h + dy;
      }
      setCropRect(clampCrop(next, originalCanvas.width, originalCanvas.height));
    };
    const onUp = () => { cropDragRef.current = null; };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    return () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
    };
  }, [originalCanvas, cropActive, setCropRect]);

  useLayoutEffect(() => {
    if (!originalCanvas || !containerRef.current) return;
    const wrap = containerRef.current;
    const update = () => {
      const pad = 48;
      const bar = compareMode ? 60 : 0;
      const maxW = Math.max(160, wrap.clientWidth - pad);
      const maxH = Math.max(160, wrap.clientHeight - pad - bar);
      const scale = Math.min(maxW / originalCanvas.width, maxH / originalCanvas.height);
      setFit({
        w: Math.max(1, Math.round(originalCanvas.width * scale)),
        h: Math.max(1, Math.round(originalCanvas.height * scale))
      });
    };
    update();
    const ro = new ResizeObserver(update);
    ro.observe(wrap);
    return () => ro.disconnect();
  }, [originalCanvas, compareMode]);

  useEffect(() => {
    if (compareMode) setCompareSliderPos(50);
  }, [compareMode]);

  useEffect(() => {
    if (!compareMode || !originalCanvas || !compareOriginalRef.current) return;
    const c = compareOriginalRef.current;
    if (c.width !== originalCanvas.width) c.width = originalCanvas.width;
    if (c.height !== originalCanvas.height) c.height = originalCanvas.height;
    c.getContext('2d').drawImage(originalCanvas, 0, 0);
  }, [compareMode, originalCanvas]);

  const setCompareFromClientX = (clientX) => {
    const frame = compareFrameRef.current;
    if (!frame) return;
    const rect = frame.getBoundingClientRect();
    if (rect.width <= 0) return;
    const pct = ((clientX - rect.left) / rect.width) * 100;
    setCompareSliderPos(Math.max(0, Math.min(100, pct)));
  };

  useEffect(() => {
    if (!compareMode) return;
    const onMove = (e) => {
      if (!compareDragRef.current) return;
      setCompareFromClientX(e.clientX);
    };
    const onUp = () => { compareDragRef.current = false; };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    return () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
    };
  }, [compareMode]);

  // Convert Mouse event coords to canvas pixel coordinates
  const getCanvasCoords = (e) => {
    const canvas = compositeCanvasRef.current;
    if (!canvas) return { x: 0, y: 0 };
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
      x: (e.clientX - rect.left) * scaleX,
      y: (e.clientY - rect.top) * scaleY
    };
  };

  // Canvas Click Handlers for Eyedropper & Magic Wand
  const handleCanvasClick = (e) => {
    const canvas = compositeCanvasRef.current;
    if (!canvas || !originalCanvas) return;
    const { x, y } = getCanvasCoords(e);

    if (removalMode === 'wand') {
      onMagicWandPurge(Math.floor(x), Math.floor(y));
    }
  };

  // Brush Painting Mouse Handlers
  const handleMouseDown = (e) => {
    if (cropActive || compareMode) return;
    if (removalMode === 'brush') {
      setIsPaintingBrush(true);
      handleMouseMove(e);
    }
  };

  const handleMouseMove = (e) => {
    const coords = getCanvasCoords(e);
    setMouseCanvasPos(coords);

    if (removalMode === 'brush' && isPaintingBrush) {
      onApplyBrushStroke(coords.x, coords.y, brushSize, brushHardness, brushMode);
    }
  };

  const handleMouseUp = () => {
    if (isPaintingBrush && onBrushStrokeEnd) {
      onBrushStrokeEnd();
    }
    setIsPaintingBrush(false);
  };

  return (
    <div
      ref={containerRef}
      className="checkerboard-bg glass-panel viewport-shell"
    >
      <div className="viewport-column">
        <div
          style={{
            transform: `scale(${zoom})`,
            transition: zoom === 1 ? 'none' : 'transform 0.15s ease-out'
          }}
        >
          <div
            ref={compareFrameRef}
            className="compare-frame"
            style={{ width: fit.w, height: fit.h }}
          >
            <canvas
              ref={compositeCanvasRef}
              width={originalCanvas ? originalCanvas.width : 800}
              height={originalCanvas ? originalCanvas.height : 600}
              onClick={compareMode ? undefined : handleCanvasClick}
              onMouseDown={handleMouseDown}
              onMouseMove={compareMode ? undefined : handleMouseMove}
              onMouseUp={handleMouseUp}
              onMouseLeave={() => { handleMouseUp(); setMouseCanvasPos(null); }}
              style={{
                display: 'block',
                width: fit.w,
                height: fit.h,
                cursor: cropActive
                  ? 'default'
                  : compareMode
                  ? 'ew-resize'
                  : removalMode === 'wand'
                  ? 'cell'
                  : removalMode === 'brush'
                  ? 'none'
                  : 'default'
              }}
            />

            {compareMode && originalCanvas && (
              <>
                <div
                  className="compare-clip"
                  style={{ width: `${compareSliderPos}%` }}
                >
                  <canvas
                    ref={compareOriginalRef}
                    width={originalCanvas.width}
                    height={originalCanvas.height}
                    style={{ width: fit.w, height: fit.h }}
                  />
                  {compareSliderPos > 12 && (
                    <span className="compare-badge compare-badge-left">Original</span>
                  )}
                </div>

                {compareSliderPos < 88 && (
                  <span className="compare-badge compare-badge-right">Edited</span>
                )}

                <div
                  className="compare-divider"
                  style={{ left: `${compareSliderPos}%` }}
                >
                  <span className="compare-handle" aria-hidden="true">
                    <span />
                    <span />
                  </span>
                </div>

                <div
                  className="compare-hit"
                  onPointerDown={(e) => {
                    compareDragRef.current = true;
                    e.currentTarget.setPointerCapture?.(e.pointerId);
                    setCompareFromClientX(e.clientX);
                  }}
                />
              </>
            )}

            {cropActive && cropRect && originalCanvas && (
          <div
            className="crop-overlay"
            onPointerMove={(e) => {
              if (!cropDragRef.current || !originalCanvas) return;
              const { x, y } = getCanvasCoords(e);
              const { type, startX, startY, startRect } = cropDragRef.current;
              const dx = x - startX;
              const dy = y - startY;
              let next = { ...startRect };
              if (type === 'move') {
                next.x = startRect.x + dx;
                next.y = startRect.y + dy;
              } else {
                if (type.includes('w')) {
                  next.x = startRect.x + dx;
                  next.w = startRect.w - dx;
                }
                if (type.includes('e')) next.w = startRect.w + dx;
                if (type.includes('n')) {
                  next.y = startRect.y + dy;
                  next.h = startRect.h - dy;
                }
                if (type.includes('s')) next.h = startRect.h + dy;
              }
              setCropRect(clampCrop(next, originalCanvas.width, originalCanvas.height));
            }}
            onPointerUp={() => { cropDragRef.current = null; }}
          >
            <div
              className="crop-box"
              style={{
                left: `${(cropRect.x / originalCanvas.width) * 100}%`,
                top: `${(cropRect.y / originalCanvas.height) * 100}%`,
                width: `${(cropRect.w / originalCanvas.width) * 100}%`,
                height: `${(cropRect.h / originalCanvas.height) * 100}%`
              }}
              onPointerDown={(e) => {
                e.stopPropagation();
                const { x, y } = getCanvasCoords(e);
                cropDragRef.current = { type: 'move', startX: x, startY: y, startRect: { ...cropRect } };
                e.currentTarget.setPointerCapture?.(e.pointerId);
              }}
            >
              {[
                { id: 'nw', left: '0%', top: '0%', cursor: 'nwse-resize' },
                { id: 'n', left: '50%', top: '0%', cursor: 'ns-resize' },
                { id: 'ne', left: '100%', top: '0%', cursor: 'nesw-resize' },
                { id: 'e', left: '100%', top: '50%', cursor: 'ew-resize' },
                { id: 'se', left: '100%', top: '100%', cursor: 'nwse-resize' },
                { id: 's', left: '50%', top: '100%', cursor: 'ns-resize' },
                { id: 'sw', left: '0%', top: '100%', cursor: 'nesw-resize' },
                { id: 'w', left: '0%', top: '50%', cursor: 'ew-resize' }
              ].map((handle) => (
                <div
                  key={handle.id}
                  className="crop-handle"
                  style={{ left: handle.left, top: handle.top, cursor: handle.cursor }}
                  onPointerDown={(e) => {
                    e.stopPropagation();
                    const { x, y } = getCanvasCoords(e);
                    cropDragRef.current = { type: handle.id, startX: x, startY: y, startRect: { ...cropRect } };
                    e.currentTarget.setPointerCapture?.(e.pointerId);
                  }}
                />
              ))}
            </div>
          </div>
        )}

        {removalMode === 'brush' && !cropActive && !compareMode && mouseCanvasPos && (
          <div
            style={{
              position: 'absolute',
              pointerEvents: 'none',
              left: `${(mouseCanvasPos.x / (compositeCanvasRef.current?.width || 1)) * 100}%`,
              top: `${(mouseCanvasPos.y / (compositeCanvasRef.current?.height || 1)) * 100}%`,
              width: `${(brushSize / (compositeCanvasRef.current?.width || 1)) * 100 * 2}%`,
              height: `${(brushSize / (compositeCanvasRef.current?.height || 1)) * 100 * 2}%`,
              transform: 'translate(-50%, -50%)',
              borderRadius: '50%',
              border: `2px solid ${brushMode === 'erase' ? '#ef4444' : '#10b981'}`,
              backgroundColor: `${brushMode === 'erase' ? 'rgba(239, 68, 68, 0.2)' : 'rgba(16, 185, 129, 0.2)'}`,
              boxShadow: '0 0 10px rgba(0,0,0,0.5)',
              zIndex: 30
            }}
          />
        )}
          </div>
        </div>

        {compareMode && (
          <div className="compare-bar">
            <span>Before</span>
            <input
              type="range"
              min="0"
              max="100"
              value={compareSliderPos}
              onChange={(e) => setCompareSliderPos(Number(e.target.value))}
            />
            <span>After</span>
          </div>
        )}
      </div>

      {isProcessingAI && <ScanOverlay progress={aiProgress} />}

    </div>
  );
}
