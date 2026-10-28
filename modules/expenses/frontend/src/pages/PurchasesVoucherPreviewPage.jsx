import { useEffect, useState } from 'react';
import { ArrowLeft, ExternalLink, FileText, Loader2, Paperclip } from 'lucide-react';
import { deskPageUrl, fetchPurchasesVoucherPreview } from '../api/expensesDesk';

function formatAmount(value) {
  return (Number(value) || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const normalized = String(dateStr).includes('T') ? dateStr : `${dateStr}T12:00:00`;
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return String(dateStr).slice(0, 10);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function withPoEmbed(url) {
  if (!url) return url;
  try {
    const u = new URL(url, window.location.href);
    u.searchParams.set('embed', '1');
    return u.pathname + u.search + u.hash;
  } catch {
    return `${url}${String(url).includes('?') ? '&' : '?'}embed=1`;
  }
}

function resolvePvId() {
  if (typeof window !== 'undefined' && window.__PURCHASES_PV_ID__) {
    const boot = Number(window.__PURCHASES_PV_ID__);
    if (boot > 0) return boot;
  }
  try {
    const params = new URLSearchParams(window.location.search);
    const fromQuery = Number(params.get('pv_id') || params.get('id') || 0);
    if (fromQuery > 0) return fromQuery;
  } catch {
    // ignore
  }
  return 0;
}

export default function PurchasesVoucherPreviewPage() {
  const [pvId] = useState(() => resolvePvId());
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [payload, setPayload] = useState(null);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      if (pvId <= 0) {
        setError('Missing payment voucher id.');
        setLoading(false);
        return;
      }
      setLoading(true);
      setError('');
      try {
        const data = await fetchPurchasesVoucherPreview(pvId);
        if (!cancelled) setPayload(data);
      } catch (err) {
        if (!cancelled) setError(err?.message || 'Could not load voucher preview.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [pvId]);

  const listUrl = deskPageUrl('index.php');
  const voucher = payload?.voucher || null;
  const items = Array.isArray(payload?.items) ? payload.items : [];
  const poDocs = Array.isArray(payload?.purchaseOrderDocs) ? payload.purchaseOrderDocs : [];
  const attachments = Array.isArray(payload?.attachments) ? payload.attachments : [];
  const fullUrl = String(payload?.fullVoucherUrl || '').trim();
  const titleNo = voucher?.voucher_no || `PV #${pvId || ''}`;
  const payee = voucher?.payee_name || '-';
  const preparedBy = String(voucher?.prepared_by || '').trim() || '-';
  const docsQty = voucher?.supporting_documents || String(attachments.length || 0);
  const itemsTotal = items.reduce((sum, item) => sum + (Number(item.amount) || 0), 0);

  if (loading) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading voucher details...</span>
      </div>
    );
  }

  return (
    <div className="exp-desk-page exp-desk-purch-preview-page">
      <header className="exp-desk-purch-preview-toolbar">
        <a className="exp-desk-purch-preview-back" href={listUrl}>
          <ArrowLeft size={14} aria-hidden="true" />
          <span>Back to Purchases</span>
        </a>
        {fullUrl ? (
          <a className="exp-desk-btn exp-desk-btn-primary" href={fullUrl}>
            <ExternalLink size={16} aria-hidden="true" />
            Open full voucher
          </a>
        ) : null}
      </header>

      {error ? (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
      ) : (
        <div className="exp-desk-purch-preview-layout">
          <div className="exp-desk-purch-preview-main">
            <section className="exp-desk-purch-section exp-desk-purch-section--voucher" aria-label="Payment voucher table">
              <div className="exp-desk-purch-pv-paper">
                <div className="exp-desk-purch-pv-table-wrap">
                  <table className="exp-desk-purch-pv-table exp-desk-purch-pv-table--header">
                    <colgroup>
                      <col style={{ width: '18%' }} />
                      <col style={{ width: '32%' }} />
                      <col style={{ width: '18%' }} />
                      <col style={{ width: '32%' }} />
                    </colgroup>
                    <tbody>
                      <tr>
                        <th scope="row">Voucher NO. :</th>
                        <td>{titleNo}</td>
                        <th scope="row">Date:</th>
                        <td>{formatDate(voucher?.date_created)}</td>
                      </tr>
                      <tr>
                        <th scope="row">Payee Name:</th>
                        <td>{payee}</td>
                        <th scope="row">Prepared By:</th>
                        <td>{preparedBy.toUpperCase()}</td>
                      </tr>
                      <tr>
                        <th scope="row">Description:</th>
                        <td>{voucher?.description || '-'}</td>
                        <th scope="row">Supporting Documents (Qty.)</th>
                        <td>{docsQty}</td>
                      </tr>
                      <tr>
                        <th scope="row">Currency:</th>
                        <td>{voucher?.currency || 'TZS'}</td>
                        <th scope="row">Amount:</th>
                        <td className="exp-desk-purch-pv-amt">{formatAmount(voucher?.total_amount)}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div className="exp-desk-purch-pv-table-wrap">
                  <table className="exp-desk-purch-pv-table exp-desk-purch-pv-table--items">
                    <colgroup>
                      <col style={{ width: '18%' }} />
                      <col style={{ width: '18%' }} />
                      <col style={{ width: '20%' }} />
                      <col style={{ width: '14%' }} />
                      <col style={{ width: '30%' }} />
                    </colgroup>
                    <thead>
                      <tr>
                        <th>Payment Type</th>
                        <th>Budget Type</th>
                        <th>Name</th>
                        <th className="exp-desk-purch-pv-amt">Amount</th>
                        <th>Description</th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.length === 0 ? (
                        <tr>
                          <td colSpan={5} className="exp-desk-purch-pv-empty">
                            No line items on this voucher.
                          </td>
                        </tr>
                      ) : (
                        items.map((item, index) => (
                          <tr key={`pv-item-${index}`}>
                            <td>{item.payment_type || '-'}</td>
                            <td>{item.budget_type || '-'}</td>
                            <td>{item.name || '-'}</td>
                            <td className="exp-desk-purch-pv-amt">{formatAmount(item.amount)}</td>
                            <td>{item.description || '-'}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                    {items.length > 0 ? (
                      <tfoot>
                        <tr>
                          <td colSpan={3}>Total</td>
                          <td className="exp-desk-purch-pv-amt">
                            {formatAmount(itemsTotal || voucher?.total_amount)}
                          </td>
                          <td />
                        </tr>
                      </tfoot>
                    ) : null}
                  </table>
                </div>
              </div>
            </section>
          </div>

          <aside className="exp-desk-purch-preview-aside">
            <section className="exp-desk-purch-section" aria-label="Linked purchase orders">
              <div className="exp-desk-purch-section-head">
                <h2 className="exp-desk-purch-section-title">
                  <FileText size={16} aria-hidden="true" />
                  Purchase orders
                </h2>
                <span className="exp-desk-purch-section-count">{poDocs.length}</span>
              </div>
              {poDocs.length === 0 ? (
                <p className="exp-desk-purch-empty">No linked purchase order.</p>
              ) : (
                <div className="exp-desk-purch-po-list">
                  {poDocs.map((po) => {
                    const viewUrl = String(po.viewLink || '');
                    const embedUrl = withPoEmbed(viewUrl);
                    return (
                      <article key={`po-${po.id}`} className="exp-desk-purch-po">
                        <div className="exp-desk-purch-po-head">
                          <div>
                            <div className="exp-desk-purch-po-title">{po.poNumber || `PO-${po.id}`}</div>
                            <div className="exp-desk-purch-po-meta">
                              {[
                                po.supplierName ? `Supplier: ${po.supplierName}` : '',
                                po.status ? `Status: ${po.status}` : '',
                              ]
                                .filter(Boolean)
                                .join(' · ')}
                            </div>
                          </div>
                          {viewUrl ? (
                            <a
                              className="exp-desk-btn exp-desk-btn-secondary exp-desk-purch-po-open"
                              href={viewUrl}
                              target="_blank"
                              rel="noopener noreferrer"
                            >
                              Open PO
                            </a>
                          ) : null}
                        </div>
                        {embedUrl ? (
                          <div className="exp-desk-purch-po-frame-wrap">
                            <iframe
                              className="exp-desk-purch-po-frame"
                              title={`Purchase order ${po.poNumber || po.id}`}
                              src={embedUrl}
                              loading="lazy"
                            />
                          </div>
                        ) : null}
                      </article>
                    );
                  })}
                </div>
              )}
            </section>

            <section className="exp-desk-purch-section" aria-label="Attachments">
              <div className="exp-desk-purch-section-head">
                <h2 className="exp-desk-purch-section-title">
                  <Paperclip size={16} aria-hidden="true" />
                  Attachments
                </h2>
                <span className="exp-desk-purch-section-count">{attachments.length}</span>
              </div>
              {attachments.length === 0 ? (
                <p className="exp-desk-purch-empty">No uploaded attachments.</p>
              ) : (
                <ul className="exp-desk-purch-att-list">
                  {attachments.map((att) => (
                    <li key={`att-${att.id || att.name}`}>
                      <div className="exp-desk-purch-att-main">
                        <span className="exp-desk-purch-att-name" title={att.name}>
                          {att.name}
                        </span>
                        <span className="exp-desk-purch-att-meta">
                          {[att.typeLabel, att.fileSizeLabel, att.missing ? 'Unavailable' : '']
                            .filter(Boolean)
                            .join(' · ')}
                        </span>
                      </div>
                      {!att.missing && att.proxyLink ? (
                        <a
                          className="exp-desk-purch-att-link"
                          href={att.proxyLink}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          View
                        </a>
                      ) : null}
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </aside>
        </div>
      )}
    </div>
  );
}
