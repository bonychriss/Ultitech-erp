import { useCallback, useEffect, useMemo, useState } from 'react';
import { Loader2 } from 'lucide-react';
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
  overtimeAllowances: '',
  bonusCommission: '',
  monthlyAdjustment: '',
  nssfDeduction: '',
  taxDeduction: '',
  otherDeductions: '',
  remarks: '',
};

function Field({ id, label, value, onChange, type = 'number' }) {
  return (
    <div className="pay-ca-row">
      <label className="pay-ca-label" htmlFor={id}>{label}</label>
      <div className="pay-ca-money">
        {type === 'number' && <span className="pay-ca-money-prefix">TZS</span>}
        <input
          id={id}
          type={type}
          step={type === 'number' ? '0.01' : undefined}
          className="pay-ca-input"
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
      </div>
    </div>
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
        overtimeAllowances: slip.overtimeAllowances ?? '',
        bonusCommission: slip.bonusCommission ?? '',
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
    const bonus = Number(form.bonusCommission) || 0;
    const adj = Number(form.monthlyAdjustment) || 0;
    const nssf = Number(form.nssfDeduction) || 0;
    const tax = Number(form.taxDeduction) || 0;
    const other = Number(form.otherDeductions) || 0;
    return basic + allowances + bonus + adj - nssf - tax - other;
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
        overtimeAllowances: Number(form.overtimeAllowances) || 0,
        bonusCommission: Number(form.bonusCommission) || 0,
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
        overtimeAllowances: next.overtimeAllowances ?? form.overtimeAllowances,
        bonusCommission: next.bonusCommission ?? form.bonusCommission,
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
      <div className="pay-ca-loading" role="status" aria-live="polite">
        <Loader2 className="pay-desk-boot-spinner" size={18} aria-hidden="true" />
        <span>Loading payslip...</span>
      </div>
    );
  }

  if (!init && error) {
    return (
      <div className="pay-ca-main">
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
    <form className="pay-ca-form" onSubmit={handleSubmit}>
      <div className="pay-ca-main">
        <div className="pay-ca-employee">
          <EmployeeAvatar name={slip.fullName} id={slip.userId} />
          <div className="pay-desk-employee-meta">
            <div className="pay-desk-cell-main">{slip.fullName || 'Employee'}</div>
            <div className="pay-desk-cell-sub">{slip.periodLabel || ''}</div>
          </div>
        </div>

        {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}
        {notice && <div className="pay-desk-flash-ok" role="status">{notice}</div>}

        <section className="pay-ca-section">
          <h3 className="pay-ca-section-title">Earnings</h3>
          <Field id="basic_salary" label="Basic salary" value={form.basicSalary} onChange={(v) => updateField('basicSalary', v)} />
          <Field id="total_allowances" label="Overtime & allowances" value={form.totalAllowances} onChange={(v) => updateField('totalAllowances', v)} />
          <Field id="bonus_commission" label="Bonus / commission" value={form.bonusCommission} onChange={(v) => updateField('bonusCommission', v)} />
          <Field id="monthly_adjustment" label="Adjustments" value={form.monthlyAdjustment} onChange={(v) => updateField('monthlyAdjustment', v)} />
        </section>

        <section className="pay-ca-section">
          <h3 className="pay-ca-section-title">Deductions</h3>
          <Field id="nssf_deduction" label="NSSF (employee)" value={form.nssfDeduction} onChange={(v) => updateField('nssfDeduction', v)} />
          <Field id="tax_deduction" label="PAYE (tax)" value={form.taxDeduction} onChange={(v) => updateField('taxDeduction', v)} />
          <Field id="other_deductions" label="Other deductions" value={form.otherDeductions} onChange={(v) => updateField('otherDeductions', v)} />
        </section>

        {(Number(slip.employerNssf) > 0 || Number(slip.sdlAmount) > 0 || Number(slip.wcfAmount) > 0) && (
          <section className="pay-ca-section">
            <h3 className="pay-ca-section-title">Employer cost (auto)</h3>
            <div className="pay-desk-cell-sub">
              Employer NSSF {formatAmount(slip.employerNssf || 0)} · SDL {formatAmount(slip.sdlAmount || 0)} · WCF {formatAmount(slip.wcfAmount || 0)} · Total {formatAmount(slip.employerCost || 0)}
            </div>
          </section>
        )}

        <section className="pay-ca-section">
          <div className="pay-ca-row">
            <label className="pay-ca-label" htmlFor="remarks">Remarks / notes</label>
            <textarea
              id="remarks"
              className="pay-ca-textarea"
              rows={2}
              placeholder="Add any special notes or reasons for adjustments..."
              value={form.remarks}
              onChange={(e) => updateField('remarks', e.target.value)}
            />
          </div>
        </section>

        <div className="pay-ca-net">
          <span className="pay-ca-net-label">Estimated net</span>
          <span className="pay-ca-net-value">TZS {formatAmount(netPreview)}</span>
        </div>
      </div>

      <div className="pay-ca-actions">
        {typeof onClose === 'function' && (
          <button type="button" className="pay-desk-btn pay-desk-btn-secondary pay-desk-btn--pill" onClick={onClose} disabled={saving}>
            Cancel
          </button>
        )}
        <button type="submit" className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill" disabled={saving}>
          {saving ? 'Saving...' : 'Save changes'}
        </button>
      </div>
    </form>
  );
}
