import React, { useRef, useState } from 'react';
import { HiOutlineArrowPath } from 'react-icons/hi2';
import './products-desk.css';
import WebsiteDashboard from './WebsiteDashboard';

function money(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0.00';
  return n.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function syncedDate(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function changeText(change) {
  if (change.field === 'description' || change.field === 'image') return `${change.label} changed`;
  const show = (v) => (change.field === 'unit_price' ? money(v) : v || '(empty)');
  return `${change.label}: ${show(change.from)} → ${show(change.to)}`;
}

const TABS = [
  { key: 'pending', label: 'New' },
  { key: 'edited', label: 'Edited' },
  { key: 'deleted', label: 'Deleted' },
];

export default function WebServices({ data }) {
  const {
    view = 'sync',
    pending = [],
    edited = [],
    deleted = [],
    syncUrl = '',
    cancelUrl = '',
    dashboard = null,
    dashboardUrl = '',
  } = data;
  const lists = { pending, edited, deleted };
  const [tab, setTab] = useState(() => TABS.find((t) => lists[t.key].length > 0)?.key || 'pending');
  const [syncing, setSyncing] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const abortRef = useRef(null);
  const total = pending.length + edited.length + deleted.length;

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

  if (view === 'dashboard') {
    return (
      <div className="prod-desk-page">
        {dashboard ? (
          <WebsiteDashboard dashboard={dashboard} websiteUrl={dashboardUrl} />
        ) : (
          <div className="alert alert-warning mb-0">The website dashboard could not be loaded. Refresh the page to try again.</div>
        )}
      </div>
    );
  }

  const rows = lists[tab];
  const empty = {
    pending: 'No new products waiting. Every active product is already on the website.',
    edited: 'No edited products. Names, prices, categories, and photos on the website match UltiTech.',
    deleted: 'No deleted products. Everything on the website still exists in UltiTech.',
  };

  return (
    <div className="prod-desk-page">
      <div className="prod-desk-page-header" style={{ gridTemplateColumns: '1fr auto' }}>
        <p style={{ margin: 0, color: '#64748b', fontSize: '0.925rem' }}>
          Products that changed in UltiTech since the last sync to ultimate.co.tz. Syncing adds new products, updates edited
          ones, and hides deleted ones on the website.
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

      {total === 0 ? (
        <div className="alert alert-success mb-0">Everything is in sync. No new, edited, or deleted products since the last sync.</div>
      ) : (
        <>
          <div className="web-sync-tabs" role="tablist" aria-label="Product changes">
            {TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                role="tab"
                aria-selected={tab === t.key}
                className={`web-sync-tab web-sync-tab--${t.key}${tab === t.key ? ' is-active' : ''}`}
                onClick={() => setTab(t.key)}
              >
                <span>{t.label}</span>
                <span className="web-sync-count">{lists[t.key].length}</span>
              </button>
            ))}
          </div>

          {rows.length === 0 ? (
            <div className="alert alert-success mb-0">{empty[tab]}</div>
          ) : (
            <div className="prod-desk-table-wrap">
              <table className="prod-desk-table">
                <thead>
                  {tab === 'pending' ? (
                    <tr>
                      <th>Code</th>
                      <th>Name</th>
                      <th>Category</th>
                      <th style={{ textAlign: 'right' }}>Price</th>
                      <th style={{ textAlign: 'right' }}>Stock</th>
                    </tr>
                  ) : tab === 'edited' ? (
                    <tr>
                      <th>Code</th>
                      <th>Name</th>
                      <th>What changed</th>
                      <th>Last synced</th>
                    </tr>
                  ) : (
                    <tr>
                      <th>Code</th>
                      <th>Name</th>
                      <th>Status in UltiTech</th>
                      <th>Last synced</th>
                    </tr>
                  )}
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id}>
                      <td>{row.product_code}</td>
                      <td>{row.name || `Product #${row.id}`}</td>
                      {tab === 'pending' ? (
                        <>
                          <td>{row.category_name}</td>
                          <td style={{ textAlign: 'right' }}>{money(row.unit_price)}</td>
                          <td style={{ textAlign: 'right' }}>{row.stock_qty}</td>
                        </>
                      ) : tab === 'edited' ? (
                        <>
                          <td>
                            {row.changes?.length ? (
                              <ul className="web-sync-changes">
                                {row.changes.map((c) => (
                                  <li key={c.field}>{changeText(c)}</li>
                                ))}
                              </ul>
                            ) : (
                              <span className="web-sync-muted">Edited after the last sync</span>
                            )}
                          </td>
                          <td className="web-sync-muted">{syncedDate(row.synced_at)}</td>
                        </>
                      ) : (
                        <>
                          <td>
                            <span className={`web-sync-status web-sync-status--${row.status}`}>
                              {row.status === 'inactive' ? 'Deactivated' : 'Deleted'}
                            </span>
                          </td>
                          <td className="web-sync-muted">{syncedDate(row.synced_at)}</td>
                        </>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}
