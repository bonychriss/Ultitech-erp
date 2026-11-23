<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Trial Balance Smoke Test</title>
  <style>
    :root{font-family:Segoe UI,Arial,sans-serif;color:#172033;background:#f3f5f8}
    *{box-sizing:border-box}
    body{margin:0;padding:24px}
    main{max-width:1180px;margin:0 auto}
    h1{margin:0 0 6px;font-size:23px}
    p{margin:0;color:#667085}
    .toolbar{display:flex;align-items:center;gap:12px;margin:18px 0 12px}
    button{border:0;border-radius:6px;padding:10px 16px;background:#2563eb;color:#fff;font-size:14px;font-weight:650;cursor:pointer}
    button:disabled{opacity:.6;cursor:wait}
    #status{font-size:13px;color:#667085}
    #status[data-state="pass"]{color:#16803c}
    #status[data-state="fail"]{color:#b42318}
    #results{display:grid;gap:6px;margin:0 0 14px;padding:0;list-style:none}
    #results:empty{display:none}
    #results li{padding:9px 12px;border:1px solid #dfe5ec;border-radius:5px;background:#fff;font-size:13px}
    #results li.pass{border-color:#b7e3c2}
    #results li.fail{border-color:#f0b8b8;color:#a82020}
    .accuracy{margin:16px 0;background:#fff;border:1px solid #dfe5ec;border-radius:6px;overflow:hidden}
    .accuracy h2{margin:0;padding:12px 14px;border-bottom:1px solid #e8edf2;font-size:16px}
    .accuracy-note{padding:9px 14px;color:#667085;font-size:12px;border-bottom:1px solid #e8edf2}
    .accuracy-scroll{max-height:420px;overflow:auto}
    .accuracy table{width:100%;border-collapse:collapse;font-size:12px}
    .accuracy th,.accuracy td{padding:8px 10px;border-bottom:1px solid #edf0f3;text-align:right;white-space:nowrap}
    .accuracy th:first-child,.accuracy td:first-child{text-align:left}
    .accuracy th{position:sticky;top:0;background:#f8fafc;color:#475467}
    .accuracy td.pass{color:#16803c}
    .accuracy td.fail{color:#b42318;font-weight:650}
    iframe{display:block;width:100%;height:72vh;min-height:480px;border:1px solid #dfe5ec;border-radius:6px;background:#fff}
    @media(max-width:640px){body{padding:14px}iframe{height:70vh;min-height:400px}}
  </style>
</head>
<body>
<main>
  <h1>Trial Balance verification</h1>
  <p>Checks whether account balances calculate correctly from opening balances and period movements, and whether the report totals reconcile.</p>
  <div class="toolbar">
    <button id="runTest" type="button">Run verification</button>
    <span id="status" role="status" aria-live="polite">Ready</span>
  </div>
  <ul id="results" aria-label="Verification results"></ul>
  <section class="accuracy" id="accuracy" hidden>
    <h2>Account balance accuracy</h2>
    <div class="accuracy-note" id="accuracySummary"></div>
    <div class="accuracy-scroll">
      <table>
        <thead><tr><th>Account</th><th>Opening</th><th>Debits</th><th>Credits</th><th>Calculated closing</th><th>Report closing</th><th>Check</th></tr></thead>
        <tbody id="accuracyRows"></tbody>
      </table>
    </div>
  </section>
  <iframe id="report" title="Trial Balance report preview" hidden></iframe>
</main>
<script>
(function () {
  var button = document.getElementById('runTest');
  var status = document.getElementById('status');
  var results = document.getElementById('results');
  var accuracy = document.getElementById('accuracy');
  var accuracySummary = document.getElementById('accuracySummary');
  var accuracyRows = document.getElementById('accuracyRows');
  var frame = document.getElementById('report');
  var reportUrl = 'accounting/trial-balance?module=accounting';
  var timeout;

  function record(label, passed, detail) {
    var item = document.createElement('li');
    item.className = passed ? 'pass' : 'fail';
    item.textContent = (passed ? 'PASS: ' : 'FAIL: ') + label + (detail ? ' — ' + detail : '');
    results.appendChild(item);
    return passed;
  }

  function toCents(value) {
    var amount = Number(value);
    return Number.isFinite(amount) ? Math.round(amount * 100) : NaN;
  }

  function formatMoney(cents) {
    return (cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function displayCents(value) {
    var cleaned = value.trim().replace(/,/g, '');
    if (!cleaned) return 0;
    var number = Number(cleaned);
    return Number.isFinite(number) ? Math.round(number * 100) : NaN;
  }

  function runAccuracyChecks(doc, accountRows) {
    accuracy.hidden = false;
    accuracyRows.replaceChildren();
    var sums = { opening: 0, debit: 0, credit: 0, closingDebit: 0, closingCredit: 0, closing: 0 };
    var allRowsAccurate = true;

    accountRows.forEach(function (row) {
      var opening = toCents(row.dataset.opening);
      var debit = toCents(row.dataset.periodDebit);
      var credit = toCents(row.dataset.periodCredit);
      var closing = toCents(row.dataset.closing);
      var expected = opening + debit - credit;
      var closingDebit = Math.max(0, closing);
      var closingCredit = Math.max(0, -closing);
      var cells = row.querySelectorAll('td');
      var displayedDebit = displayCents(cells[1] ? cells[1].textContent : '');
      var displayedCredit = displayCents(cells[2] ? cells[2].textContent : '');
      var displayedNet = displayCents(cells[3] ? cells[3].textContent : '');
      var ok = expected === closing
        && closingDebit === toCents(row.dataset.closingDebit)
        && closingCredit === toCents(row.dataset.closingCredit)
        && displayedDebit === closingDebit
        && displayedCredit === closingCredit
        && displayedNet === closing;
      allRowsAccurate = allRowsAccurate && ok;
      sums.opening += opening;
      sums.debit += debit;
      sums.credit += credit;
      sums.closingDebit += closingDebit;
      sums.closingCredit += closingCredit;
      sums.closing += closing;

      var name = row.querySelector('.glr-trial-account');
      var output = document.createElement('tr');
      [
        name ? name.textContent.trim().replace(/\s+/g, ' ') : 'Unknown account',
        formatMoney(opening),
        formatMoney(debit),
        formatMoney(credit),
        formatMoney(expected),
        formatMoney(closing),
        ok ? 'PASS' : 'FAIL'
      ].forEach(function (value, index) {
        var cell = document.createElement('td');
        cell.textContent = value;
        if (index === 6) cell.className = ok ? 'pass' : 'fail';
        output.appendChild(cell);
      });
      accuracyRows.appendChild(output);
    });

    var totalsRow = doc.querySelector('[data-trial-totals]');
    var totalsOk = false;
    if (totalsRow) {
      var expectedVariance = sums.closingDebit - sums.closingCredit;
      var totalCells = totalsRow.querySelectorAll('td');
      var varianceValue = doc.querySelector('.glr-trial-variance-value');
      totalsOk = sums.opening === toCents(totalsRow.dataset.opening)
        && sums.debit === toCents(totalsRow.dataset.periodDebit)
        && sums.credit === toCents(totalsRow.dataset.periodCredit)
        && sums.closingDebit === toCents(totalsRow.dataset.closingDebit)
        && sums.closingCredit === toCents(totalsRow.dataset.closingCredit)
        && expectedVariance === toCents(totalsRow.dataset.variance)
        && displayCents(totalCells[1] ? totalCells[1].textContent : '') === sums.closingDebit
        && displayCents(totalCells[2] ? totalCells[2].textContent : '') === sums.closingCredit
        && displayCents(totalCells[3] ? totalCells[3].textContent : '') === expectedVariance
        && varianceValue !== null
        && displayCents(varianceValue.textContent) === expectedVariance;

      var periodBalanced = sums.debit === sums.credit;
      record('Period debits equal period credits', periodBalanced, 'Debit ' + formatMoney(sums.debit) + '; credit ' + formatMoney(sums.credit));
      record('Closing debit and credit balances reconcile', expectedVariance === 0, 'Difference ' + formatMoney(expectedVariance));
      record('Displayed totals agree with account details', totalsOk);
    } else {
      totalsOk = accountRows.length === 0 && !!doc.querySelector('.glr-trial-empty');
      record('No-activity period has a valid empty state', totalsOk);
    }

    accuracySummary.textContent = accountRows.length
      ? accountRows.length + ' accounts checked. Period debits ' + formatMoney(sums.debit)
        + '; period credits ' + formatMoney(sums.credit)
        + '; closing variance ' + formatMoney(sums.closingDebit - sums.closingCredit) + '.'
      : 'No account activity was returned for the selected period.';
    record('Opening + debits − credits equals closing for every account', allRowsAccurate, accountRows.length + ' account(s) checked');
    return allRowsAccurate && totalsOk && (!totalsRow || sums.debit === sums.credit);
  }

  function runReportChecks() {
    window.clearTimeout(timeout);
    var reportWindow;
    var doc;
    try {
      reportWindow = frame.contentWindow;
      doc = frame.contentDocument;
      if (!doc || !reportWindow || !frame.src) throw new Error('Report document is unavailable.');
      if (/\/login\/?$/.test(reportWindow.location.pathname)) {
        record('Authenticated report access', false, 'Log in to the ERP in this browser, then run the test again.');
        return false;
      }
    } catch (error) {
      record('Report is accessible in this browser session', false, error.message);
      return false;
    }

    record('Trial Balance route loaded', /\/accounting\/trial-balance(?:\.php)?\/?$/.test(reportWindow.location.pathname), reportWindow.location.pathname);

    var main = doc.querySelector('main.glr-trial');
    record('Report layout rendered', !!main);
    if (!main) return false;

    var headers = Array.prototype.map.call(doc.querySelectorAll('.glr-trial-table thead th'), function (th) {
      return th.textContent.trim();
    });
    record('Nominal, Debit, Credit, and Net columns exist', headers.join('|') === 'Nominal|Debit|Credit|Net', headers.join(', ') || 'No table headers found');

    var accountRows = Array.prototype.slice.call(doc.querySelectorAll('[data-account-row]'));
    var hasEmptyState = !!doc.querySelector('.glr-trial-empty');
    record('Account data or valid empty state rendered', accountRows.length > 0 || hasEmptyState, accountRows.length + ' account row(s)');
    record('Total Variance is shown', !!doc.querySelector('.glr-trial-variance-value'));
    record('Date filters and search are available', !!doc.querySelector('input[name="from_date"]') && !!doc.querySelector('input[name="to_date"]') && !!doc.getElementById('glrTrialSearch'));
    var accuracyPassed = runAccuracyChecks(doc, accountRows);

    if (accountRows.length) {
      var firstRow = accountRows[0];
      var expand = firstRow.querySelector('.glr-trial-expand');
      var detail = firstRow.nextElementSibling;
      if (expand && detail) {
        expand.click();
        var opened = expand.getAttribute('aria-expanded') === 'true' && !detail.hidden;
        expand.click();
        var closed = expand.getAttribute('aria-expanded') === 'false' && detail.hidden;
        record('Account detail expands and collapses', opened && closed);
      } else {
        record('Account detail expands and collapses', false, 'Expand control or detail row is missing.');
      }

      var search = doc.getElementById('glrTrialSearch');
      if (search) {
        search.value = 'no-account-should-match-9f31';
        search.dispatchEvent(new reportWindow.Event('input', { bubbles: true }));
        var noMatches = accountRows.every(function (row) { return row.hidden; });
        search.value = '';
        search.dispatchEvent(new reportWindow.Event('input', { bubbles: true }));
        var restored = accountRows.some(function (row) { return !row.hidden; });
        record('Account search filters and restores rows', noMatches && restored);
      }
    }

    var pagination = doc.getElementById('glrTrialPagination');
    var nextPage = doc.getElementById('glrTrialNext');
    if (accountRows.length > 100 && pagination && nextPage) {
      var initialVisible = accountRows.filter(function (row) { return !row.hidden; }).length;
      var pageLabel = doc.getElementById('glrTrialPageLabel');
      var initialPageLabel = pageLabel ? pageLabel.textContent : '';
      nextPage.click();
      var pageChanged = accountRows.some(function (row) { return !row.hidden; })
        && pageLabel && pageLabel.textContent !== initialPageLabel;
      var previousPage = doc.getElementById('glrTrialPrev');
      if (previousPage) previousPage.click();
      record('Pagination controls work', initialVisible === 100 && pageChanged);
    } else {
      record('Pagination controls are present when needed', accountRows.length <= 100 || (!!pagination && !!nextPage));
    }

    var failed = results.querySelector('.fail');
    return !failed && accuracyPassed;
  }

  button.addEventListener('click', function () {
    window.clearTimeout(timeout);
    results.replaceChildren();
    accuracy.hidden = true;
    accuracyRows.replaceChildren();
    button.disabled = true;
    status.dataset.state = '';
    status.textContent = 'Loading Trial Balance…';
    frame.hidden = false;
    frame.onload = function () {
      var passed = runReportChecks();
      status.dataset.state = passed ? 'pass' : 'fail';
      status.textContent = passed ? 'Verification passed' : 'Verification found issues';
      button.disabled = false;
    };
    frame.src = reportUrl + '&_smoke=' + Date.now();
    timeout = window.setTimeout(function () {
      record('Trial Balance response', false, 'Timed out waiting for the report. Check the server and login session.');
      status.dataset.state = 'fail';
      status.textContent = 'Verification timed out';
      button.disabled = false;
    }, 30000);
  });
})();
</script>
</body>
</html>
