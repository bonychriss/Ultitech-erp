import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlineArrowPath,
  HiOutlineCheckCircle,
  HiOutlineCloudArrowUp,
  HiOutlineDocument,
  HiOutlineExclamationTriangle,
  HiOutlineInformationCircle,
  HiOutlinePhoto,
} from 'react-icons/hi2';
import './products-desk.css';
import './uploads-desk.css';
import './uploads-upload.css';

function formatBytes(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(2)} MB`;
}

function isImageFile(file) {
  return String(file?.type || '').startsWith('image/');
}

function uploadOneFile(uploadUrl, folder, file, onProgress) {
  return new Promise((resolve) => {
    const formData = new FormData();
    formData.append('folder', folder);
    formData.append('files[]', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', uploadUrl, true);

    xhr.upload.onprogress = (e) => {
      if (!e.lengthComputable) return;
      onProgress(Math.round((e.loaded / e.total) * 100));
    };

    xhr.onload = () => {
      let response = {};
      try {
        response = JSON.parse(xhr.responseText || '{}');
      } catch {
        response = { success: false, errors: ['Invalid server response.'] };
      }

      if (xhr.status !== 200) {
        resolve({ ok: false, error: 'Upload failed. Please try again.' });
        return;
      }

      const uploaded = Array.isArray(response.uploaded) ? response.uploaded : [];
      const errors = Array.isArray(response.errors) ? response.errors : [];
      if (response.success && uploaded.length > 0 && errors.length === 0) {
        resolve({ ok: true, name: uploaded[0] });
        return;
      }
      if (response.success && uploaded.length > 0) {
        resolve({ ok: true, name: uploaded[0], warning: errors.join(' ') });
        return;
      }
      resolve({
        ok: false,
        error: errors[0] || response.message || 'Upload failed.',
      });
    };

    xhr.onerror = () => {
      resolve({ ok: false, error: 'Network error while uploading.' });
    };

    xhr.send(formData);
  });
}

export default function UploadsUpload({ data }) {
  const {
    folder = 'documents',
    folderLabel = 'Documents',
    backUrl = 'index.php?folder=documents',
    uploadUrl = 'ajax_upload.php',
    accept = '',
    tip = '',
  } = data;

  const inputRef = useRef(null);
  const busyRef = useRef(false);
  const previewUrlsRef = useRef([]);
  const [dragging, setDragging] = useState(false);
  const [queue, setQueue] = useState([]);
  const [uploading, setUploading] = useState(false);
  const [batchProgress, setBatchProgress] = useState({ current: 0, total: 0 });
  const [error, setError] = useState('');

  const patchItem = useCallback((id, patch) => {
    setQueue((prev) => prev.map((q) => (q.id === id ? { ...q, ...patch } : q)));
  }, []);

  const startUpload = useCallback(
    async (fileList) => {
      const files = Array.from(fileList || []).filter((f) => f && f.size > 0);
      if (!files.length || busyRef.current) return;

      busyRef.current = true;
      setError('');
      const batchId = Date.now();
      const items = files.map((f, i) => {
        const previewUrl = isImageFile(f) ? URL.createObjectURL(f) : '';
        if (previewUrl) previewUrlsRef.current.push(previewUrl);
        return {
          id: `${batchId}-${i}-${f.name}`,
          batchId,
          file: f,
          name: f.name,
          size: f.size,
          isImage: isImageFile(f),
          previewUrl,
          status: 'pending',
          progress: 0,
          error: '',
          storedName: '',
        };
      });
      setQueue((prev) => [...prev.filter((q) => q.status === 'done' || q.status === 'error'), ...items]);
      setBatchProgress({ current: 0, total: items.length });
      setUploading(true);

      let okCount = 0;
      const failMessages = [];

      for (let i = 0; i < items.length; i += 1) {
        const item = items[i];
        setBatchProgress({ current: i + 1, total: items.length });
        patchItem(item.id, { status: 'uploading', progress: 0, error: '' });
        const result = await uploadOneFile(uploadUrl, folder, item.file, (pct) => {
          patchItem(item.id, { progress: pct });
        });

        if (result.ok) {
          okCount += 1;
          patchItem(item.id, {
            status: 'done',
            progress: 100,
            error: '',
            storedName: result.name || item.name,
          });
        } else {
          failMessages.push(`${item.name}: ${result.error}`);
          patchItem(item.id, {
            status: 'error',
            progress: 0,
            error: result.error || 'Failed',
          });
        }
      }

      setUploading(false);
      setBatchProgress({ current: 0, total: 0 });
      busyRef.current = false;

      if (okCount === items.length) {
        return;
      }

      if (okCount > 0) {
        setError(failMessages.join('\n'));
        if (window.Swal) {
          window.Swal.fire({
            icon: 'warning',
            title: 'Partial upload',
            html: `<p>${okCount} uploaded, ${failMessages.length} failed.</p><pre style="text-align:left;max-height:10rem;overflow:auto;font-size:11px">${failMessages
              .map((x) => String(x).replace(/</g, '&lt;'))
              .join('<br>')}</pre>`,
          });
        }
        return;
      }

      const msg = failMessages.join('\n') || 'Upload failed.';
      setError(msg);
      if (window.Swal) {
        window.Swal.fire({ icon: 'error', title: 'Upload failed', text: msg });
      }
    },
    [folder, patchItem, uploadUrl]
  );

  useEffect(() => {
    return () => {
      previewUrlsRef.current.forEach((url) => URL.revokeObjectURL(url));
      previewUrlsRef.current = [];
    };
  }, []);

  useEffect(() => {
    const onPaste = (e) => {
      const items = Array.from(e.clipboardData?.files || []);
      if (items.length) {
        e.preventDefault();
        startUpload(items);
      }
    };
    window.addEventListener('paste', onPaste);
    return () => window.removeEventListener('paste', onPaste);
  }, [startUpload]);

  const onDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setDragging(false);
    startUpload(e.dataTransfer.files);
  };

  const doneCount = queue.filter((q) => q.status === 'done').length;
  const activeName = queue.find((q) => q.status === 'uploading')?.name;
  const queueTitle = uploading
    ? `Uploading (${batchProgress.current}/${batchProgress.total})`
    : doneCount > 0
      ? `Uploaded (${doneCount})`
      : 'Queue';

  return (
    <div className="uploads-up">
      <header className="uploads-up-head">
        <a className="uploads-up-back" href={backUrl}>
          <HiOutlineArrowLeft size={14} aria-hidden="true" />
          <span>Back to {folderLabel}</span>
        </a>
      </header>

      {tip ? (
        <div className="uploads-up-tip">
          <HiOutlineInformationCircle size={18} aria-hidden="true" />
          <p>{tip}</p>
        </div>
      ) : null}

      {error ? <div className="uploads-dup-error">{error}</div> : null}

      <section className="uploads-up-card">
        <div
          className={`uploads-up-drop${dragging ? ' is-dragging' : ''}${uploading ? ' is-busy' : ''}`}
          onClick={() => !uploading && inputRef.current?.click()}
          onDragEnter={(e) => {
            e.preventDefault();
            setDragging(true);
          }}
          onDragOver={(e) => {
            e.preventDefault();
            setDragging(true);
          }}
          onDragLeave={(e) => {
            e.preventDefault();
            setDragging(false);
          }}
          onDrop={onDrop}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault();
              inputRef.current?.click();
            }
          }}
          aria-label="Upload files"
        >
          <div className="uploads-up-drop-icon" aria-hidden="true">
            <HiOutlineCloudArrowUp size={28} />
          </div>
          <p className="uploads-up-drop-title">
            {uploading
              ? `Uploading ${batchProgress.current} of ${batchProgress.total}${activeName ? ` — ${activeName}` : ''}`
              : 'Drop files here, paste, or browse'}
          </p>
          <p className="uploads-up-drop-sub">
            Destination: <strong>{folderLabel}</strong>
          </p>
          <input
            ref={inputRef}
            type="file"
            className="sr-only"
            multiple
            accept={accept || undefined}
            disabled={uploading}
            onChange={(e) => {
              startUpload(e.target.files);
              e.target.value = '';
            }}
          />
        </div>

        {queue.length > 0 ? (
          <div className="uploads-up-queue">
            <h3>{queueTitle}</h3>
            <ul>
              {queue.map((item) => (
                <li key={item.id} className={`is-${item.status}`}>
                  <span className="uploads-up-queue-icon" aria-hidden="true">
                    {item.previewUrl ? (
                      <img src={item.previewUrl} alt="" className="uploads-up-queue-thumb" />
                    ) : item.isImage ? (
                      <HiOutlinePhoto size={16} />
                    ) : (
                      <HiOutlineDocument size={16} />
                    )}
                  </span>
                  <div className="uploads-up-queue-meta">
                    <div className="uploads-up-queue-name" title={item.storedName || item.name}>
                      {item.storedName || item.name}
                    </div>
                    <div className="uploads-up-queue-size">
                      {formatBytes(item.size)}
                      {item.status === 'uploading' ? ` — ${item.progress}%` : null}
                      {item.status === 'done' ? ' — Uploaded' : null}
                      {item.status === 'error' && item.error ? ` — ${item.error}` : null}
                    </div>
                    {item.status === 'uploading' ? (
                      <div className="uploads-up-item-progress" aria-hidden="true">
                        <span style={{ width: `${item.progress}%` }} />
                      </div>
                    ) : null}
                  </div>
                  <span className={`uploads-up-queue-status is-${item.status}`}>
                    {item.status === 'done' ? (
                      <HiOutlineCheckCircle size={18} aria-label="Done" />
                    ) : item.status === 'error' ? (
                      <HiOutlineExclamationTriangle size={18} aria-label="Error" />
                    ) : item.status === 'uploading' ? (
                      <HiOutlineArrowPath size={18} className="uploads-spin" aria-label="Uploading" />
                    ) : (
                      <span className="uploads-up-queue-waiting" aria-label="Waiting" />
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </section>
    </div>
  );
}
