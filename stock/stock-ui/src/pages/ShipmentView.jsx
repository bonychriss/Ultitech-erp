import React, { useEffect, useMemo, useState } from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlineCalculator,
  HiOutlineCheckCircle,
  HiOutlineClock,
  HiOutlineDocumentText,
  HiOutlineLink,
  HiOutlinePencilSquare,
  HiOutlinePlus,
  HiOutlineTruck,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './products-desk.css';
import './shipments-desk.css';

const STATUS_META = {
  pending: { label: 'Pending', className: 'ship-desk-status--pending' },
  confirmed: { label: 'Confirmed', className: 'ship-desk-status--pending' },
  shipped: { label: 'Shipped', className: 'ship-desk-status--shipped' },
  in_transit: { label: 'In transit', className: 'ship-desk-status--in_transit' },
  arrived_at_port: { label: 'Port arrival', className: 'ship-desk-status--arrived_at_port' },
  in_customs: { label: 'In customs', className: 'ship-desk-status--in_customs' },
  ready_for_pickup: { label: 'Ready for pickup', className: 'ship-desk-status--shipped' },
  out_for_delivery: { label: 'Out for delivery', className: 'ship-desk-status--in_transit' },
  delivered: { label: 'Delivered', className: 'ship-desk-status--delivered' },
  delayed: { label: 'Delayed', className: 'ship-desk-status--delayed' },
  cancelled: { label: 'Cancelled', className: 'ship-desk-status--cancelled' },
};

const TIMELINE_STEPS = [
  { id: 'step_pending', label: 'Pending', Icon: HiOutlineClock },
  { id: 'step_shipped', label: 'Shipped', Icon: HiOutlineTruck },
  { id: 'step_arrived', label: 'Delivered', Icon: HiOutlineCheckCircle },
];

const STATUS_TO_STEP = {
  pending: 'step_pending',
  confirmed: 'step_pending',
  cancelled: 'step_pending',
  delayed: 'step_shipped',
  shipped: 'step_shipped',
  in_transit: 'step_shipped',
  arrived_at_port: 'step_arrived',
  in_customs: 'step_arrived',
  ready_for_pickup: 'step_arrived',
  out_for_delivery: 'step_arrived',
  delivered: 'step_arrived',
};

const TABS = [
  { id: 'details', label: 'Basic info' },
  { id: 'packages', label: 'Packages' },
  { id: 'ecc', label: 'ECC documents' },
  { id: 'shipper', label: 'Shipper' },
  { id: 'landed-cost', label: 'Landed cost' },
];

const COST_FIELDS = [
  { key: 'shipping_cost', label: 'Freight / shipping', group: 'Shipping & logistics' },
  { key: 'insurance_cost', label: 'Insurance', group: 'Shipping & logistics' },
  { key: 'customs_duty', label: 'Customs duty', group: 'Customs & duties' },
  { key: 'customs_brokerage', label: 'Brokerage', group: 'Customs & duties' },
  { key: 'port_charges', label: 'Port charges', group: 'Customs & duties' },
  { key: 'local_transport', label: 'Local transport', group: 'Local & other' },
  { key: 'warehousing_fees', label: 'Warehousing', group: 'Local & other' },
  { key: 'other_costs', label: 'Other', group: 'Local & other' },
];

function formatMoney(value, prefix = '$') {
  const n = Number(value);
  if (Number.isNaN(n)) return `${prefix}0.00`;
  return `${prefix}${n.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(d) {
  if (!d || !String(d).trim()) return '';
  const s = String(d);
  return s.length >= 10 ? s.slice(0, 10) : s;
}

function StatusBadge({ status }) {
  const key = String(status || '').toLowerCase();
  const meta = STATUS_META[key] || {
    label: key ? key.replace(/_/g, ' ') : '�',
    className: 'ship-desk-status--default',
  };
  return <span className={`ship-desk-status ${meta.className}`}>{meta.label}</span>;
}

function MoneyInput({ name, value, onChange, label }) {
  return (
    <label className="ship-view-field">
      <span>{label}</span>
      <div className="ship-view-money">
        <span className="ship-view-money-prefix">$</span>
        <input
          type="number"
          step="0.01"
          name={name}
          value={value}
          onChange={(e) => onChange(name, e.target.value)}
          className="ship-view-input"
        />
      </div>
    </label>
  );
}

export default function ShipmentView({ data }) {
  const {
    indexUrl = 'index.php',
    editUrl = 'edit.php',
    poViewUrl = '../purchases/view_po.php',
    formAction = 'view.php',
    landedCostAction = 'save_landed_cost.php',
    initialTab = 'details',
    shipment = {},
    items = [],
    packages = [],
    eccDocs = [],
    valuePrefix = '$',
  } = data;

  const tabFromUrl = String(initialTab || 'details').toLowerCase();
  const startTab = TABS.some((t) => t.id === tabFromUrl) ? tabFromUrl : 'details';
  const [tab, setTab] = useState(startTab);
  const [showPkgModal, setShowPkgModal] = useState(false);
  const [costs, setCosts] = useState(() => {
    const init = {};
    COST_FIELDS.forEach((f) => {
      init[f.key] = shipment[f.key] != null ? String(shipment[f.key]) : '0';
    });
    return init;
  });

  const status = String(shipment.status || '').toLowerCase();
  const canEdit = status !== 'delivered';
  const currentStep = STATUS_TO_STEP[status] || 'step_pending';
  const currentStepIndex = TIMELINE_STEPS.findIndex((s) => s.id === currentStep);

  const productCost = Number(shipment.total_value) || 0;
  const additionalCost = useMemo(
    () => COST_FIELDS.reduce((sum, f) => sum + (Number(costs[f.key]) || 0), 0),
    [costs]
  );
  const landedTotal = productCost + additionalCost;

  const packageCount = packages.length || Number(shipment.packages_count) || 0;

  useEffect(() => {
    if (!showPkgModal) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') setShowPkgModal(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [showPkgModal]);

  const setCost = (key, value) => {
    setCosts((prev) => ({ ...prev, [key]: value }));
  };

  const costGroups = useMemo(() => {
    const map = new Map();
    COST_FIELDS.forEach((f) => {
      if (!map.has(f.group)) map.set(f.group, []);
      map.get(f.group).push(f);
    });
    return Array.from(map.entries());
  }, []);

  const chartParts = useMemo(() => {
    const parts = [
      { label: 'Product', value: productCost, color: '#64748b' },
      { label: 'Freight', value: Number(costs.shipping_cost) || 0, color: '#3b82f6' },
      { label: 'Customs', value: (Number(costs.customs_duty) || 0) + (Number(costs.customs_brokerage) || 0) + (Number(costs.port_charges) || 0), color: '#f59e0b' },
      { label: 'Other', value: (Number(costs.insurance_cost) || 0) + (Number(costs.local_transport) || 0) + (Number(costs.warehousing_fees) || 0) + (Number(costs.other_costs) || 0), color: '#7c3aed' },
    ].filter((p) => p.value > 0);
    const total = parts.reduce((s, p) => s + p.value, 0) || 1;
    return parts.map((p) => ({ ...p, pct: (p.value / total) * 100 }));
  }, [costs, productCost]);

  return (
    <div className="prod-desk-page ship-view">
      <header className="ship-view-top">
        <div className="ship-view-top-row">
          <div className="ship-view-top-lead">
            <a href={indexUrl} className="prod-desk-btn prod-desk-btn-secondary">
              <HiOutlineArrowLeft size={16} aria-hidden="true" />
              Shipments
            </a>
            {canEdit ? (
              <a href={`${editUrl}?id=${shipment.id || ''}`} className="prod-desk-btn ship-edit-btn-primary">
                <HiOutlinePencilSquare size={16} aria-hidden="true" />
                Edit
              </a>
            ) : null}
            <div className="ship-view-title-wrap">
              <h1 className="ship-view-title">Shipment</h1>
              <code className="ship-view-invoice">{shipment.invoice_number || '�'}</code>
            </div>
          </div>
        </div>
        <div className="ship-view-meta">
          <StatusBadge status={status} />
          <span className="ship-view-meta-item">
            <HiOutlineTruck size={14} aria-hidden="true" />
            {shipment.supplier_name || '�'}
          </span>
          {shipment.shipper_name ? (
            <span className="ship-view-meta-item">{shipment.shipper_name}</span>
          ) : null}
          {shipment.stocks_po_id ? (
            <a
              className="ship-view-meta-link"
              href={`${poViewUrl}?id=${shipment.stocks_po_id}`}
            >
              <HiOutlineLink size={14} aria-hidden="true" />
              PO {shipment.linked_po_number || `#${shipment.stocks_po_id}`}
            </a>
          ) : null}
        </div>
      </header>

      <section className="ship-view-card">
        <div className="ship-view-card-head">Progress</div>
        <div className="ship-view-timeline">
          <div className="ship-view-timeline-bar" aria-hidden="true" />
          {TIMELINE_STEPS.map((step, idx) => {
            const done = idx <= currentStepIndex;
            const Icon = step.Icon;
            let dateBits = [];
            if (step.id === 'step_shipped') {
              if (formatDate(shipment.shipment_date)) dateBits.push(`Dep: ${formatDate(shipment.shipment_date)}`);
              if (formatDate(shipment.etd)) dateBits.push(`ETD: ${formatDate(shipment.etd)}`);
            }
            if (step.id === 'step_arrived' && formatDate(shipment.eta)) {
              dateBits.push(`ETA: ${formatDate(shipment.eta)}`);
            }
            return (
              <div key={step.id} className={`ship-view-tl-step${done ? ' is-done' : ''}`}>
                <div className="ship-view-tl-icon">
                  <Icon size={18} aria-hidden="true" />
                </div>
                <div className="ship-view-tl-label">{step.label}</div>
                {dateBits.length ? (
                  <div className="ship-view-tl-date">
                    {dateBits.map((d) => (
                      <div key={d}>{d}</div>
                    ))}
                  </div>
                ) : null}
              </div>
            );
          })}
        </div>
      </section>

      <section className="ship-view-card ship-view-tabs-card">
        <nav className="ship-view-tabs" aria-label="Shipment sections">
          {TABS.map((t) => (
            <button
              key={t.id}
              type="button"
              className={`ship-view-tab${tab === t.id ? ' is-active' : ''}`}
              onClick={() => setTab(t.id)}
            >
              {t.id === 'landed-cost' ? <HiOutlineCalculator size={14} aria-hidden="true" /> : null}
              {t.label}
              {t.id === 'packages' ? <span className="ship-view-tab-badge">{packageCount}</span> : null}
            </button>
          ))}
        </nav>

        <div className="ship-view-tab-panel">
          {tab === 'details' ? (
            <div className="ship-view-details-grid">
              <div className="ship-view-panel">
                <div className="ship-view-panel-head">Shipment content</div>
                <div className="ship-view-table-wrap">
                  <table className="ship-view-table">
                    <thead>
                      <tr>
                        <th>Product</th>
                        <th className="is-num">Qty</th>
                        <th className="is-num">Unit price</th>
                        <th className="is-num">Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      {items.length === 0 ? (
                        <tr>
                          <td colSpan={4} className="is-empty">
                            No specific product items linked.
                          </td>
                        </tr>
                      ) : (
                        items.map((item) => {
                          const name = item.product_name || item.stocks_item_name || 'Line item';
                          const code = item.product_code || item.stocks_item_sku || '';
                          const qty = Number(item.quantity) || 0;
                          const unit = Number(item.unit_price) || 0;
                          return (
                            <tr key={item.id || `${name}-${code}`}>
                              <td>
                                <div className="ship-view-item-name">{name}</div>
                                {code ? <div className="ship-view-item-code">{code}</div> : null}
                              </td>
                              <td className="is-num">{qty}</td>
                              <td className="is-num">{formatMoney(unit, '$')}</td>
                              <td className="is-num is-strong">{formatMoney(qty * unit, '$')}</td>
                            </tr>
                          );
                        })
                      )}
                      <tr className="ship-view-desc-row">
                        <td colSpan={4}>
                          <div className="ship-view-desc-label">Description / cargo manifest</div>
                          <div className="ship-view-desc-body">
                            {shipment.description || '�'}
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <aside className="ship-view-panel">
                <div className="ship-view-panel-head">Summary</div>
                <ul className="ship-view-summary">
                  <li>
                    <span>Invoice #</span>
                    <strong>{shipment.invoice_number || '�'}</strong>
                  </li>
                  <li>
                    <span>Contact</span>
                    <strong>{shipment.contact_number || 'NA'}</strong>
                  </li>
                  <li>
                    <span>Tracking #</span>
                    <strong className="ship-view-clip" title={shipment.tracking_number || ''}>
                      {shipment.tracking_number || '�'}
                    </strong>
                  </li>
                  <li>
                    <span>Total value</span>
                    <strong className="is-money">{formatMoney(shipment.total_value, valuePrefix)}</strong>
                  </li>
                  <li>
                    <span>Packages</span>
                    <strong>{shipment.packages_count ?? 0}</strong>
                  </li>
                  <li>
                    <span>CBM</span>
                    <strong>{shipment.cbm != null ? `${shipment.cbm} m3` : '�'}</strong>
                  </li>
                </ul>
              </aside>
            </div>
          ) : null}

          {tab === 'packages' ? (
            <div className="ship-view-panel">
              <div className="ship-view-panel-toolbar">
                <h2>Package tracking</h2>
                <button type="button" className="prod-desk-btn ship-edit-btn-primary" onClick={() => setShowPkgModal(true)}>
                  <HiOutlinePlus size={16} aria-hidden="true" />
                  Add package
                </button>
              </div>
              <div className="ship-view-table-wrap">
                <table className="ship-view-table">
                  <thead>
                    <tr>
                      <th>Package #</th>
                      <th>Tracking</th>
                      <th>Dimensions</th>
                      <th>Weight</th>
                      <th>CBM</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {packages.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="is-empty">
                          No individual packages tracked.
                          <br />
                          Total packages count: <strong>{shipment.packages_count ?? 0}</strong>
                        </td>
                      </tr>
                    ) : (
                      packages.map((pkg) => (
                        <tr key={pkg.id}>
                          <td>{pkg.package_number || '�'}</td>
                          <td>{pkg.tracking_number || '�'}</td>
                          <td>{pkg.dimensions || '�'}</td>
                          <td>{pkg.weight_kg != null ? `${pkg.weight_kg} kg` : '�'}</td>
                          <td>{pkg.cbm != null ? pkg.cbm : '�'}</td>
                          <td>
                            <span className="ship-view-pkg-status">{pkg.status || '�'}</span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          ) : null}

          {tab === 'ecc' ? (
            <div className="ship-view-panel">
              <div className="ship-view-panel-head">
                <HiOutlineDocumentText size={14} aria-hidden="true" />
                Clearance &amp; documents
              </div>
              <div className="ship-view-ecc">
                <div className="ship-view-ecc-cost">
                  <span>Est. clearance cost</span>
                  <strong>{formatMoney(shipment.estimated_clearance_cost, '$')}</strong>
                </div>
                <div className="ship-view-ecc-note">
                  This is an estimate. Use <strong>Landed cost</strong> when final invoices are available.
                </div>
              </div>
              <h3 className="ship-view-subhead">Attached documents</h3>
              <div className="ship-view-table-wrap">
                <table className="ship-view-table">
                  <thead>
                    <tr>
                      <th>Type</th>
                      <th>Authority</th>
                      <th>Dates</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {eccDocs.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="is-empty">
                          No documents uploaded.
                        </td>
                      </tr>
                    ) : (
                      eccDocs.map((doc) => (
                        <tr key={doc.id}>
                          <td>{doc.doc_type || doc.type || '�'}</td>
                          <td>{doc.authority || '�'}</td>
                          <td>{formatDate(doc.doc_date || doc.created_at) || '�'}</td>
                          <td>{doc.status || '�'}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          ) : null}

          {tab === 'shipper' ? (
            <div className="ship-view-panel">
              <div className="ship-view-panel-head">Shipper details</div>
              {shipment.shipper_id ? (
                <div className="ship-view-shipper">
                  <h2>{shipment.shipper_name || 'Shipper'}</h2>
                  <dl className="ship-view-dl">
                    <div>
                      <dt>Service</dt>
                      <dd>{shipment.service_type ? String(shipment.service_type) : 'Standard'}</dd>
                    </div>
                    <div>
                      <dt>Website</dt>
                      <dd>
                        {shipment.shipper_website ? (
                          <a href={shipment.shipper_website} target="_blank" rel="noopener noreferrer">
                            {shipment.shipper_website}
                          </a>
                        ) : (
                          '�'
                        )}
                      </dd>
                    </div>
                    <div>
                      <dt>Phone</dt>
                      <dd>{shipment.shipper_phone || '�'}</dd>
                    </div>
                    <div>
                      <dt>Email</dt>
                      <dd>{shipment.shipper_email || '�'}</dd>
                    </div>
                  </dl>
                </div>
              ) : (
                <div className="ship-view-empty">
                  <HiOutlineTruck size={40} aria-hidden="true" />
                  <p>No shipper linked to this shipment.</p>
                </div>
              )}
            </div>
          ) : null}

          {tab === 'landed-cost' ? (
            <form method="post" action={landedCostAction} className="ship-view-landed">
              <input type="hidden" name="shipment_id" value={shipment.id || ''} />
              <div className="ship-view-landed-grid">
                <div className="ship-view-panel">
                  <div className="ship-view-panel-head">Additional costs</div>
                  <div className="ship-view-landed-body">
                    {costGroups.map(([group, fields]) => (
                      <div key={group} className="ship-view-cost-group">
                        <h3>{group}</h3>
                        <div className="ship-view-cost-fields">
                          {fields.map((f) => (
                            <MoneyInput
                              key={f.key}
                              name={f.key}
                              label={f.label}
                              value={costs[f.key]}
                              onChange={setCost}
                            />
                          ))}
                          {group === 'Shipping & logistics' ? (
                            <label className="ship-view-field">
                              <span>Mode</span>
                              <select
                                name="shipping_method"
                                className="ship-view-input"
                                defaultValue={shipment.shipping_method || 'sea'}
                              >
                                <option value="sea">Sea freight</option>
                                <option value="air">Air freight</option>
                                <option value="road">Road</option>
                              </select>
                            </label>
                          ) : null}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                <aside className="ship-view-landed-side">
                  <div className="ship-view-panel ship-view-panel--summary">
                    <div className="ship-view-panel-head ship-view-panel-head--green">Cost summary</div>
                    <div className="ship-view-landed-body">
                      <ul className="ship-view-summary">
                        <li>
                          <span>Product cost</span>
                          <strong>{formatMoney(productCost, valuePrefix)}</strong>
                        </li>
                        <li>
                          <span>Total additional</span>
                          <strong className="is-danger">{formatMoney(additionalCost, '$')}</strong>
                        </li>
                        <li className="is-total">
                          <span>Landed cost</span>
                          <strong className="is-success">{formatMoney(landedTotal, '$')}</strong>
                        </li>
                      </ul>

                      <label className="ship-view-field">
                        <span>Allocation method</span>
                        <select name="allocation_method" className="ship-view-input" defaultValue="value">
                          <option value="value">By product value (recommended)</option>
                          <option value="weight">By weight</option>
                          <option value="volume">By volume</option>
                        </select>
                      </label>

                      <label className="ship-view-check">
                        <input type="checkbox" name="update_products" value="1" />
                        Update buying price on master products
                      </label>

                      <button type="submit" className="prod-desk-btn ship-edit-btn-primary ship-view-save-btn">
                        Calculate &amp; save
                      </button>
                    </div>
                  </div>

                  <div className="ship-view-panel ship-view-chart-card">
                    <div className="ship-view-chart-bar" role="img" aria-label="Cost breakdown">
                      {chartParts.length === 0 ? (
                        <div className="ship-view-chart-empty">No costs yet</div>
                      ) : (
                        chartParts.map((p) => (
                          <div
                            key={p.label}
                            className="ship-view-chart-seg"
                            style={{ width: `${p.pct}%`, background: p.color }}
                            title={`${p.label}: ${formatMoney(p.value, '$')}`}
                          />
                        ))
                      )}
                    </div>
                    <ul className="ship-view-chart-legend">
                      {chartParts.map((p) => (
                        <li key={p.label}>
                          <span className="swatch" style={{ background: p.color }} />
                          {p.label}
                          <em>{formatMoney(p.value, '$')}</em>
                        </li>
                      ))}
                    </ul>
                  </div>
                </aside>
              </div>
            </form>
          ) : null}
        </div>
      </section>

      {showPkgModal ? (
        <div className="ship-view-modal-backdrop" role="presentation" onClick={() => setShowPkgModal(false)}>
          <div
            className="ship-view-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ship-pkg-modal-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="ship-view-modal-head">
              <h2 id="ship-pkg-modal-title">Add package</h2>
              <button type="button" className="ship-view-modal-close" onClick={() => setShowPkgModal(false)} aria-label="Close">
                <HiOutlineXMark size={18} />
              </button>
            </div>
            <form method="post" action={`${formAction}?id=${shipment.id || ''}`}>
              <input type="hidden" name="action" value="add_package" />
              <input type="hidden" name="shipment_id" value={shipment.id || ''} />
              <div className="ship-view-modal-body">
                <label className="ship-view-field">
                  <span>
                    Package number / ID <em className="req">*</em>
                  </span>
                  <input
                    type="text"
                    name="package_number"
                    className="ship-view-input"
                    required
                    placeholder="e.g. PKG-001 or 1/10"
                    autoComplete="off"
                  />
                </label>
                <label className="ship-view-field">
                  <span>Tracking reference</span>
                  <input type="text" name="tracking_number" className="ship-view-input" placeholder="Optional" autoComplete="off" />
                </label>
                <div className="ship-view-modal-row">
                  <label className="ship-view-field">
                    <span>Dimensions (LxWxH)</span>
                    <input type="text" name="dimensions" className="ship-view-input" placeholder="e.g. 50x50x50 cm" />
                  </label>
                  <label className="ship-view-field">
                    <span>Weight (kg)</span>
                    <input type="number" step="0.01" name="weight_kg" className="ship-view-input" defaultValue="0" />
                  </label>
                  <label className="ship-view-field">
                    <span>CBM</span>
                    <input type="number" step="0.001" name="cbm" className="ship-view-input" defaultValue="0.000" />
                  </label>
                </div>
              </div>
              <div className="ship-view-modal-foot">
                <button type="button" className="prod-desk-btn prod-desk-btn-secondary" onClick={() => setShowPkgModal(false)}>
                  Cancel
                </button>
                <button type="submit" className="prod-desk-btn ship-edit-btn-primary">
                  Save package
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  );
}
