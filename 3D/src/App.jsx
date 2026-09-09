import React, { useState, useRef, useEffect, useCallback } from 'react';
import { Upload } from 'lucide-react';

import Header from './components/Header';
import ImageUploader from './components/ImageUploader';
import CanvasViewport from './components/CanvasViewport';
import RemovalControls from './components/RemovalControls';
import BatchStrip from './components/BatchStrip';
import ApplyStyleModal from './components/ApplyStyleModal';
import ExportModal from './components/ExportModal';
import { removeColorKey, magicWandPurge, applyBrush, cropCanvas, imageToCanvas, removeBackgroundFromCanvas, makeThumb } from './utils/imageProcessing';

const DEFAULT_SUBJECT_POSITION = { x: 0, y: 0, scale: 1, rotation: 0, flipH: false, flipV: false };
const DEFAULT_SUBJECT_EFFECTS = {
  outline: { enabled: false, size: 6, color: '#FFFFFF' },
  shadow: { enabled: false, blur: 20, offsetX: 10, offsetY: 15, opacity: 40, color: '#000000' },
  adjustments: { brightness: 100, contrast: 100, saturation: 100 }
};
const DEFAULT_GRADIENT = {
  type: 'linear',
  color1: '#3b82f6',
  color2: '#8b5cf6',
  angle: 135
};

function cloneStyle(style) {
  return JSON.parse(JSON.stringify(style));
}

export default function App() {
  // Image & Canvas References
  const [originalCanvas, setOriginalCanvas] = useState(null);
  const [subjectCanvas, setSubjectCanvas] = useState(null);
  const [fileName, setFileName] = useState('');

  // Undo / Redo History Stack (stores subjectCanvas data URLs)
  const [history, setHistory] = useState([]);
  const [historyIndex, setHistoryIndex] = useState(-1);

  // Light / Dark Theme
  const [theme, setTheme] = useState(() => localStorage.getItem('bcut-theme') || 'light');

  // Removal Engine States
  const [removalMode, setRemovalMode] = useState('ai'); // 'ai' | 'chroma' | 'wand' | 'brush'
  const [isProcessingAI, setIsProcessingAI] = useState(false);
  const [aiProgress, setAiProgress] = useState('');
  const [hasRunAI, setHasRunAI] = useState(false);

  // Chroma Key / Color Picker States
  const [chromaColor, setChromaColor] = useState('#00FF00'); // default studio green
  const [chromaTolerance, setChromaTolerance] = useState(30);
  const [chromaFuzziness, setChromaFuzziness] = useState(20);
  const [desaturateSpill, setDesaturateSpill] = useState(true);

  // Magic Wand & Brush States
  const [wandTolerance, setWandTolerance] = useState(25);
  const [brushMode, setBrushMode] = useState('erase'); // 'erase' | 'restore'
  const [brushSize, setBrushSize] = useState(30);
  const [brushHardness, setBrushHardness] = useState(50);

  // Background Replacement States
  const [bgType, setBgType] = useState('color'); // 'color' | 'gradient' | 'image' | 'pattern' | 'transparent'
  const [bgColor, setBgColor] = useState('#F8FAFC');
  const [gradientConfig, setGradientConfig] = useState({ ...DEFAULT_GRADIENT });
  const [bgImage, setBgImage] = useState(null);
  const [bgBlur, setBgBlur] = useState(0);
  const [bgOpacity, setBgOpacity] = useState(100);

  // Subject Effects & Transforms
  const [subjectPosition, setSubjectPosition] = useState({ ...DEFAULT_SUBJECT_POSITION });
  const [subjectEffects, setSubjectEffects] = useState(cloneStyle(DEFAULT_SUBJECT_EFFECTS));

  // Viewport & Export Controls
  const [zoom, setZoom] = useState(1);
  const [compareMode, setCompareMode] = useState(false);
  const [isExportOpen, setIsExportOpen] = useState(false);
  const [cropActive, setCropActive] = useState(false);
  const [cropRect, setCropRect] = useState(null);
  const [batchImages, setBatchImages] = useState([]);
  const [activeBatchId, setActiveBatchId] = useState(null);
  const [showApplyBanner, setShowApplyBanner] = useState(false);
  const [isApplyModalOpen, setIsApplyModalOpen] = useState(false);
  const [applyBannerDismissed, setApplyBannerDismissed] = useState(false);

  const historyIndexRef = useRef(historyIndex);
  historyIndexRef.current = historyIndex;
  const aiJobRef = useRef(0);
  const cleanCutoutRef = useRef(null);
  const fullOriginalRef = useRef(null);
  const subjectCanvasRef = useRef(null);
  subjectCanvasRef.current = subjectCanvas;
  const progressThrottleRef = useRef(0);
  const styleRef = useRef(null);

  const captureStyle = useCallback(() => ({
    bgType,
    bgColor,
    gradientConfig: cloneStyle(gradientConfig),
    bgImage,
    bgBlur,
    bgOpacity,
    subjectPosition: { ...subjectPosition },
    subjectEffects: cloneStyle(subjectEffects)
  }), [bgType, bgColor, gradientConfig, bgImage, bgBlur, bgOpacity, subjectPosition, subjectEffects]);

  styleRef.current = captureStyle;

  const restoreStyle = useCallback((style) => {
    const s = style || {
      bgType: 'color',
      bgColor: '#F8FAFC',
      gradientConfig: { ...DEFAULT_GRADIENT },
      bgImage: null,
      bgBlur: 0,
      bgOpacity: 100,
      subjectPosition: { ...DEFAULT_SUBJECT_POSITION },
      subjectEffects: cloneStyle(DEFAULT_SUBJECT_EFFECTS)
    };
    setBgType(s.bgType || 'color');
    setBgColor(s.bgColor || '#F8FAFC');
    setGradientConfig(s.gradientConfig ? cloneStyle(s.gradientConfig) : { ...DEFAULT_GRADIENT });
    setBgImage(s.bgImage ?? null);
    setBgBlur(s.bgBlur ?? 0);
    setBgOpacity(s.bgOpacity ?? 100);
    setSubjectPosition(s.subjectPosition ? { ...s.subjectPosition } : { ...DEFAULT_SUBJECT_POSITION });
    setSubjectEffects(s.subjectEffects ? cloneStyle(s.subjectEffects) : cloneStyle(DEFAULT_SUBJECT_EFFECTS));
  }, []);

  const reportAiProgress = useCallback((message) => {
    const now = Date.now();
    if (now - progressThrottleRef.current < 120 && !/Downloading|Scanning \d+ of/.test(message)) return;
    progressThrottleRef.current = now;
    setAiProgress(message);
  }, []);

  const yieldForScan = () => new Promise((resolve) => {
    requestAnimationFrame(() => {
      setTimeout(resolve, 80);
    });
  });

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('bcut-theme', theme);
  }, [theme]);

  // Warm the AI model while the user is on the upload screen
  useEffect(() => {
    import('@imgly/background-removal').then((imgly) => {
      imgly.preload?.().catch(() => {});
    }).catch(() => {});
  }, []);

  const saveToHistory = useCallback((canvas) => {
    if (!canvas) return;
    const dataUrl = canvas.toDataURL();
    const idx = historyIndexRef.current;
    setHistory(prev => [...prev.slice(0, idx + 1), dataUrl]);
    setHistoryIndex(idx + 1);
  }, []);

  // Client-side AI background removal (runs automatically on upload)
  const runAIRemoval = useCallback(async (sourceCanvas) => {
    if (!sourceCanvas) return;
    const jobId = ++aiJobRef.current;
    setIsProcessingAI(true);
    setAiProgress('Removing background…');
    await yieldForScan();
    if (jobId !== aiJobRef.current) return;
    try {
      const processed = await removeBackgroundFromCanvas(sourceCanvas, (key, pct) => {
        if (jobId !== aiJobRef.current) return;
        const isDownload = /fetch|download|load/i.test(String(key));
        reportAiProgress(isDownload ? `Downloading AI model ${pct}%` : `Removing background ${pct}%`);
      });
      if (jobId !== aiJobRef.current) return;
      setSubjectCanvas(processed);
      cleanCutoutRef.current = processed.toDataURL();
      saveToHistory(processed);
      setHasRunAI(true);
    } finally {
      if (jobId === aiJobRef.current) {
        setIsProcessingAI(false);
        setAiProgress('');
      }
    }
  }, [saveToHistory, reportAiProgress]);

  // Load image file(s) into workspace
  const handleImageLoaded = (imgElement, name, options = {}) => {
    const origCanvas = imageToCanvas(imgElement);
    const ctx = origCanvas.getContext('2d');
    const subCanvas = imageToCanvas(imgElement);

    setOriginalCanvas(origCanvas);
    setSubjectCanvas(subCanvas);
    setFileName(name);
    setHasRunAI(false);
    setRemovalMode('ai');
    cleanCutoutRef.current = null;
    fullOriginalRef.current = origCanvas.toDataURL();
    setCropActive(false);
    setCropRect(null);

    const initialUrl = origCanvas.toDataURL();
    setHistory([initialUrl]);
    setHistoryIndex(0);
    setSubjectPosition({ ...DEFAULT_SUBJECT_POSITION });
    setSubjectEffects(cloneStyle(DEFAULT_SUBJECT_EFFECTS));
    setBgType('color');
    setBgColor('#F8FAFC');
    setGradientConfig({ ...DEFAULT_GRADIENT });
    setBgImage(null);
    setBgBlur(0);
    setBgOpacity(100);

    const cornerPixel = ctx.getImageData(5, 5, 1, 1).data;
    const detectedHex = `#${((1 << 24) + (cornerPixel[0] << 16) + (cornerPixel[1] << 8) + cornerPixel[2]).toString(16).slice(1)}`;
    setChromaColor(detectedHex);

    if (!options.skipAI) runAIRemoval(origCanvas);
    return origCanvas;
  };

  const handleImagesLoaded = async (items) => {
    if (!items?.length) return;
    const jobId = ++aiJobRef.current;
    const total = items.length;

    const batch = items.map((item, i) => ({
      id: `b${Date.now()}-${i}`,
      name: item.name,
      previewUrl: makeThumb(item.img),
      cutoutPreviewUrl: null,
      originalCanvas: imageToCanvas(item.img),
      cutoutCanvas: null,
      status: i === 0 ? 'scanning' : 'queued',
      style: null,
      styleApplied: false
    }));

    setBatchImages(batch);
    setActiveBatchId(batch[0].id);
    setApplyBannerDismissed(false);
    setShowApplyBanner(false);
    setIsApplyModalOpen(false);
    handleImageLoaded(items[0].img, items[0].name, { skipAI: true });
    setIsProcessingAI(true);
    setAiProgress(`Scanning 1 of ${total}…`);
    await yieldForScan();
    if (jobId !== aiJobRef.current) return;

    try {
      const nextBatch = [...batch];
      for (let i = 0; i < nextBatch.length; i++) {
        if (jobId !== aiJobRef.current) return;
        setActiveBatchId(nextBatch[i].id);
        setOriginalCanvas(nextBatch[i].originalCanvas);
        setSubjectCanvas(nextBatch[i].originalCanvas);
        setFileName(nextBatch[i].name);
        setBatchImages((prev) => prev.map((b, idx) => ({
          ...b,
          status: idx === i ? 'scanning' : idx < i ? 'done' : 'queued'
        })));
        setAiProgress(`Scanning ${i + 1} of ${total}…`);
        await yieldForScan();
        if (jobId !== aiJobRef.current) return;

        const cut = await removeBackgroundFromCanvas(nextBatch[i].originalCanvas, (key, pct) => {
          if (jobId !== aiJobRef.current) return;
          const isDownload = /fetch|download|load/i.test(String(key));
          reportAiProgress(
            isDownload
              ? `Downloading AI model ${pct}%`
              : `Scanning ${i + 1} of ${total} (${pct}%)`
          );
        });
        if (jobId !== aiJobRef.current) return;

        nextBatch[i] = {
          ...nextBatch[i],
          cutoutCanvas: cut,
          cutoutPreviewUrl: makeThumb(cut, 160, true),
          status: 'done'
        };
        setBatchImages([...nextBatch]);
        setSubjectCanvas(cut);

        if (i === 0) {
          cleanCutoutRef.current = cut.toDataURL();
          saveToHistory(cut);
          setHasRunAI(true);
        }
      }
      if (jobId !== aiJobRef.current) return;

      const first = nextBatch[0];
      setActiveBatchId(first.id);
      setOriginalCanvas(first.originalCanvas);
      setSubjectCanvas(first.cutoutCanvas);
      setFileName(first.name);
      fullOriginalRef.current = first.originalCanvas.toDataURL();
      cleanCutoutRef.current = first.cutoutCanvas.toDataURL();
      setHasRunAI(true);
      if (nextBatch.length > 1) {
        setShowApplyBanner(true);
        setApplyBannerDismissed(false);
      }
    } finally {
      if (jobId === aiJobRef.current) {
        setIsProcessingAI(false);
        setAiProgress('');
      }
    }
  };

  const selectBatchItem = (item) => {
    if (!item || item.status !== 'done' || item.id === activeBatchId) return;
    const currentStyle = styleRef.current?.() || captureStyle();

    setBatchImages((prev) => prev.map((b) => {
      if (b.id === activeBatchId) {
        const current = subjectCanvasRef.current;
        return {
          ...b,
          cutoutCanvas: current || b.cutoutCanvas,
          cutoutPreviewUrl: current ? makeThumb(current, 160, true) : b.cutoutPreviewUrl,
          style: cloneStyle(currentStyle)
        };
      }
      return b;
    }));

    setActiveBatchId(item.id);
    setOriginalCanvas(item.originalCanvas);
    setSubjectCanvas(item.cutoutCanvas || item.originalCanvas);
    setFileName(item.name);
    fullOriginalRef.current = item.originalCanvas.toDataURL();
    cleanCutoutRef.current = item.cutoutCanvas ? item.cutoutCanvas.toDataURL() : null;
    setHasRunAI(!!item.cutoutCanvas);
    const url = (item.cutoutCanvas || item.originalCanvas).toDataURL();
    setHistory([url]);
    setHistoryIndex(0);
    setCropActive(false);
    setCropRect(null);
    setCompareMode(false);
    restoreStyle(item.style);
  };

  const handleApplyStyleToSelected = (ids) => {
    const style = cloneStyle(captureStyle());
    const idSet = new Set(ids || []);
    setBatchImages((prev) => prev.map((b) => {
      if (b.id === activeBatchId) {
        return { ...b, style, styleApplied: true };
      }
      if (idSet.has(b.id)) {
        return { ...b, style: cloneStyle(style), styleApplied: true };
      }
      return b;
    }));
    setIsApplyModalOpen(false);
    setShowApplyBanner(false);
    setApplyBannerDismissed(true);
  };

  // Re-run Chroma Key removal whenever sliders or colors change
  useEffect(() => {
    if (removalMode === 'chroma' && originalCanvas) {
      const processed = removeColorKey(
        originalCanvas,
        chromaColor,
        chromaTolerance,
        chromaFuzziness,
        desaturateSpill
      );
      setSubjectCanvas(processed);
    }
  }, [removalMode, chromaColor, chromaTolerance, chromaFuzziness, desaturateSpill, originalCanvas]);

  // Magic Wand Region Purge Handler
  const handleMagicWandPurge = (x, y) => {
    if (!subjectCanvas) return;
    const processed = magicWandPurge(subjectCanvas, x, y, wandTolerance);
    setSubjectCanvas(processed);
    saveToHistory(processed);
  };

  // Brush Touch-up Stroke Handler
  const handleApplyBrushStroke = (x, y, radius, hardness, mode) => {
    if (!subjectCanvas || !originalCanvas) return;
    applyBrush(subjectCanvas, originalCanvas, x, y, radius, hardness, mode);
    // Force canvas re-render
    setSubjectCanvas(prev => {
      const clone = document.createElement('canvas');
      clone.width = prev.width;
      clone.height = prev.height;
      clone.getContext('2d').drawImage(prev, 0, 0);
      return clone;
    });
  };

  const handleBrushStrokeEnd = () => {
    saveToHistory(subjectCanvasRef.current);
  };

  const handleResetEdits = () => {
    setSubjectEffects(cloneStyle(DEFAULT_SUBJECT_EFFECTS));
    setSubjectPosition({ ...DEFAULT_SUBJECT_POSITION });
    setBgType('color');
    setBgColor('#F8FAFC');
    setGradientConfig({ ...DEFAULT_GRADIENT });
    setBgImage(null);
    setBgBlur(0);
    setBgOpacity(100);
    setCropActive(false);
    setCropRect(null);

    const url = cleanCutoutRef.current;
    const origUrl = fullOriginalRef.current;

    const restore = (dataUrl, setter) => {
      if (!dataUrl) return;
      const img = new Image();
      img.onload = () => {
        const canv = document.createElement('canvas');
        canv.width = img.width;
        canv.height = img.height;
        canv.getContext('2d').drawImage(img, 0, 0);
        setter(canv);
      };
      img.src = dataUrl;
    };

    restore(origUrl, setOriginalCanvas);
    if (url) {
      restore(url, (canv) => {
        setSubjectCanvas(canv);
        saveToHistory(canv);
      });
    }
  };

  const handleStartCrop = () => {
    if (!originalCanvas) return;
    const m = Math.round(Math.min(originalCanvas.width, originalCanvas.height) * 0.08);
    setCropRect({
      x: m,
      y: m,
      w: Math.max(20, originalCanvas.width - m * 2),
      h: Math.max(20, originalCanvas.height - m * 2)
    });
    setCropActive(true);
  };

  const handleApplyCrop = () => {
    if (!cropRect || !originalCanvas || !subjectCanvas) return;
    const croppedOrig = cropCanvas(originalCanvas, cropRect);
    const croppedSub = cropCanvas(subjectCanvas, cropRect);
    setOriginalCanvas(croppedOrig);
    setSubjectCanvas(croppedSub);
    saveToHistory(croppedSub);
    setCropActive(false);
    setCropRect(null);
    setSubjectPosition((pos) => ({ ...pos, x: 0, y: 0, scale: 1 }));
  };

  const handleCancelCrop = () => {
    setCropActive(false);
    setCropRect(null);
  };

  // History Undo & Redo Handlers
  const handleUndo = () => {
    if (historyIndex > 0) {
      const prevUrl = history[historyIndex - 1];
      const img = new Image();
      img.onload = () => {
        const canv = document.createElement('canvas');
        canv.width = originalCanvas.width;
        canv.height = originalCanvas.height;
        canv.getContext('2d').drawImage(img, 0, 0);
        setSubjectCanvas(canv);
        setHistoryIndex(historyIndex - 1);
      };
      img.src = prevUrl;
    }
  };

  const handleRedo = () => {
    if (historyIndex < history.length - 1) {
      const nextUrl = history[historyIndex + 1];
      const img = new Image();
      img.onload = () => {
        const canv = document.createElement('canvas');
        canv.width = originalCanvas.width;
        canv.height = originalCanvas.height;
        canv.getContext('2d').drawImage(img, 0, 0);
        setSubjectCanvas(canv);
        setHistoryIndex(historyIndex + 1);
      };
      img.src = nextUrl;
    }
  };

  return (
    <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      
      {/* Top Header Navigation */}
      <Header
        canUndo={historyIndex > 0}
        canRedo={historyIndex < history.length - 1}
        onUndo={handleUndo}
        onRedo={handleRedo}
        zoom={zoom}
        onZoomIn={() => setZoom(z => Math.min(z + 0.25, 4))}
        onZoomOut={() => setZoom(z => Math.max(z - 0.25, 0.25))}
        onResetZoom={() => setZoom(1)}
        compareMode={compareMode}
        setCompareMode={setCompareMode}
        onOpenExport={() => setIsExportOpen(true)}
        hasImage={!!originalCanvas}
        theme={theme}
        setTheme={setTheme}
      />

      {/* Main Content Workspace */}
      <main style={{ flex: 1, padding: '0 20px 20px 20px', display: 'flex', flexDirection: 'column' }}>
        
        {!originalCanvas ? (
          /* Empty State: File Uploader */
          <ImageUploader
            onImagesLoaded={handleImagesLoaded}
          />
        ) : (
          /* Active Editor Workspace: Split Sidebar & Viewport */
          <div style={{ display: 'grid', gridTemplateColumns: '280px 1fr', gap: '20px', flex: 1, height: 'calc(100vh - 100px)' }}>
            
            {/* Left Control Sidebar */}
            <div className="glass-panel" style={{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '16px', overflowY: 'auto' }}>

              <RemovalControls
                bgColor={bgColor}
                setBgColor={setBgColor}
                bgType={bgType}
                setBgType={setBgType}
                subjectEffects={subjectEffects}
                setSubjectEffects={setSubjectEffects}
                subjectPosition={subjectPosition}
                setSubjectPosition={setSubjectPosition}
                setRemovalMode={setRemovalMode}
                brushMode={brushMode}
                setBrushMode={setBrushMode}
                brushSize={brushSize}
                setBrushSize={setBrushSize}
                onResetEdits={handleResetEdits}
                cropActive={cropActive}
                onStartCrop={handleStartCrop}
                onApplyCrop={handleApplyCrop}
                onCancelCrop={handleCancelCrop}
              />

              <button
                onClick={() => {
                  aiJobRef.current += 1;
                  setIsProcessingAI(false);
                  setAiProgress('');
                  setHasRunAI(false);
                  setOriginalCanvas(null);
                  setSubjectCanvas(null);
                  setBatchImages([]);
                  setActiveBatchId(null);
                  setShowApplyBanner(false);
                  setApplyBannerDismissed(false);
                  setIsApplyModalOpen(false);
                }}
                className="btn btn-outline btn-sm"
                style={{ marginTop: 'auto', gap: '6px', color: 'var(--text-muted)', fontWeight: '400' }}
              >
                <Upload size={14} />
                <span>Upload different image</span>
              </button>

            </div>

            {/* Central Interactive Viewport */}
            <div className="workspace-stage">
            <CanvasViewport
              originalCanvas={originalCanvas}
              subjectCanvas={subjectCanvas}
              bgType={bgType}
              bgColor={bgColor}
              gradientConfig={gradientConfig}
              bgImage={bgImage}
              bgBlur={bgBlur}
              bgOpacity={bgOpacity}
              subjectPosition={subjectPosition}
              setSubjectPosition={setSubjectPosition}
              subjectEffects={subjectEffects}
              removalMode={removalMode}
              wandTolerance={wandTolerance}
              onMagicWandPurge={handleMagicWandPurge}
              brushMode={brushMode}
              brushSize={brushSize}
              brushHardness={brushHardness}
              onApplyBrushStroke={handleApplyBrushStroke}
              onBrushStrokeEnd={handleBrushStrokeEnd}
              zoom={zoom}
              compareMode={compareMode}
              isProcessingAI={isProcessingAI}
              aiProgress={aiProgress}
              cropActive={cropActive}
              cropRect={cropRect}
              setCropRect={setCropRect}
            />
            {batchImages.length > 1 && (
              <BatchStrip
                items={batchImages}
                activeId={activeBatchId}
                onSelect={selectBatchItem}
                isProcessing={isProcessingAI}
                showApplyBanner={showApplyBanner && !applyBannerDismissed}
                onOpenApply={() => setIsApplyModalOpen(true)}
                onDismissApply={() => {
                  setApplyBannerDismissed(true);
                  setShowApplyBanner(false);
                }}
              />
            )}
            </div>

          </div>
        )}

      </main>

      <ApplyStyleModal
        isOpen={isApplyModalOpen}
        onClose={() => setIsApplyModalOpen(false)}
        items={batchImages}
        sourceId={activeBatchId}
        onApply={handleApplyStyleToSelected}
      />

      {/* Export & Download Dialog Modal */}
      <ExportModal
        isOpen={isExportOpen}
        onClose={() => setIsExportOpen(false)}
        originalCanvas={originalCanvas}
        subjectCanvas={subjectCanvas}
        bgType={bgType}
        bgColor={bgColor}
        gradientConfig={gradientConfig}
        bgImage={bgImage}
        bgBlur={bgBlur}
        bgOpacity={bgOpacity}
        subjectPosition={subjectPosition}
        subjectEffects={subjectEffects}
        fileName={fileName}
      />

    </div>
  );
}
