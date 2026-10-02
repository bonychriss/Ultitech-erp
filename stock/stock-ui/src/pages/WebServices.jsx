import React, { useRef, useState } from 'react';
import { HiOutlineArrowPath } from 'react-icons/hi2';
import './products-desk.css';

function money(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0.00';
  return n.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function WebServices({ data }) {
  const { pending = [], syncUrl = '', cancelUrl = '' } = data;
  const [syncing, setSyncing] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const abortRef = useRef(null);

  const withRun = (url, runId) => {
    if (!url || !runId) return url;
    return `${url}${url.includes('?') ? '&' : '?'}run=${encodeURIComponent(runId)}`;
  };

  const cancel = () => {
    const runId = abortRef.current?.runId || '';
    if (cancelUrl) {
      fetch(withRun(cancelUrl, runId), { method: 'POST', credentials: 'same-origin' }).catch(() => {});
    }
    abortRef.current?.abort();
    setSyncing(false);
    setError('');
    setMessage('Sync cancelled.');
  };

  const sync = async () => {
    if (!syncUrl || syncing) return;
    const controller = new AbortController();
    const runId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    controller.runId = runId;
    abortRef.current = controller;
    setSyncing(true);
    setError('');
    setMessage('Syncing products to ultimate.co.tz. This can take a few minutes.');
    try {
      const res = await fetch(withRun(syncUrl, runId), {
        method: 'POST',
        credentials: 'same-origin',
        signal: controller.signal,
      });
      const json = await res.json();
      if (json.cancelled) {
        setMessage('Sync cancelled.');
        setSyncing(false);
        return;
      }
      if (!res.ok || !json.ok) {
        throw new Error(json.error || 'Sync failed');
      }
      setMessage(json.summary || 'Sync finished.');
      window.setTimeout(() => window.location.reload(), 700);
    } catch (err) {
      if (err?.name === 'AbortError') {
        setMessage('Sync cancelled.');
        setSyncing(false);
        return;
      }
      setMessage('');
      setError(err?.message || 'Sync failed');
      setSyncing(false);
    }
  };

  return (
    <div className="prod-desk-page">
      <div className="prod-desk-page-header" style={{ gridTemplateColumns: '1fr auto' }}>
        <p style={{ margin: 0, color: '#64748b', fontSize: '0.925rem' }}>
          Send Ultimate products to ultimate.co.tz. New products that are not on the website yet are listed here.
        </p>
        <div className="prod-desk-page-header-actions" style={{ gridColumn: 'auto' }}>
          {syncing ? (
            <button type="button" className="prod-desk-btn prod-desk-btn-secondary" onClick={cancel}>
              Cancel
            </button>
          ) : null}
          <button type="button" className="prod-desk-btn prod-desk-btn-primary prod-desk-btn-pill" onClick={sync} disabled={syncing}>
            <HiOutlineArrowPath aria-hidden="true" />
            <span>{syncing ? 'Syncing...' : 'Sync to website'}</span>
          </button>
        </div>
      </div>

      {message ? <div className="alert alert-info mb-0">{message}</div> : null}
      {error ? <div className="alert alert-danger mb-0">{error}</div> : null}

      {pending.length === 0 ? (
        <div className="alert alert-success mb-0">
          No new products waiting. Everything active in UltiTech has been sent, or nothing has been added since the last sync.
        </div>
      ) : (
        <div className="prod-desk-table-wrap">
          <table className="prod-desk-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Category</th>
                <th style={{ textAlign: 'right' }}>Price</th>
                <th style={{ textAlign: 'right' }}>Stock</th>
              </tr>
            </thead>
            <tbody>
              {pending.map((row) => (
                <tr key={row.id}>
                  <td>{row.product_code}</td>
                  <td>{row.name}</td>
                  <td>{row.category_name}</td>
                  <td style={{ textAlign: 'right' }}>{money(row.unit_price)}</td>
                  <td style={{ textAlign: 'right' }}>{row.stock_qty}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
