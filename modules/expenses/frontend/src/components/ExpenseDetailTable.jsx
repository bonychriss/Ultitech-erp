import { useState } from 'react';
import { createPortal } from 'react-dom';
import { Download, File, FileImage, FileSpreadsheet, FileText, X } from 'lucide-react';

const blankCell = '\u00a0';

function formatExpenseDate(value) {
  const date = String(value || '').slice(0, 10);
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
  if (!match) return blankCell;
  return new Intl.DateTimeFormat('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
}

function formatExpenseAmount(value) {
  const amount = Number(value);
  return Number.isFinite(amount)
    ? new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount)
    : blankCell;
}

function formatAccountLabel(value) {
  return String(value || '').replace(/^\s*\d+\s*-\s*/, '').trim();
}

function attachmentKind(name) {
  const extension = String(name || '').split('.').pop().toLowerCase();
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(extension)) {
    return { label: 'IMG', tone: 'image', image: true, pdf: false, Icon: FileImage };
  }
  if (extension === 'pdf') return { label: 'PDF', tone: 'pdf', image: false, pdf: true, Icon: FileText };
  if (['xls', 'xlsx', 'csv'].includes(extension)) {
    return { label: 'XLS', tone: 'sheet', image: false, pdf: false, Icon: FileSpreadsheet };
  }
  if (['doc', 'docx'].includes(extension)) {
    return { label: 'DOC', tone: 'doc', image: false, pdf: false, Icon: FileText };
  }
  return { label: 'FILE', tone: 'file', image: false, pdf: false, Icon: File };
}

function ExpenseAttachment({ name, url }) {
  const [previewOpen, setPreviewOpen] = useState(false);
  const kind = attachmentKind(name);
  const Icon = kind.Icon;

  return (
    <>
      <article className="exp-detail-document-card">
        <button
          type="button"
          className="exp-detail-document-preview"
          onClick={() => setPreviewOpen(true)}
          aria-label={`Preview ${name}`}
        >
          {kind.image ? (
            <img src={url} alt="" loading="lazy" />
          ) : kind.pdf ? (
            <iframe
              src={`${url}#page=1&view=FitH&toolbar=0&navpanes=0`}
              title={`${name} preview`}
              loading="lazy"
              tabIndex={-1}
            />
          ) : (
            <span className={`exp-detail-document-type exp-detail-document-type--${kind.tone}`}>
              {kind.label}
            </span>
          )}
        </button>
        <div className="exp-detail-document-footer">
          <span className={`exp-detail-document-badge exp-detail-document-badge--${kind.tone}`} aria-hidden="true">
            {kind.label}
          </span>
          <Icon size={16} aria-hidden="true" />
          <span className="exp-detail-document-name" title={name}>{name}</span>
          <a
            href={url}
            download={name}
            className="exp-detail-document-download"
            aria-label={`Download ${name}`}
            title="Download"
          >
            <Download size={15} aria-hidden="true" />
          </a>
        </div>
      </article>
      {previewOpen ? createPortal(
        <div
          className="exp-detail-document-modal"
          role="dialog"
          aria-modal="true"
          aria-label={`Preview ${name}`}
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) setPreviewOpen(false);
          }}
        >
          <div className="exp-detail-document-modal-panel">
            <div className="exp-detail-document-modal-head">
              <span>{name}</span>
              <button type="button" onClick={() => setPreviewOpen(false)} aria-label="Close preview">
                <X size={18} aria-hidden="true" />
                Close
              </button>
            </div>
            <div className="exp-detail-document-modal-body">
              {kind.image ? (
                <img src={url} alt={name} />
              ) : (
                <iframe src={url} title={name} />
              )}
            </div>
          </div>
        </div>,
        document.body,
      ) : null}
    </>
  );
}

export default function ExpenseDetailTable({ expense }) {
  const supportingDocuments = expense?.attachment_name || expense?.attachment_url ? '1' : '0';
  const attachmentUrl = expense?.attachment_url || '';
  const attachmentName = expense?.attachment_name || 'Supporting document';
  const budgetType = expense?.main_account_name && expense.main_account_name !== '-'
    ? formatAccountLabel(expense.main_account_name) || blankCell
    : blankCell;
  const category = expense?.sub_account_name && expense.sub_account_name !== '-'
    ? formatAccountLabel(expense.sub_account_name) || blankCell
    : (expense?.category_name && expense.category_name !== '-'
      ? formatAccountLabel(expense.category_name) || blankCell
      : blankCell);

  return (
    <div className="exp-desk-expense-sheet">
      <div className="exp-desk-expense-table-wrap">
        <table className="exp-desk-expense-table exp-desk-expense-summary" aria-label="Expense summary">
          <colgroup>
            <col />
            <col />
            <col />
            <col />
          </colgroup>
          <tbody>
            <tr>
              <td>Voucher NO. :</td>
              <td>{expense?.expense_number || blankCell}</td>
              <td>Date:</td>
              <td>{formatExpenseDate(expense?.date)}</td>
            </tr>
            <tr>
              <td>Payment Account:</td>
              <td>{expense?.source_account_name && expense.source_account_name !== '-'
                ? formatAccountLabel(expense.source_account_name) || blankCell
                : blankCell}</td>
              <td>Prepared By:</td>
              <td>{expense?.created_by_name || blankCell}</td>
            </tr>
            <tr>
              <td>Description:</td>
              <td>{expense?.description || expense?.payee || blankCell}</td>
              <td>Supporting Documents (Qty.)</td>
              <td>{supportingDocuments}</td>
            </tr>
            <tr>
              <td>Currency:</td>
              <td>{expense?.currency_display || expense?.currency_code || blankCell}</td>
              <td>Amount:</td>
              <td className="exp-desk-expense-amount">{formatExpenseAmount(expense?.amount)}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="exp-desk-expense-table-wrap">
        <table className="exp-desk-expense-table exp-desk-expense-breakdown" aria-label="Expense breakdown">
          <colgroup>
            <col />
            <col />
            <col />
          </colgroup>
          <thead>
            <tr>
              {['Payment Type', 'Budget Type', 'Category'].map((heading) => (
                <th key={heading}>{heading}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>{expense?.payment_type_label || blankCell}</td>
              <td>{budgetType}</td>
              <td>{category}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="exp-desk-expense-table-wrap">
        <table className="exp-desk-expense-table exp-desk-expense-approvals" aria-label="Expense approvals">
          <colgroup>
            <col />
            <col />
            <col />
            <col />
          </colgroup>
          <tbody>
            <tr>
              <td>Applicant</td>
              <td>
                <div className="exp-detail-applicant">
                  <span>{expense?.created_by_name || blankCell}</span>
                  {expense?.created_by_signature_url ? (
                    <img
                      src={expense.created_by_signature_url}
                      alt="Applicant signature"
                      className="exp-detail-applicant-signature"
                      onError={(event) => {
                        event.currentTarget.style.display = 'none';
                      }}
                    />
                  ) : null}
                </div>
              </td>
              <td>Check</td>
              <td>{expense?.approved_by_name || blankCell}</td>
            </tr>
          </tbody>
        </table>
      </div>
      {attachmentUrl ? (
        <section className="exp-detail-attachments" aria-label="Supporting documents">
          <h2>Supporting Documents</h2>
          <div className="exp-detail-document-list" role="list">
            <ExpenseAttachment name={attachmentName} url={attachmentUrl} />
          </div>
        </section>
      ) : null}
    </div>
  );
}
