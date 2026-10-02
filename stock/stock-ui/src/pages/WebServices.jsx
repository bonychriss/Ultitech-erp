import React, { useState } from 'react';
import { HiOutlineArrowPath, HiOutlineGlobeAlt } from 'react-icons/hi2';
import './products-desk.css';

function money(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0.00';
  return n.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function WebServices({ data }) {
  const { pending = [], syncUrl = '' } = data;
  const [syncing, setSyncing] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const sync = async () => {
    if (!syncUrl || syncing) return;
    setSyncing(true);
    setError('');
    setMessage('Syncing products to ultimate.co.tz. This can take a few minutes.');
    try {
      const res = await fetch(syncUrl, { method: 'POST', credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || !json.ok) {
        throw new Error(json.error || 'Sync failed');
      }
      setMessage(json.summary || 'Sync finished.');
      window.setTimeout(() => window.location.reload(), 700);
    } catch (err) {
      setMessage('');
      setError(err?.message || 'Sync failed');
      setSyncing(false);
    }
  };

  return (
    <div className="prod-desk-page">
      <div className="prod-desk-page-header" style={{ gridTemplateColumns: '1fr auto' }}>
        <div>
          <h1 className="prod-desk-title" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', margin: 0 }}>
            <HiOutlineGlobeAlt aria-hidden="true" />
            webServices
          </h1>
          <p style={{ margin: '0.35rem 0 0', color: '#64748b', fontSize: '0.925rem' }}>
            Send Ultimate products to ultimate.co.tz. New products that are not on the website yet are listed here.
          </p>
        </div>
        <div className="prod-desk-page-header-actions" style={{ gridColumn: 'auto' }}>
          <button type="button" className="prod-desk-btn prod-desk-btn-primary" onClick={sync} disabled={syncing}>
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
