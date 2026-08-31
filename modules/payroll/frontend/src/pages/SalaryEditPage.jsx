import { useCallback, useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import {
  deskPageUrl,
  fetchSalaryEmployee,
  resolveEmployeeId,
  saveSalary,
} from '../api/payrollDesk';
import EmployeeAvatar from '../components/EmployeeAvatar.jsx';

const emptySalary = {
  basicSalary: '',
  houseAllowance: '',
  transportAllowance: '',
  bankName: '',
  accountNumber: '',
  tinNumber: '',
  nssfNumber: '',
};

export default function SalaryEditPage({
  employeeId: employeeIdProp,
  variant = 'page',
  onClose,
  onSaved,
} = {}) {
  const employeeId = Number(employeeIdProp) > 0 ? Number(employeeIdProp) : resolveEmployeeId();
  const isModal = variant === 'modal';
  const [init, setInit] = useState(null);
  const [form, setForm] = useState(emptySalary);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const loadData = useCallback(async () => {
    if (employeeId <= 0) {
      setError('Invalid employee id.');
      setLoading(false);
      return;
    }
    setLoading(true);
    setError('');
    try {
      const data = await fetchSalaryEmployee(employeeId);
      setInit(data);
      setForm({
        basicSalary: data.salary?.basicSalary ?? '',
        houseAllowance: data.salary?.houseAllowance ?? '',
        transportAllowance: data.salary?.transportAllowance ?? '',
        bankName: data.salary?.bankName ?? '',
        accountNumber: data.salary?.accountNumber ?? '',
        tinNumber: data.salary?.tinNumber ?? '',
        nssfNumber: data.salary?.nssfNumber ?? '',
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load employee.');
    } finally {
      setLoading(false);
    }
  }, [employeeId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  function updateField(key, value) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  function handleCancel() {
    if (typeof onClose === 'function') {
      onClose();
      return;
    }
    window.location.href = init?.links?.salaries || deskPageUrl('salaries.php');
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const res = await saveSalary({
        userId: employeeId,
        basicSalary: Number(form.basicSalary) || 0,
        houseAllowance: Number(form.houseAllowance) || 0,
        transportAllowance: Number(form.transportAllowance) || 0,
        bankName: form.bankName,
        accountNumber: form.accountNumber,
        tinNumber: form.tinNumber,
        nssfNumber: form.nssfNumber,
      });
      setInit(res.data || init);
      const message = res.message || 'Salary details updated.';
      if (typeof onSaved === 'function') {
        onSaved({
          data: res.data || init,
          message,
          employeeId,
        });
      }
      if (isModal && typeof onClose === 'function') {
        onClose();
      } else {
        setNotice(message);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not save salary.');
    } finally {
      setSaving(false);
    }
  }

  const employee = init?.employee;
  const basic = Number(form.basicSalary) || 0;
  const house = Number(form.houseAllowance) || 0;
  const transport = Number(form.transportAllowance) || 0;
  const gross = basic + house + transport;

  if (loading && !init) {
    return (
      <div className="pay-create-loading" role="status" aria-live="polite">
        <Loader2 size={22} className="pay-create-spinner" aria-hidden="true" />
        Loading form...
      </div>
    );
  }

  if (!init && error) {
    return (
      <div className={`pay-create-shell${isModal ? ' pay-create-shell--modal' : ''}`}>
        <div className="pay-create-alert pay-create-alert--error" role="alert">{error}</div>
        <button type="button" className="pay-create-btn-cancel" onClick={handleCancel}>
          {isModal ? 'Close' : 'Back to salaries'}
        </button>
      </div>
    );
  }

  return (
    <div className={`pay-create-shell${isModal ? ' pay-create-shell--modal' : ''}`}>
      {error && (
        <div className="pay-create-alert pay-create-alert--error" role="alert">{error}</div>
      )}
      {notice && (
        <div className="pay-create-alert pay-create-alert--ok" role="status">{notice}</div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="pay-create-main">
          <section className="pay-create-section" id="salary-employee">
            <div className={`pay-create-row${isModal ? ' pay-create-row--staff' : ''}`}>
              {!isModal && <div className="pay-create-label">Staff</div>}
              <div className="pay-create-employee">
                {employee ? (
                  <>
                    <EmployeeAvatar name={employee.fullName} id={employee.id} large={!isModal} />
                    <div>
                      <div className="pay-create-employee-name">{employee.fullName}</div>
                      <div className="pay-create-help">
                        {String(employee.department || 'N/A').toUpperCase()}
                        {employee.email ? ` | ${employee.email}` : ''}
                      </div>
                    </div>
                  </>
                ) : null}
              </div>
            </div>
          </section>

          <section className="pay-create-section" id="salary-compensation">
            <div className="pay-create-row">
              <label className="pay-create-label" htmlFor="basic_salary">
                Basic salary<span className="req">*</span>
              </label>
              <div>
                <div className="pay-create-input-group">
                  <span>TZS</span>
                  <input
                    id="basic_salary"
                    type="number"
                    step="0.01"
                    min="0"
                    className="pay-create-input pay-create-input--price"
                    value={form.basicSalary}
                    onChange={(e) => updateField('basicSalary', e.target.value)}
                    required
                  />
                </div>
              </div>
            </div>

            <div className="pay-create-allowance-grid">
              <div className="pay-create-row">
                <label className="pay-create-label" htmlFor="house_allowance">
                  House allowance
                </label>
                <div>
                  <div className="pay-create-input-group">
                    <span>TZS</span>
                    <input
                      id="house_allowance"
                      type="number"
                      step="0.01"
                      min="0"
                      className="pay-create-input pay-create-input--price"
                      value={form.houseAllowance}
                      onChange={(e) => updateField('houseAllowance', e.target.value)}
                    />
                  </div>
                </div>
              </div>

              <div className="pay-create-row">
                <label className="pay-create-label" htmlFor="transport_allowance">
                  Transport allowance
                </label>
                <div>
                  <div className="pay-create-input-group">
                    <span>TZS</span>
                    <input
                      id="transport_allowance"
                      type="number"
                      step="0.01"
                      min="0"
                      className="pay-create-input pay-create-input--price"
                      value={form.transportAllowance}
                      onChange={(e) => updateField('transportAllowance', e.target.value)}
                    />
                  </div>
                </div>
              </div>
            </div>

            <div className="pay-create-help pay-create-help--gross">
              Gross pay preview:{' '}
              <strong>
                TZS {gross.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </strong>
            </div>
          </section>

          <section className="pay-create-section" id="salary-banking">
            <div className="pay-create-row">
              <label className="pay-create-label" htmlFor="bank_name">
                Bank name
              </label>
              <div>
                <input
                  id="bank_name"
                  type="text"
                  className="pay-create-input"
                  placeholder="e.g. CRDB Bank"
                  value={form.bankName}
                  onChange={(e) => updateField('bankName', e.target.value)}
                />
              </div>
            </div>

            <div className="pay-create-row">
              <label className="pay-create-label" htmlFor="account_number">
                Account number
              </label>
              <div>
                <input
                  id="account_number"
                  type="text"
                  className="pay-create-input"
                  placeholder="Enter account number"
                  value={form.accountNumber}
                  onChange={(e) => updateField('accountNumber', e.target.value)}
                />
              </div>
            </div>
          </section>

          <section className="pay-create-section" id="salary-statutory">
            <div className="pay-create-row">
              <label className="pay-create-label" htmlFor="tin_number">
                TIN number
              </label>
              <div>
                <input
                  id="tin_number"
                  type="text"
                  className="pay-create-input"
                  placeholder="Tax identification number"
                  value={form.tinNumber}
                  onChange={(e) => updateField('tinNumber', e.target.value)}
                />
              </div>
            </div>

            <div className="pay-create-row">
              <label className="pay-create-label" htmlFor="nssf_number">
                NSSF number
              </label>
              <div>
                <input
                  id="nssf_number"
                  type="text"
                  className="pay-create-input"
                  placeholder="Social security number"
                  value={form.nssfNumber}
                  onChange={(e) => updateField('nssfNumber', e.target.value)}
                />
              </div>
            </div>
          </section>

          <div className="pay-create-actions">
            <button type="button" className="pay-create-btn-cancel" onClick={handleCancel}>
              Cancel
            </button>
            <button type="submit" className="pay-create-btn-save" disabled={saving}>
              {saving ? (
                <>
                  <Loader2 size={16} className="pay-create-spinner" aria-hidden="true" />
                  Saving...
                </>
              ) : (
                'Save configuration'
              )}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
