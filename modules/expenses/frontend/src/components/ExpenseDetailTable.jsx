const blankCell = '\u00a0';

export default function ExpenseDetailTable({ expense }) {
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
              <td>{blankCell}</td>
            </tr>
            <tr>
              <td>Payee Name:</td>
              <td>{blankCell}</td>
              <td>Prepared By:</td>
              <td>{blankCell}</td>
            </tr>
            <tr>
              <td>Description:</td>
              <td>{blankCell}</td>
              <td>Supporting Documents (Qty.)</td>
              <td>{blankCell}</td>
            </tr>
            <tr>
              <td>Currency:</td>
              <td>{blankCell}</td>
              <td>Amount:</td>
              <td className="exp-desk-expense-amount">{blankCell}</td>
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
            <col />
            <col />
          </colgroup>
          <thead>
            <tr>
              {['Payment Type', 'Budget Type', 'Name', 'Amount', 'Description'].map((heading) => (
                <th key={heading} className={heading === 'Amount' ? 'is-amount' : undefined}>{heading}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            <tr>
              {Array.from({ length: 5 }, (_, index) => (
                <td key={index} className={index === 3 ? 'is-amount' : undefined}>{blankCell}</td>
              ))}
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
              <td>{blankCell}</td>
              <td>Check</td>
              <td>{blankCell}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  );
}
