import { useCallback, useEffect, useRef, useState } from 'react';
import {
  createBackup,
  deleteBackup,
  triggerBackupDownload,
  getBootData,
} from '../api';

function IconCloudUpload({ className }) {
  return (
    <svg className={className} width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M12 13V3" />
      <path d="m8 7 4-4 4 4" />
      <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3" />
      <path d="M16 19h6" />
      <path d="M19 16v6" />
    </svg>
  );
}

function IconFolder({ className }) {
  return (
    <svg className={className} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.64 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z" />
    </svg>
  );
}

function IconHardDrive({ className }) {
  return (
    <svg className={className} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M22 12H2" />
      <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
      <path d="M6 16h.01" />
      <path d="M10 16h.01" />
    </svg>
  );
}

function IconArrowLeft({ className }) {
  return (
    <svg className={className} width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="m12 19-7-7 7-7" />
      <path d="M19 12H5" />
    </svg>
  );
}

function IconDownload({ className }) {
  return (
    <svg className={className} width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
      <polyline points="7 10 12 15 17 10" />
      <line x1="12" x2="12" y1="15" y2="3" />
    </svg>
  );
}

function IconTrash({ className }) {
  return (
    <svg className={className} width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M3 6h18" />
      <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
      <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
    </svg>
  );
}

export default function BackupDeskPage() {
  const boot = getBootData();
  const [backups, setBackups] = useState(() => boot.backups || []);
  const [capabilities] = useState(() => boot.capabilities || {});
  const [links] = useState(() => boot.links || {});
  const [creating, setCreating] = useState(false);
  const [progressPercent, setProgressPercent] = useState(0);
  const [displayPercent, setDisplayPercent] = useState(0);
  const [progressLabel, setProgressLabel] = useState('');
  const [deletingId, setDeletingId] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const displayRef = useRef(0);

  // Smooth counting animation toward the latest reported percent.
  useEffect(() => {
    if (!creating) {
      displayRef.current = 0;
      setDisplayPercent(0);
      return undefined;
    }

    const timer = window.setInterval(() => {
      const current = displayRef.current;
      const target = progressPercent;
      if (current >= target) return;
      const step = Math.max(1, Math.ceil((target - current) / 6));
      const next = Math.min(target, current + step);
      displayRef.current = next;
      setDisplayPercent(next);
    }, 40);

    return () => window.clearInterval(timer);
  }, [creating, progressPercent]);

  const handleCreate = useCallback(async () => {
    if (creating) return;
    if (!capabilities.zip) {
      setError('PHP Zip extension is not enabled on this server.');
      return;
    }
    setCreating(true);
    setError('');
    setSuccess('');
    setProgressPercent(1);
    setDisplayPercent(1);
    displayRef.current = 1;
    setProgressLabel('Starting backup...');
    try {
      const result = await createBackup(({ percent, label }) => {
        setProgressPercent((prev) => Math.max(prev, Number(percent) || 0));
        if (label) setProgressLabel(label);
      });
      setProgressPercent(100);
      setDisplayPercent(100);
      displayRef.current = 100;
      setProgressLabel('Backup ready');
      setBackups(result.backups || []);
      setSuccess(result.message || 'Backup created.');
    } catch (err) {
      setError(err.message || 'Could not create backup.');
    } finally {
      setCreating(false);
      setProgressLabel('');
    }
  }, [creating, capabilities.zip]);

  const handleDelete = useCallback(async (id) => {
    if (!id || deletingId) return;
    if (!window.confirm('Delete this backup file from the server?')) return;
    setDeletingId(id);
    setError('');
    setSuccess('');
    try {
      const result = await deleteBackup(id);
      setBackups(result.backups || []);
      setSuccess('Backup deleted.');
    } catch (err) {
      setError(err.message || 'Could not delete backup.');
    } finally {
      setDeletingId('');
    }
  }, [deletingId]);

  return (
    <div className="bk-page">
      <div className="bk-hero">
        <div className="bk-actions">
          <button
            type="button"
            className="bk-btn bk-btn-primary"
            onClick={handleCreate}
            disabled={creating || !capabilities.zip}
          >
            {creating ? (
              <span className="bk-spin" aria-hidden="true" />
            ) : (
              <IconCloudUpload />
            )}
            {creating ? `Creating... ${displayPercent}%` : 'Create backup'}
          </button>
        </div>
      </div>

      {creating ? (
        <div className="bk-progress-panel" role="status" aria-live="polite">
          <div className="bk-progress-top">
            <div className="bk-progress-percent">{displayPercent}%</div>
            <div className="bk-progress-label">{progressLabel || 'Working...'}</div>
          </div>
          <div className="bk-progress-track" aria-hidden="true">
            <div
              className="bk-progress-fill"
              style={{ width: `${Math.max(2, displayPercent)}%` }}
            />
          </div>
          <div className="bk-progress-meta">
            Packing database and files...
          </div>
        </div>
      ) : null}

      {error ? <div className="bk-alert bk-alert-error" role="alert">{error}</div> : null}
      {success ? <div className="bk-alert bk-alert-success" role="status">{success}</div> : null}

      <section className="bk-saved">
        <div className="bk-saved-head">
          <div className="bk-saved-title">
            <span className="bk-card-icon bk-card-icon--blue" aria-hidden="true">
              <IconHardDrive />
            </span>
            <div>
              <h2>Saved backups</h2>
            </div>
          </div>
        </div>

        {backups.length === 0 ? (
          <div className="bk-empty">
            <div className="bk-empty-icon" aria-hidden="true">
              <IconFolder />
            </div>
            <p className="bk-empty-title">No backups yet</p>
            <p className="bk-empty-text">
              Click{' '}
              <button type="button" className="bk-inline-link" onClick={handleCreate} disabled={creating || !capabilities.zip}>
                Create backup
              </button>{' '}
              to generate your first archive.
            </p>
          </div>
        ) : (
          <div className="bk-table-wrap">
            <table className="bk-table">
              <thead>
                <tr>
                  <th>Created</th>
                  <th>File</th>
                  <th>Size</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {backups.map((item) => (
                  <tr key={item.id}>
                    <td>{item.created_label}</td>
                    <td><code>{item.filename}</code></td>
                    <td>{item.size_label}</td>
                    <td>
                      <div className="bk-table-actions">
                        <button
                          type="button"
                          className="bk-btn bk-btn-secondary bk-btn-sm"
                          onClick={() => triggerBackupDownload(item.id)}
                        >
                          <IconDownload />
                          Download
                        </button>
                        <button
                          type="button"
                          className="bk-btn bk-btn-danger bk-btn-sm"
                          onClick={() => handleDelete(item.id)}
                          disabled={deletingId === item.id}
                        >
                          <IconTrash />
                          {deletingId === item.id ? 'Deleting...' : 'Delete'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {links.modules ? (
        <div className="bk-footer">
          <a className="bk-btn bk-btn-secondary" href={links.modules}>
            <IconArrowLeft />
            Back to modules
          </a>
        </div>
      ) : null}
    </div>
  );
}
