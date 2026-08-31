<?php
/**
 * Register Revenue modal - posts to revenue_process.php with action=register_entry (revenue_entries.id).
 * Expects $financialAccounts (array) from the parent page.
 */
if (!isset($financialAccounts) || !is_array($financialAccounts)) {
    $financialAccounts = [];
}
?>
<style>
/* Bottom sheet on phones/small tablets (matches Revenue mobile breakpoint) */
@media (max-width: 767.98px) {
    /* Allow light page-level scroll on the modal root (helps iOS when inner scroll sticks) */
    .reg-inv-modal.modal {
        overflow-x: hidden !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }
    .reg-inv-modal.modal.fade .modal-dialog {
        transform: translate3d(0, 100%, 0);
        transition: transform 0.35s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .reg-inv-modal.modal.show .modal-dialog {
        transform: translate3d(0, 0, 0) !important;
    }
    .reg-inv-modal .modal-dialog.modal-dialog-centered {
        display: flex;
        align-items: flex-end;
        justify-content: stretch;
        min-height: 0 !important;
    }
    .reg-inv-modal .modal-dialog {
        position: fixed !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        top: auto !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
    }
    .reg-inv-modal .modal-content {
        border-radius: 1.125rem 1.125rem 0 0 !important;
        max-height: min(92vh, 100dvh - env(safe-area-inset-top, 0px));
        display: flex !important;
        flex-direction: column;
        overflow: hidden;
        min-height: 0;
        box-shadow: 0 -8px 40px rgba(15, 23, 42, 0.18) !important;
    }
    /* Form sits between .modal-content and header/body/footer - must be the flex column for scroll to work */
    .reg-inv-modal .modal-content > form {
        display: flex !important;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        max-height: 100%;
        overflow: hidden;
    }
    .reg-inv-modal .modal-header {
        flex-shrink: 0;
        padding: 0.875rem 1rem;
    }
    .reg-inv-modal .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        touch-action: pan-y;
        padding-bottom: 0.75rem;
    }
    .reg-inv-modal .modal-footer {
        flex-shrink: 0;
        margin-top: auto;
        padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom, 0px)) !important;
        background: #fff !important;
        border-top: 1px solid #e5e7eb !important;
    }
    .reg-inv-modal .reg-inv-drop {
        padding: 1rem 0.75rem;
    }
}
</style>
<div class="modal fade reg-inv-modal" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="revenue_process.php" enctype="multipart/form-data" id="registerRevenueForm" novalidate>
                <input type="hidden" name="action" value="register_entry">
                <input type="hidden" name="entry_id" id="reg_entry_id" value="">
                <div class="modal-header">
                    <h2 class="modal-title" id="registerModalLabel">Register Revenue Invoice</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="reg_info" class="alert alert-primary border-0 mb-3 small" style="background:#eff6ff;border:1px solid #bfdbfe!important;color:#1e3a5f;line-height:1.5;"></div>
                    <div class="mb-3">
                        <label class="form-label small text-uppercase text-gray-500 fw-bold">Payment type</label>
                        <select name="payment_type" id="reg_payment_type" class="form-select" required>
                            <option value="">Select</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank">Bank transfer</option>
                            <option value="Account Receivable">Account receivable (record later)</option>
                        </select>
                    </div>
                    <div class="mb-3" id="reg_account_wrap">
                        <label class="form-label small text-uppercase text-gray-500 fw-bold">Deposit account</label>
                        <select name="account_id" id="reg_account_id" class="form-select">
                            <option value="">Select account</option>
                            <?php foreach ($financialAccounts as $acc): ?>
                                <?php
                                $accBal = (float) ($acc['current_balance'] ?? 0);
                                $accCur = (string) ($acc['currency'] ?? 'TZS');
                                ?>
                                <option
                                    value="<?= (int) ($acc['id'] ?? 0) ?>"
                                    data-balance="<?= htmlspecialchars((string) $accBal, ENT_QUOTES, 'UTF-8') ?>"
                                    data-currency="<?= htmlspecialchars($accCur, ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <?= htmlspecialchars((string) ($acc['name'] ?? '')) ?> (<?= htmlspecialchars((string) ($acc['type'] ?? '')) ?>) - Bal <?= htmlspecialchars($accCur) ?> <?= number_format($accBal, 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="reg_balance_hint" class="alert alert-light border small py-2 px-3 mt-2 mb-0 d-none" role="status">
                            <i class="fas fa-coins text-secondary me-2"></i><span id="reg_balance_text"></span>
                        </div>
                        <div class="form-text">Choose the account to credit when cash or bank is received.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-uppercase text-gray-500 fw-bold">Amount received / paid (<span id="reg_currency_lbl">TZS</span>)</label>
                        <input type="number" name="amount_received" id="reg_amount" class="form-control fw-bold" step="0.01" min="0.01" required placeholder="">
                        <div class="form-text" id="reg_amount_hint"></div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small text-uppercase text-gray-500 fw-bold">Upload receipt / proof of payment</label>
                    </div>
                    <label class="reg-inv-drop d-block position-relative mb-0" id="reg_drop">
                        <input type="file" name="receipt" id="reg_receipt" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                        <i class="fas fa-cloud-upload-alt fa-2x text-gray-400 mb-2"></i>
                        <div class="fw-semibold text-gray-800">Click to browse or drag &amp; drop</div>
                        <div class="small text-gray-500 mt-1">Supported: JPG, PNG, PDF (max 5MB)</div>
                        <div class="small text-primary mt-2 fw-semibold d-none" id="reg_file_name"></div>
                    </label>
                </div>
                <div class="modal-footer border-0 pt-0 flex-column">
                    <button type="submit" class="reg-inv-btn-complete d-inline-flex align-items-center justify-content-center gap-2">
                        <i class="fas fa-pen-fancy"></i> Complete registration
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var regModalEl = document.getElementById('registerModal');
    var form = document.getElementById('registerRevenueForm');
    if (!regModalEl || !form) return;

    var payType = document.getElementById('reg_payment_type');
    var accSel = document.getElementById('reg_account_id');
    var accWrap = document.getElementById('reg_account_wrap');
    var amtIn = document.getElementById('reg_amount');
    var curLbl = document.getElementById('reg_currency_lbl');
    var fileIn = document.getElementById('reg_receipt');
    var drop = document.getElementById('reg_drop');
    var fileName = document.getElementById('reg_file_name');
    var balHint = document.getElementById('reg_balance_hint');
    var balText = document.getElementById('reg_balance_text');
    var regInvMaxRemain = 0;
    var regInvSuggestedPay = 0;
    var registerModalBs = null;

    function fmt(n) {
        var x = parseFloat(n);
        if (isNaN(x)) return '0.00';
        return x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function mapModeToSelect(mode) {
        if (!mode || !payType) return;
        var m = String(mode).trim();
        var opts = { 'cash': 'Cash', 'bank': 'Bank', 'account receivable': 'Account Receivable' };
        var lower = m.toLowerCase();
        if (opts[lower]) { payType.value = opts[lower]; return; }
        for (var i = 0; i < payType.options.length; i++) {
            if (payType.options[i].value === m) { payType.value = m; return; }
        }
    }

    function updateDepositBalanceHint() {
        if (!balHint || !balText) return;
        var v = payType.value;
        if (v !== 'Cash' && v !== 'Bank') {
            balHint.classList.add('d-none');
            balText.textContent = '';
            return;
        }
        var opt = accSel.options[accSel.selectedIndex];
        if (!opt || !opt.value) {
            balHint.classList.add('d-none');
            balText.textContent = '';
            return;
        }
        var b = parseFloat(opt.getAttribute('data-balance') || '0');
        var cur = opt.getAttribute('data-currency') || 'TZS';
        var msg = 'Current balance on this account: ' + cur + ' ' + fmt(b) + ' (before this payment).';
        var payAmt = parseFloat(String(amtIn.value || '0').replace(/,/g, ''));
        if (!isNaN(payAmt) && payAmt > 0) {
            msg += ' After recording this payment: ' + cur + ' ' + fmt(b + payAmt) + '.';
        }
        balText.textContent = msg;
        balHint.classList.remove('d-none');
    }

    function syncAccountRequired() {
        var v = payType.value;
        if (v === 'Cash' || v === 'Bank') {
            accSel.setAttribute('required', 'required');
            accWrap.style.opacity = '1';
        } else {
            accSel.removeAttribute('required');
            accSel.value = '';
            accWrap.style.opacity = (v === 'Account Receivable') ? '0.65' : '1';
        }
        updateDepositBalanceHint();
    }

    payType.addEventListener('change', syncAccountRequired);
    accSel.addEventListener('change', updateDepositBalanceHint);
    if (amtIn) {
        amtIn.addEventListener('input', updateDepositBalanceHint);
        amtIn.addEventListener('blur', function () {
            var v = String(amtIn.value || '').trim();
            if (v === '' && regInvSuggestedPay > 0) {
                amtIn.value = String(regInvSuggestedPay);
                updateDepositBalanceHint();
            }
        });
    }

    if (drop && fileIn) {
        drop.addEventListener('click', function (ev) {
            if (ev.target !== fileIn) fileIn.click();
        });
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                drop.classList.add('is-drag');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                drop.classList.remove('is-drag');
            });
        });
        drop.addEventListener('drop', function (e) {
            var f = e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) {
                fileIn.files = e.dataTransfer.files;
                fileName.textContent = f.name;
                fileName.classList.remove('d-none');
            }
        });
        fileIn.addEventListener('change', function () {
            var f = fileIn.files && fileIn.files[0];
            if (f) {
                fileName.textContent = f.name;
                fileName.classList.remove('d-none');
            } else {
                fileName.classList.add('d-none');
                fileName.textContent = '';
            }
        });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    window.openRegisterModal = function (entryId, customer, total, paid, mode, currency, invoiceNo) {
        entryId = parseInt(entryId, 10);
        if (!entryId) return;
        currency = currency || 'TZS';
        invoiceNo = invoiceNo || '';
        customer = customer || 'Customer';
        total = Number(total) || 0;
        paid = Number(paid) || 0;
        var balance = Math.max(0, Math.round((total - paid) * 100) / 100);

        document.getElementById('reg_entry_id').value = String(entryId);
        var info = document.getElementById('reg_info');
        var invPart = invoiceNo ? '<div class="mb-1"><strong>Invoice:</strong> ' + escHtml(invoiceNo) + '</div>' : '';
        info.innerHTML = invPart +
            'Registering invoice for <strong>' + escHtml(customer) + '</strong>.<br>' +
            'Total: <strong>' + escHtml(currency) + ' ' + fmt(total) + '</strong> | ' +
            'Already Paid: <strong>' + escHtml(currency) + ' ' + fmt(paid) + '</strong> | ' +
            'Remaining: <strong>' + escHtml(currency) + ' ' + fmt(balance) + '</strong>';

        curLbl.textContent = currency;
        regInvMaxRemain = balance > 0.001 ? balance : (total > 0.001 ? total : 0);
        regInvSuggestedPay = regInvMaxRemain;
        amtIn.value = regInvSuggestedPay > 0 ? String(regInvSuggestedPay) : '';
        amtIn.setAttribute('placeholder', regInvSuggestedPay > 0 ? String(regInvSuggestedPay) : '0.00');
        var hint = document.getElementById('reg_amount_hint');
        if (hint) {
            hint.textContent = regInvSuggestedPay > 0
                ? 'Suggested: ' + currency + ' ' + fmt(regInvSuggestedPay) + ' (editable for partial payment). Max: ' + currency + ' ' + fmt(regInvMaxRemain) + '.'
                : 'Enter amount received.';
        }
        if (regInvMaxRemain > 0) amtIn.setAttribute('max', String(regInvMaxRemain));
        else amtIn.removeAttribute('max');

        payType.value = '';
        if (mode) mapModeToSelect(mode);
        accSel.value = '';
        fileIn.value = '';
        fileName.classList.add('d-none');
        fileName.textContent = '';
        syncAccountRequired();
        updateDepositBalanceHint();

        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            if (!registerModalBs) registerModalBs = new bootstrap.Modal(regModalEl);
            registerModalBs.show();
        } else {
            regModalEl.style.display = 'block';
            regModalEl.classList.add('show');
        }
    };

    form.addEventListener('submit', function (ev) {
        var v = payType.value;
        if ((v === 'Cash' || v === 'Bank') && !accSel.value) {
            ev.preventDefault();
            alert('Select a deposit account for Cash or Bank payments.');
            return false;
        }
        var entered = parseFloat(String(amtIn.value || '').replace(/,/g, ''));
        if ((isNaN(entered) || entered <= 0) && regInvSuggestedPay > 0) {
            entered = regInvSuggestedPay;
            amtIn.value = String(regInvSuggestedPay);
        }
        if (isNaN(entered) || entered <= 0) {
            ev.preventDefault();
            alert('Enter a valid amount greater than zero.');
            return false;
        }
        if (regInvMaxRemain > 0 && entered > regInvMaxRemain + 0.02) {
            ev.preventDefault();
            alert('Amount cannot exceed the remaining balance (' + fmt(regInvMaxRemain) + ').');
            return false;
        }
    });
})();
</script>
