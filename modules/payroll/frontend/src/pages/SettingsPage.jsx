import { useCallback, useEffect, useState } from 'react';
import {
  BookOpen,
  Loader2,
  Pencil,
  Plus,
  Save,
  Trash2,
  X,
} from 'lucide-react';
import {
  deskPageUrl,
  fetchSettings,
  formatAmount,
  saveSettingsAction,
} from '../api/payrollDesk';

const emptyBand = {
  id: 0,
  minSalary: '',
  maxSalary: '',
  taxRate: '',
  offsetAmount: '0',
  description: '',
  isActive: true,
};

function formatBandNumber(value) {
  const amount = Number(value) || 0;
  return amount.toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function buildBandDescription({ minSalary, taxRate, offsetAmount }) {
  const min = Number(minSalary) || 0;
  const rate = Number(taxRate) || 0;
  const offset = Number(offsetAmount) || 0;
  if (rate === 0) return '0% (No tax)';
  const rateLabel = String(rate).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
  const base = offset > 0 ? `${formatBandNumber(offset)} + ` : '';
  return `${base}${rateLabel}% of amount above ${formatBandNumber(min)}`;
}

export default function SettingsPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [globalForm, setGlobalForm] = useState({
    payDay: '30',
    socialSecurityRate: '10',
    taxRate: '0',
  });
  const [bandModal, setBandModal] = useState(null);
  const [manualOpen, setManualOpen] = useState(false);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchSettings();
      setInit(data);
      setGlobalForm({
        payDay: String(data.settings?.payDay ?? '30'),
        socialSecurityRate: String(data.settings?.socialSecurityRate ?? '10'),
        taxRate: String(data.settings?.taxRate ?? '0'),
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load settings.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  async function runAction(payload, closeModal) {
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const res = await saveSettingsAction(payload);
      setInit(res.data || init);
      if (res.data?.settings) {
        setGlobalForm({
          payDay: String(res.data.settings.payDay ?? '30'),
          socialSecurityRate: String(res.data.settings.socialSecurityRate ?? '10'),
          taxRate: String(res.data.settings.taxRate ?? '0'),
        });
      }
      setNotice(res.message || 'Saved.');
      if (typeof closeModal === 'function') closeModal();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Save failed.');
    } finally {
      setSaving(false);
    }
  }

  async function handleSaveGlobal(event) {
    event.preventDefault();
    await runAction({
      action: 'save_settings',
      payDay: globalForm.payDay,
      socialSecurityRate: globalForm.socialSecurityRate,
      taxRate: globalForm.taxRate,
    });
  }

  if (loading) {
    return (
      <div className="pay-desk-page">
        <div className="pay-desk-loading" role="status">
          <Loader2 className="pay-desk-boot-spinner" size={18} aria-hidden="true" />
          <span>Loading settings...</span>
        </div>
      </div>
    );
  }

  const taxBands = init?.taxBands || [];
  const links = init?.links || {};

  return (
    <div className="pay-desk-page pay-settings-page">
      <div className="pay-desk-page-header pay-settings-page-header">
        <a href={links.dashboard || deskPageUrl('index.php')} className="pay-settings-back-link">
          ? Back
        </a>
        <div className="pay-desk-page-header-actions">
          <button
            type="button"
            className="pay-desk-btn pay-desk-btn-secondary pay-desk-btn--pill"
            onClick={() => setManualOpen(true)}
          >
            <BookOpen size={15} aria-hidden="true" />
            Manual
          </button>
        </div>
      </div>

      {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}
      {notice && <div className="pay-desk-flash-ok" role="status">{notice}</div>}

      <section className="pay-settings-card">
        <div className="pay-settings-card-head">
          <div>
            <h2>PAYE tax bands (Tanzania)</h2>
            <p>Configure the progressive tax rates.</p>
          </div>
          <button
            type="button"
            className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill"
            onClick={() => setBandModal({ ...emptyBand })}
          >
            <Plus size={15} aria-hidden="true" />
            Add band
          </button>
        </div>
        <div className="pay-desk-table-wrap">
          <table className="pay-desk-table">
            <thead>
              <tr>
                <th>Taxable income (TZS)</th>
                <th>Tax rate</th>
                <th>Fixed base</th>
                <th>Description</th>
                <th>Status</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {taxBands.length === 0 ? (
                <tr>
                  <td colSpan={6} className="pay-desk-empty-cell">No tax bands found.</td>
                </tr>
              ) : (
                taxBands.map((band) => (
                  <tr key={band.id}>
                    <td className="pay-desk-cell-main">{band.rangeLabel}</td>
                    <td>{band.taxRate}%</td>
                    <td>{formatAmount(band.offsetAmount)}</td>
                    <td className="pay-desk-cell-sub">{band.description}</td>
                    <td>
                      <label className="pay-settings-switch">
                        <input
                          type="checkbox"
                          checked={Boolean(band.isActive)}
                          disabled={saving}
                          onChange={(e) => runAction({
                            action: 'save_tax_band',
                            id: band.id,
                            minSalary: band.minSalary,
                            maxSalary: band.maxSalary ?? '',
                            taxRate: band.taxRate,
                            offsetAmount: band.offsetAmount,
                            isActive: e.target.checked,
                          })}
                        />
                        <span>Active</span>
                      </label>
                    </td>
                    <td className="pay-desk-actions">
                      <button
                        type="button"
                        className="pay-desk-icon-btn"
                        title="Edit band"
                        onClick={() => setBandModal({
                          id: band.id,
                          minSalary: String(band.minSalary ?? ''),
                          maxSalary: band.maxSalary == null ? '' : String(band.maxSalary),
                          taxRate: String(band.taxRate ?? ''),
                          offsetAmount: String(band.offsetAmount ?? 0),
                          description: String(band.description ?? ''),
                          isActive: Boolean(band.isActive),
                        })}
                      >
                        <Pencil size={15} aria-hidden="true" />
                      </button>
                      <button
                        type="button"
                        className="pay-desk-icon-btn"
                        title="Delete band"
                        onClick={() => {
                          if (window.confirm('Delete this tax band?')) {
                            runAction({ action: 'delete_tax_band', id: band.id });
                          }
                        }}
                      >
                        <Trash2 size={15} aria-hidden="true" />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="pay-settings-card pay-settings-card--global">
        <div className="pay-settings-card-head">
          <div>
            <h2>Global settings</h2>
            <p>Default payday, NSSF, and flat tax.</p>
          </div>
        </div>
        <form className="pay-settings-form pay-settings-form--horizontal" onSubmit={handleSaveGlobal}>
          <div className="pay-settings-fields-row">
            <div className="pay-settings-field">
              <label className="pay-create-label" htmlFor="pay_day">Default pay day</label>
              <input
                id="pay_day"
                type="number"
                min="1"
                max="31"
                className="pay-create-input"
                value={globalForm.payDay}
                onChange={(e) => setGlobalForm((c) => ({ ...c, payDay: e.target.value }))}
              />
            </div>
            <div className="pay-settings-field">
              <label className="pay-create-label" htmlFor="nssf_rate">Employee NSSF contribution (%)</label>
              <input
                id="nssf_rate"
                type="number"
                step="0.01"
                className="pay-create-input"
                value={globalForm.socialSecurityRate}
                onChange={(e) => setGlobalForm((c) => ({ ...c, socialSecurityRate: e.target.value }))}
              />
            </div>
            <div className="pay-settings-field">
              <label className="pay-create-label" htmlFor="tax_rate">Tax rate (flat %)</label>
              <input
                id="tax_rate"
                type="number"
                step="0.01"
                className="pay-create-input"
                value={globalForm.taxRate}
                onChange={(e) => setGlobalForm((c) => ({ ...c, taxRate: e.target.value }))}
              />
              <p className="pay-desk-cell-sub">Set to 0 if using graduated tax bands above.</p>
            </div>
            <div className="pay-settings-field pay-settings-field--action">
              <button type="submit" className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill" disabled={saving}>
                {saving ? <Loader2 size={16} className="pay-desk-boot-spinner" /> : <Save size={16} aria-hidden="true" />}
                Save
              </button>
            </div>
          </div>
        </form>
      </section>

      {bandModal && (
        <div className="pay-desk-modal-backdrop" role="presentation" onClick={() => !saving && setBandModal(null)}>
          <div className="pay-desk-modal" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
            <div className="pay-salary-edit-modal-head">
              <h2 className="pay-salary-edit-modal-title">{bandModal.id ? 'Edit tax band' : 'Add tax band'}</h2>
              <button type="button" className="pay-salary-edit-modal-close" onClick={() => setBandModal(null)} aria-label="Close">
                <X size={18} aria-hidden="true" />
              </button>
            </div>
            <form
              className="pay-settings-modal-form"
              onSubmit={(e) => {
                e.preventDefault();
                runAction({
                  action: 'save_tax_band',
                  id: bandModal.id,
                  minSalary: bandModal.minSalary,
                  maxSalary: bandModal.maxSalary,
                  taxRate: bandModal.taxRate,
                  offsetAmount: bandModal.offsetAmount,
                  description: bandModal.description || buildBandDescription(bandModal),
                  isActive: bandModal.isActive,
                }, () => setBandModal(null));
              }}
            >
              <div className="pay-settings-modal-body">
                <label className="pay-create-label" htmlFor="band_min">Min salary</label>
                <input
                  id="band_min"
                  type="number"
                  className="pay-create-input"
                  required
                  value={bandModal.minSalary}
                  onChange={(e) => setBandModal((c) => {
                    const next = { ...c, minSalary: e.target.value };
                    return { ...next, description: buildBandDescription(next) };
                  })}
                />
                <label className="pay-create-label" htmlFor="band_max">Max salary</label>
                <input
                  id="band_max"
                  type="number"
                  className="pay-create-input"
                  placeholder="Leave empty for infinity"
                  value={bandModal.maxSalary}
                  onChange={(e) => setBandModal((c) => ({ ...c, maxSalary: e.target.value }))}
                />
                <label className="pay-create-label" htmlFor="band_rate">Tax rate (%)</label>
                <input
                  id="band_rate"
                  type="number"
                  step="0.01"
                  className="pay-create-input"
                  required
                  value={bandModal.taxRate}
                  onChange={(e) => setBandModal((c) => {
                    const next = { ...c, taxRate: e.target.value };
                    return { ...next, description: buildBandDescription(next) };
                  })}
                />
                <label className="pay-create-label" htmlFor="band_offset">Fixed offset (TZS)</label>
                <input
                  id="band_offset"
                  type="number"
                  step="0.01"
                  className="pay-create-input"
                  value={bandModal.offsetAmount}
                  onChange={(e) => setBandModal((c) => {
                    const next = { ...c, offsetAmount: e.target.value };
                    return { ...next, description: buildBandDescription(next) };
                  })}
                />
                <label className="pay-create-label" htmlFor="band_description">Description</label>
                <textarea
                  id="band_description"
                  className="pay-create-input pay-settings-description"
                  rows={2}
                  placeholder="Shown in the tax bands table"
                  value={bandModal.description}
                  onChange={(e) => setBandModal((c) => ({ ...c, description: e.target.value }))}
                />
                <p className="pay-desk-cell-sub">Auto-filled from rate and offset; you can edit it.</p>
              </div>
              <div className="pay-settings-modal-actions">
                <button type="button" className="pay-desk-btn pay-desk-btn-secondary pay-desk-btn--pill" onClick={() => setBandModal(null)}>Cancel</button>
                <button type="submit" className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill" disabled={saving}>Save band</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {manualOpen && (
        <div className="pay-desk-modal-backdrop" role="presentation" onClick={() => setManualOpen(false)}>
          <div className="pay-desk-modal pay-settings-manual-modal" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
            <div className="pay-salary-edit-modal-head">
              <h2 className="pay-salary-edit-modal-title">Configuration manual</h2>
              <button type="button" className="pay-salary-edit-modal-close" onClick={() => setManualOpen(false)} aria-label="Close">
                <X size={18} aria-hidden="true" />
              </button>
            </div>
            <div className="pay-settings-modal-body">
              <p className="pay-desk-cell-sub">Use PAYE bands for progressive Tanzania tax. Keep flat tax at 0 when bands are active.</p>
              <button type="button" className="pay-desk-btn pay-desk-btn-secondary pay-desk-btn--pill" onClick={() => setManualOpen(false)}>Close</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
