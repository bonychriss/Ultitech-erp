import React, { useRef, useState, useEffect } from 'react';
import { Upload } from 'lucide-react';

function readImageFile(file) {
  return new Promise((resolve, reject) => {
    if (!file.type.startsWith('image/')) {
      reject(new Error('Please select a valid image file (PNG, JPG, WebP, etc.)'));
      return;
    }
    const reader = new FileReader();
    reader.onload = (event) => {
      const img = new Image();
      img.onload = () => resolve({ img, name: file.name || 'pasted-image.png' });
      img.onerror = reject;
      img.src = event.target.result;
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

export default function ImageUploader({ onImagesLoaded }) {
  const fileInputRef = useRef(null);
  const [isDragging, setIsDragging] = useState(false);

  const processFiles = async (fileList) => {
    const files = [...(fileList || [])].filter((f) => f.type.startsWith('image/'));
    if (!files.length) {
      alert('Please select a valid image file (PNG, JPG, WebP, etc.)');
      return;
    }
    try {
      const items = [];
      for (const file of files) {
        items.push(await readImageFile(file));
      }
      onImagesLoaded?.(items);
    } catch (err) {
      alert(err.message || 'Could not read those images.');
    }
  };

  const handleFileChange = (e) => {
    processFiles(e.target.files);
    e.target.value = '';
  };

  const handleDragOver = (e) => {
    e.preventDefault();
    setIsDragging(true);
  };

  const handleDragLeave = (e) => {
    e.preventDefault();
    setIsDragging(false);
  };

  const handleDrop = (e) => {
    e.preventDefault();
    setIsDragging(false);
    processFiles(e.dataTransfer.files);
  };

  useEffect(() => {
    const handlePaste = (e) => {
      const items = e.clipboardData?.items;
      if (!items) return;
      const files = [];
      for (let i = 0; i < items.length; i++) {
        if (items[i].type.indexOf('image') !== -1) {
          const blob = items[i].getAsFile();
          if (blob) files.push(blob);
        }
      }
      if (files.length) processFiles(files);
    };
    window.addEventListener('paste', handlePaste);
    return () => window.removeEventListener('paste', handlePaste);
  }, []);

  return (
    <div style={{ maxWidth: '960px', margin: '40px auto', padding: '0 20px' }}>
      <div style={{ textAlign: 'center', marginBottom: '32px' }}>
        <h2 style={{ fontFamily: 'var(--font-sans)', fontSize: '1.75rem', fontWeight: '300', lineHeight: 1.2, marginBottom: '8px', color: 'var(--text-main)', letterSpacing: '0.01em' }}>
          Remove & Replace Image Backgrounds Instantly
        </h2>
        <p style={{ fontSize: '0.95rem', color: 'var(--text-muted)', maxWidth: '640px', margin: '0 auto', fontWeight: '300', lineHeight: 1.4 }}>
          Upload one or more photos. Backgrounds are removed automatically, then you can edit and export.
        </p>
      </div>

      <div
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
        onClick={() => fileInputRef.current?.click()}
        className="glass-panel"
        style={{
          border: `2px dashed ${isDragging ? 'var(--accent-primary)' : 'var(--bg-card-border)'}`,
          background: isDragging ? 'rgba(37, 99, 235, 0.08)' : 'var(--bg-card)',
          padding: '48px 32px',
          textAlign: 'center',
          cursor: 'pointer',
          transition: 'all 0.25s ease-in-out',
          position: 'relative',
          overflow: 'hidden'
        }}
      >
        <input
          type="file"
          ref={fileInputRef}
          onChange={handleFileChange}
          accept="image/*"
          multiple
          style={{ display: 'none' }}
        />

        <div style={{
          width: '72px',
          height: '72px',
          borderRadius: '20px',
          background: 'linear-gradient(135deg, rgba(37,99,235,0.15) 0%, rgba(2,132,199,0.15) 100%)',
          border: '1px solid rgba(37,99,235,0.25)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          margin: '0 auto 20px auto',
          boxShadow: '0 8px 24px rgba(37,99,235,0.15)'
        }}>
          <Upload size={32} color="var(--accent-primary)" />
        </div>

        <h3 style={{ fontFamily: 'var(--font-sans)', fontSize: '1.05rem', fontWeight: '300', marginBottom: '6px', color: 'var(--text-main)', letterSpacing: '0.01em' }}>
          Drag & Drop your images here
        </h3>
        <p style={{ color: 'var(--text-muted)', fontSize: '0.82rem', marginBottom: '20px', fontWeight: '300', lineHeight: 1.4 }}>
          Upload several photos at once. Supports PNG, JPG, WebP, SVG (or press <kbd style={{ background: 'var(--bg-subtle)', border: '1px solid var(--bg-subtle-border)', padding: '2px 6px', borderRadius: '4px', color: 'var(--text-main)', fontWeight: '300' }}>Ctrl + V</kbd> to paste from clipboard)
        </p>

        <button type="button" className="btn btn-primary" style={{ padding: '12px 28px', fontSize: '1rem', fontWeight: '500' }}>
          Choose files
        </button>
      </div>
    </div>
  );
}
