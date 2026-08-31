import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, Wallet } from 'lucide-react';
import type { FinancialAccount, PurchaseOrderRow } from '../types';
import { payPurchaseOrder } from '../api';
import { filterAccountsByPaymentMethod } from '../utils/paymentAccounts';
import PayFileUpload from './pay/PayFileUpload';
import { PayFormField, PaySelect, PayTextInput } from './pay/PayFormField';
import PayModalHeader from './pay/PayModalHeader';

interface PayPurchaseModalProps {
  order: PurchaseOrderRow;
  accounts: FinancialAccount[];
  paymentMethods: string[];
  onClose: () => void;
  onSuccess: (message: string) => void;
}

export default function PayPurchaseModal({
  order,
  accounts,
  paymentMethods,
  onClose,
  onSuccess,
}: PayPurchaseModalProps) {
  const [accountId, setAccountId] = useState('');
  const [amount, setAmount] = useState(String(order.balanceDue || order.amountToPay || 0));
  const [paymentDate, setPaymentDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [paymentMethod, setPaymentMethod] = useState('');
  const [notes, setNotes] = useState('');
  const [proofFile, setProofFile] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const fileInputRef = useRef<HTMLInputElement>(null);

  const paymentAccounts = useMemo(
    () => filterAccountsByPaymentMethod(accounts, paymentMethod),
    [accounts, paymentMethod],
  );

  useEffect(() => {
    if (!accountId) {
      return;
    }

    const stillValid = paymentAccounts.some((account) => String(account.id) === accountId);
    if (!stillValid) {
      setAccountId('');
    }
  }, [accountId, paymentAccounts]);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError('');

    const payAmount = parseFloat(amount);
    if (!accountId || !paymentMethod || !payAmount || payAmount <= 0) {
      setError('Please complete all required payment fields.');
      return;
    }
    if (payAmount > order.balanceDue + 0.009) {
      setError('Amount cannot exceed the balance due.');
      return;
    }
    if (!proofFile) {
      setError('Upload SWIFT or bank payment proof before confirming.');
      return;
    }

    const formData = new FormData();
    formData.set('po_id', String(order.id));
    formData.set('account_id', accountId);
    formData.set('payment_amount', String(payAmount));
    formData.set('payment_date', paymentDate);
    formData.set('payment_method', paymentMethod);
    if (notes.trim()) formData.set('payment_notes', notes.trim());
    formData.set('swift_file', proofFile);

    setSubmitting(true);
    try {
      const result = await payPurchaseOrder(formData);
      onSuccess(result.message);
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Payment failed.');
    } finally {
      setSubmitting(false);
    }
  }

  const requiredAmount = order.balanceDue || order.amountToPay || 0;
  const enteredAmount = parseFloat(amount);
  const amountMeetsRequired =
    Number.isFinite(enteredAmount) && enteredAmount >= requiredAmount - 0.009;
  const amountColor = amountMeetsRequired ? '#16a34a' : '#dc2626';

  const modal = (
    <div className="sppd-modal-backdrop" onClick={onClose}>
      <div
        className="sppd-modal sppd-pay-modal"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-labelledby="sppd-pay-modal-title"
      >
        <PayModalHeader onClose={onClose} />

        <form className="sppd-pay-modal-form" onSubmit={handleSubmit}>
          <div className="sppd-pay-modal-body">
            <section className="sppd-pay-section">
              <h3 className="sppd-pay-section-title">Payment details</h3>
              <div className="sppd-pay-form-grid">
                <PayFormField id="pay-date" label="Payment date" required>
                  <PayTextInput
                    id="pay-date"
                    type="date"
                    value={paymentDate}
                    onChange={(e) => setPaymentDate(e.target.value)}
                    required
                  />
                </PayFormField>

                <PayFormField id="pay-method" label="Payment method" required>
                  <PaySelect
                    id="pay-method"
                    value={paymentMethod}
                    onChange={(e) => {
                      setPaymentMethod(e.target.value);
                      setAccountId('');
                    }}
                    required
                  >
                    <option value="">Select method</option>
                    {paymentMethods.map((method) => (
                      <option key={method} value={method}>{method}</option>
                    ))}
                  </PaySelect>
                </PayFormField>

                <PayFormField id="pay-account" label="Payment account" required>
                  <PaySelect
                    id="pay-account"
                    value={accountId}
                    onChange={(e) => setAccountId(e.target.value)}
                    required
                    disabled={!paymentMethod}
                  >
                    <option value="">
                      {paymentMethod ? 'Select account' : 'Select payment method first'}
                    </option>
                    {paymentAccounts.map((acc) => (
                      <option key={acc.id} value={acc.id}>
                        {acc.name} ({acc.currency}{' '}
                        {acc.balance.toLocaleString(undefined, {
                          minimumFractionDigits: 2,
                          maximumFractionDigits: 2,
                        })})
                      </option>
                    ))}
                  </PaySelect>
                </PayFormField>

                <PayFormField id="pay-amount" label="amount" required>
                  <PayTextInput
                    id="pay-amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    max={order.balanceDue}
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    className={amountMeetsRequired ? 'sppd-pay-input--met' : 'sppd-pay-input--under'}
                    style={{ color: amountColor, WebkitTextFillColor: amountColor, fontWeight: 600 }}
                    required
                  />
                </PayFormField>

                <PayFormField id="pay-notes" label="Notes">
                  <PayTextInput
                    id="pay-notes"
                    type="text"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="Optional"
                  />
                </PayFormField>
              </div>
            </section>

            <section className="sppd-pay-section">
              <h3 className="sppd-pay-section-title">Payment proof</h3>
              <PayFileUpload
                inputRef={fileInputRef}
                file={proofFile}
                onFileChange={setProofFile}
              />
            </section>

            {error && (
              <div className="sppd-flash sppd-flash-error" role="alert">
                {error}
              </div>
            )}
          </div>

          <div className="sppd-pay-modal-foot">
            <button type="button" className="sppd-btn sppd-btn-secondary" onClick={onClose} disabled={submitting}>
              Cancel
            </button>
            <button type="submit" className="sppd-btn sppd-btn-primary sppd-btn-pay-confirm" disabled={submitting}>
              {submitting ? <Loader2 className="w-4 h-4 animate-spin" /> : <Wallet className="w-4 h-4" />}
              {submitting ? 'Posting...' : 'Confirm payment'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );

  return createPortal(modal, document.body);
}
