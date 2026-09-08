import { useCallback, useEffect, useMemo, useState } from 'react';
import { Loader2, Save } from 'lucide-react';
import {
  deskPageUrl,
  fetchPayslipEdit,
  formatAmount,
  resolvePayslipId,
  savePayslipEdit,
} from '../api/payrollDesk';
import EmployeeAvatar from '../components/EmployeeAvatar.jsx';

const emptyForm = {
  basicSalary: '',
  totalAllowances: '',
  monthlyAdjustment: '',
  nssfDeduction: '',
  taxDeduction: '',
  otherDeductions: '',
  remarks: '',
};

function MoneyField({ id, label, value, onChange }) {
  return (
    <label className="pay-sharpfill" htmlFor={id}>
      <span className="pay-sharpfill-label">{label}</span>
      <div className="pay-sharpfill-box">
        <span className="pay-sharpfill-prefix">TZS</span>
        <input
          id={id}
          type="number"
          step="0.01"
          className="pay-sharpfill-input"
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
      </div>
    </label>
  );
}

export default function EditPayslipForm({
  payslipId: payslipIdProp,
  onClose,
  onSaved,
  onLoaded,
} = {}) {
  const payslipId = Number(payslipIdProp) > 0 ? Number(payslipIdProp) : resolvePayslipId();
  const [init, setInit] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const loadData = useCallback(async () => {
    if (payslipId <= 0) {
      setError('Invalid payslip id.');
      setLoading(false);
      return;
    }
    setLoading(true);
    setError('');
    try {
      const data = await fetchPayslipEdit(payslipId);
      setInit(data);
      const slip = data.payslip || {};
      setForm({
        basicSalary: slip.basicSalary ?? '',
        totalAllowances: slip.totalAllowances ?? '',
        monthlyAdjustment: slip.monthlyAdjustment ?? '',
        nssfDeduction: slip.nssfDeduction ?? '',
        taxDeduction: slip.taxDeduction ?? '',
        otherDeductions: slip.otherDeductions ?? '',
        remarks: slip.remarks ?? '',
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load payslip.');
    } finally {
      setLoading(false);
    }
  }, [payslipId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  useEffect(() => {
    if (init && typeof onLoaded === 'function') {
      onLoaded(init);
    }
  }, [init, onLoaded]);

  const slip = init?.payslip || {};

  const netPreview = useMemo(() => {
    const basic = Number(form.basicSalary) || 0;
    const allowances = Number(form.totalAllowances) || 0;
    const adj = Number(form.monthlyAdjustment) || 0;
    const nssf = Number(form.nssfDeduction) || 0;
    const tax = Number(form.taxDeduction) || 0;
    const other = Number(form.otherDeductions) || 0;
    return basic + allowances + adj - nssf - tax - other;
  }, [form]);

  function updateField(key, value) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const res = await savePayslipEdit({
        id: payslipId,
        basicSalary: Number(form.basicSalary) || 0,
        totalAllowances: Number(form.totalAllowances) || 0,
        monthlyAdjustment: Number(form.monthlyAdjustment) || 0,
        nssfDeduction: Number(form.nssfDeduction) || 0,
        taxDeduction: Number(form.taxDeduction) || 0,
        otherDeductions: Number(form.otherDeductions) || 0,
        remarks: form.remarks,
      });
      setInit(res.data || init);
      const next = res.data?.payslip || {};
      setForm({
        basicSalary: next.basicSalary ?? form.basicSalary,
        totalAllowances: next.totalAllowances ?? form.totalAllowances,
        monthlyAdjustment: next.monthlyAdjustment ?? form.monthlyAdjustment,
        nssfDeduction: next.nssfDeduction ?? form.nssfDeduction,
        taxDeduction: next.taxDeduction ?? form.taxDeduction,
        otherDeductions: next.otherDeductions ?? form.otherDeductions,
        remarks: next.remarks ?? form.remarks,
      });
      const message = res.message || 'Payslip updated successfully.';
      setNotice(message);
      if (typeof onSaved === 'function') {
        onSaved({ data: res.data || init, message, payslipId });
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not save payslip.');
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <div className="pay-desk-loading" role="status" aria-live="polite">
        <Loader2 className="pay-desk-boot-spinner" size={18} aria-hidden="true" />
        <span>Loading payslip...</span>
      </div>
    );
  }

  if (!init && error) {
    return (
      <div className="pay-slip-edit-modal-body-pad">
        <div className="pay-desk-flash-error" role="alert">{error}</div>
        {typeof onClose === 'function' ? (
          <button type="button" className="pay-desk-btn pay-desk-btn-secondary pay-desk-btn--pill" onClick={onClose}>
            Close
          </button>
        ) : (
          <a href={deskPageUrl('index.php')} className="pay-desk-btn pay-desk-btn-secondary pay-desk-btn--pill">
            Back to payroll
          </a>
        )}
      </div>
    );
  }

  return (
    <div className="pay-slip-edit-modal-body-pad">
      <div className="pay-slip-edit-modal-employee">
        <EmployeeAvatar name={slip.fullName} id={slip.userId} />
        <div className="pay-desk-employee-meta">
          <div className="pay-desk-cell-main">{slip.fullName || 'Employee'}</div>
          <div className="pay-desk-cell-sub">{slip.periodLabel || ''}</div>
        </div>
      </div>

      {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}
      {notice && <div className="pay-desk-flash-ok" role="status">{notice}</div>}

      <form className="pay-slip-edit-card pay-slip-edit-card--modal" onSubmit={handleSubmit}>
        <div className="pay-slip-edit-ledger">
          <div className="pay-slip-edit-block">
            <h3 className="pay-slip-edit-block-title">Earnings</h3>
            <div className="pay-sharpfill-stack">
              <MoneyField id="basic_salary" label="Basic salary" value={form.basicSalary} onChange={(v) => updateField('basicSalary', v)} />
              <MoneyField id="total_allowances" label="Allowances" value={form.totalAllowances} onChange={(v) => updateField('totalAllowances', v)} />
              <MoneyField id="monthly_adjustment" label="Adjustments" value={form.monthlyAdjustment} onChange={(v) => updateField('monthlyAdjustment', v)} />
            </div>
          </div>

          <div className="pay-slip-edit-block pay-slip-edit-block--deductions">
            <h3 className="pay-slip-edit-block-title">Deductions</h3>
            <div className="pay-sharpfill-stack">
              <MoneyField id="nssf_deduction" label="NSSF (employee)" value={form.nssfDeduction} onChange={(v) => updateField('nssfDeduction', v)} />
              <MoneyField id="tax_deduction" label="PAYE (tax)" value={form.taxDeduction} onChange={(v) => updateField('taxDeduction', v)} />
              <MoneyField id="other_deductions" label="Other deductions" value={form.otherDeductions} onChange={(v) => updateField('otherDeductions', v)} />
            </div>
          </div>
        </div>

        <div className="pay-slip-edit-block">
          <h3 className="pay-slip-edit-block-title">Additional info</h3>
          <label className="pay-sharpfill pay-sharpfill--full" htmlFor="remarks">
            <span className="pay-sharpfill-label">Remarks / notes</span>
            <textarea
              id="remarks"
              className="pay-sharpfill-textarea"
              rows={2}
              placeholder="Add any special notes or reasons for adjustments..."
              value={form.remarks}
              onChange={(e) => updateField('remarks', e.target.value)}
            />
          </label>
        </div>

        <div className="pay-slip-edit-footer">
          <div className="pay-slip-net-box">
            <div className="pay-slip-net-label">Estimated net salary</div>
            <div className="pay-slip-net-value">TZS {formatAmount(netPreview)}</div>
          </div>
          <div className="pay-slip-edit-footer-actions">
            {typeof onClose === 'function' && (
              <button type="button" className="pay-desk-btn pay-desk-btn-secondary pay-desk-btn--pill" onClick={onClose} disabled={saving}>
                Cancel
              </button>
            )}
            <button type="submit" className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill" disabled={saving}>
              {saving ? (
                <>
                  <Loader2 size={16} className="pay-desk-boot-spinner" aria-hidden="true" />
                  Saving...
                </>
              ) : (
                <>
                  <Save size={16} aria-hidden="true" />
                  Save changes
                </>
              )}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
