// Voucher form functionality (Version 10 cache-bust)
console.log('[voucher-v5.v10.js] Loaded version 10');
let itemCount = 0;

function ensureSingleHeader() {
	try {
		const container = document.getElementById('voucher-items-container');
		if (!container) return;
		if (document.getElementById('voucher-items-header')) return;
		const header = document.createElement('div');
		header.id = 'voucher-items-header';
		header.className = 'voucher-items-header';
		header.innerHTML = '<div>Payment Type</div><div>Budget Type</div><div>Name</div><div>Amount</div><div>Item Description</div><div></div>';
		container.parentElement.insertBefore(header, container);
	} catch (e) { console.warn('ensureSingleHeader failed', e); }
}

document.addEventListener('DOMContentLoaded', function () {
	try {
		const nukeLocks = () => document.querySelectorAll('.lock-icon').forEach(el => el.remove());
		nukeLocks();
		const mo = new MutationObserver(() => nukeLocks());
		mo.observe(document.body, { childList: true, subtree: true });
	} catch (_) { }

	const payeeInput = document.getElementById('payee_name');
	if (payeeInput) {
		payeeInput.addEventListener('input', function () { updatePayeeNames(this.value); });
		if (payeeInput.value) { updatePayeeNames(payeeInput.value); }
	}
	ensureSingleHeader();
	// const restored = loadDraft();
	// if (!restored) { addVoucherItem(); }
	if (!window.__VF_SUPPRESS_AUTO_ITEM) {
		addVoucherItem();
	}

	const currencySelect = document.getElementById('currency');
	if (currencySelect) {
		currencySelect.addEventListener('change', updateCurrencySymbol);
		updateCurrencySymbol();
	}
	calculateTotal();
	const form = document.getElementById('voucherForm');
	/*
	if (form) {
		const scheduleAutoSave = debounce(function(){ saveDraft(true); }, 600);
		form.addEventListener('input', scheduleAutoSave, true);
		form.addEventListener('change', scheduleAutoSave, true);
		window.addEventListener('beforeunload', function(){ try { saveDraft(true); } catch(_){} });
	}
	*/
});

function addVoucherItem(data = null) {
	itemCount++;
	const container = document.getElementById('voucher-items-container');
	if (!container) { console.warn('voucher-items-container missing'); return; }

	const itemDiv = document.createElement('div');
	itemDiv.className = 'voucher-item';
	itemDiv.id = 'item-' + itemCount;

	const allSelects = document.querySelectorAll('select[name="payment_type[]"]');
	const isFirstItem = (allSelects.length === 0);
	const firstSelect = allSelects[0];
	const syncedType = firstSelect ? firstSelect.value : (data ? data.payment_type : 'Cash Payment');

	const currentPayeeName = document.getElementById('payee_name')?.value ||
		document.getElementById('payee_name_hidden')?.value ||
		(document.getElementById('payee_select')?.selectedOptions?.[0]?.getAttribute('data-name')) ||
		'';
	const defaultBudget = (data && data.budget_type) || 'Operational Expenses';

	itemDiv.innerHTML = `
		<div class="form-group no-label">
			<select aria-label="Payment Type" name="payment_type[]" required onchange="syncPaymentTypes(this.value)">
				<option value="">Select Type</option>
				<option value="Bank Transfer">Bank Transfer</option>
				<option value="Cash Payment">Cash Payment</option>
				<option value="Cheque">Cheque</option>
				<option value="Mobile Payment">Mobile Payment</option>
			</select>
		</div>
		<div class="form-group no-label">
			<select aria-label="Budget Type" name="budget_type[]" required>
				<option value="">Select Budget</option>
				<option value="Operational Expenses">Operational Expenses</option>
				<option value="Procurement &amp; Supplies">Procurement &amp; Supplies</option>
				<option value="Employee Costs">Employee Costs</option>
				<option value="Sales &amp; Marketing">Sales &amp; Marketing</option>
				<option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option>
				<option value="Administration &amp; Management">Administration &amp; Management</option>
				<option value="Projects &amp; Capital Expenditure (CAPEX)">Projects &amp; Capital Expenditure (CAPEX)</option>
				<option value="Financial Obligations">Financial Obligations</option>
				<option value="Tax &amp; Compliance">Tax &amp; Compliance</option>
				<option value="Others / Miscellaneous">Others / Miscellaneous</option>
			</select>
		</div>
		<div class="form-group no-label">
			<input aria-label="Name" type="text" name="name[]" required readonly value="${data ? data.name : currentPayeeName}">
		</div>
		<div class="form-group no-label">
			<input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" required value="${data ? data.amount : ''}" oninput="calculateTotal()" placeholder="0.00">
		</div>
		<div class="form-group no-label">
			<input aria-label="Item Description" type="text" name="item_description[]" placeholder="e.g. Masks, Reflector" value="${data ? (data.description || data.item_description || '') : ''}">
		</div>
		<button type="button" class="icon-btn icon-danger remove-item" title="Delete item" onclick="removeVoucherItem('item-${itemCount}')" style="justify-self:end; align-self:center;">
			<svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
				<polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
		</button>`;

	container.appendChild(itemDiv);

	// Populate values for the newly added select boxes
	const newSelects = itemDiv.querySelectorAll('select');
	if (data) {
		newSelects[0].value = data.payment_type;
		newSelects[1].value = data.budget_type;
	} else {
		newSelects[0].value = syncedType;
		newSelects[1].value = 'Operational Expenses';
	}

	// Always refresh the lock state to ensure even newly added items are synced
	setTimeout(() => {
		const container = document.getElementById('voucher-items-container');
		if (container) {
			const firstSelect = container.querySelector('select[name="payment_type[]"]');
			syncPaymentTypes(firstSelect ? firstSelect.value : '');
		}
	}, 10);

	calculateTotal();
}

function updatePayeeNames(payeeName) {
	if (typeof payeeName === 'undefined' || payeeName === null || payeeName === '') {
		payeeName = document.getElementById('payee_name')?.value ||
			document.getElementById('payee_name_hidden')?.value ||
			(document.getElementById('payee_select')?.selectedOptions?.[0]?.getAttribute('data-name')) ||
			'';
	}
	const nameInputs = document.querySelectorAll('input[name="name[]"]');
	nameInputs.forEach(input => { input.value = payeeName; });
}

/**
 * Ensures all payment type dropdowns are synced to the same value.
 * Targets ONLY selects inside the items container to avoid hidden system fields.
 */
function syncPaymentTypes(selectedType) {
	const container = document.getElementById('voucher-items-container');
	if (!container) return;

	const selects = Array.from(container.querySelectorAll('select[name="payment_type[]"]'));
	if (selects.length === 0) return;

	// Use first one's value if none provided
	if (typeof selectedType === 'undefined' || selectedType === null || selectedType === '') {
		selectedType = selects[0].value;
	}

	// Identify the first interactive master (skipping hidden ones if any)
	let masterIdx = -1;
	for (let i = 0; i < selects.length; i++) {
		if (selects[i].offsetParent !== null) { // Visibility check
			masterIdx = i;
			break;
		}
	}
	if (masterIdx === -1) masterIdx = 0; // Fallback to literal first

	selects.forEach((select, idx) => {
		if (typeof selectedType === 'string') {
			select.value = selectedType;
		}

		const isMaster = (idx === masterIdx);
		if (isMaster) {
			select.style.pointerEvents = 'auto'; // Re-enable interaction
			select.style.cursor = 'pointer';
			select.style.backgroundColor = '#ffffff'; // White background
			select.removeAttribute('tabindex');
			select.removeAttribute('disabled');
		} else {
			select.style.pointerEvents = 'none'; // Lock interaction
			select.style.cursor = 'not-allowed';
			select.style.backgroundColor = '#f3f4f6'; // Gray background
			select.setAttribute('tabindex', '-1');
			select.removeAttribute('disabled');
		}

		// Cleanup legacy
		const hiddenInput = select.parentElement.querySelector('input[type="hidden"][name="payment_type[]"]');
		if (hiddenInput) hiddenInput.remove();
		const legacyLock = select.parentElement.querySelector('.lock-icon');
		if (legacyLock) legacyLock.remove();
	});
}

function lockPaymentType(val) { syncPaymentTypes(val); }

function removeVoucherItem(itemId) {
	const item = document.getElementById(itemId);
	if (item) {
		item.remove();
		syncPaymentTypes(); // Re-evaluate which row is interactive
		calculateTotal();
	}
}

function calculateTotal() {
	const amountInputs = document.querySelectorAll('input[name="amount[]"]');
	let total = 0;
	amountInputs.forEach(input => { const value = parseFloat(input.value) || 0; total += value; });
	const totalElement = document.getElementById('total-amount');
	if (totalElement) { totalElement.textContent = total.toFixed(2); }
}

function updateCurrencySymbol() {
	const currencySelect = document.getElementById('currency');
	const currencySymbol = document.getElementById('currency-symbol');
	if (currencySelect && currencySymbol) { currencySymbol.textContent = currencySelect.value; }
}

function validateForm() {
	const requiredFields = document.querySelectorAll('input[required], select[required], textarea[required]');
	let isValid = true; let firstInvalidField = null;
	requiredFields.forEach(field => {
		if (!field.value.trim()) { field.style.borderColor = '#e74c3c'; isValid = false; if (!firstInvalidField) { firstInvalidField = field; field.focus(); field.scrollIntoView({ behavior: 'smooth', block: 'center' }); } }
		else { field.style.borderColor = '#ddd'; }
	});
	const container = document.getElementById('voucher-items-container');
	if (!container || container.children.length === 0) {
		const addItemBtn = document.querySelector('.add-item');
		if (addItemBtn) { addItemBtn.style.animation = 'pulse 0.5s'; setTimeout(() => addItemBtn.style.animation = '', 500); }
		alert('Please add at least one payment item by clicking "Add Item".');
		return false;
	}
	const firstPaymentType = container.querySelector('select[name="payment_type[]"]')?.value;
	if (!firstPaymentType) { alert('Please select a payment type for this voucher.'); return false; }
	const amountInputs = document.querySelectorAll('input[name="amount[]"]');
	let hasValidAmount = false;
	amountInputs.forEach(input => { const value = parseFloat(input.value) || 0; if (value > 0) { hasValidAmount = true; } });
	if (!hasValidAmount) { alert('Please enter at least one valid amount greater than 0.'); return false; }
	if (!isValid && firstInvalidField) { firstInvalidField.focus(); alert('Please fill in all required fields.'); }
	return isValid;
}

function printVoucher() { window.print(); }

function getDraftKey() { return 'voucherDraft:create'; }

function saveDraft(isAuto = false) {
	const form = document.getElementById('voucherForm');
	if (!form) return;
	const data = {};
	['payee_name', 'date_created', 'currency', 'supporting_documents', 'description', 'applicant', 'department_manager', 'checked_by', 'prepared_by', 'general_manager']
		.forEach(name => { const el = form.querySelector('[name="' + name + '"]'); if (el) data[name] = el.value; });
	const getAllVals = (selector) => Array.from(form.querySelectorAll(selector)).map(el => el.value);
	data['payment_type[]'] = getAllVals('select[name="payment_type[]"], input[name="payment_type[]"]');
	data['budget_type[]'] = getAllVals('select[name="budget_type[]"], input[name="budget_type[]"]');
	data['name[]'] = getAllVals('input[name="name[]"]');
	data['amount[]'] = getAllVals('input[name="amount[]"]');
	data['item_description[]'] = getAllVals('input[name="item_description[]"]');

	const payload = { page: 'create', ts: Date.now(), data };
	try { localStorage.setItem(getDraftKey(), JSON.stringify(payload)); } catch (_) { }

	if (!isAuto) {
		const saveMessage = document.createElement('div');
		saveMessage.className = 'success-message';
		saveMessage.textContent = 'Draft saved locally';
		saveMessage.style.position = 'fixed'; saveMessage.style.top = '20px'; saveMessage.style.right = '20px'; saveMessage.style.zIndex = '9999';
		document.body.appendChild(saveMessage);
		setTimeout(() => { saveMessage.remove(); }, 2000);
	}
}

function loadDraft() {
	try {
		const raw = localStorage.getItem(getDraftKey());
		if (!raw) return false;
		const parsed = JSON.parse(raw);
		const data = parsed && parsed.data ? parsed.data : null;
		if (!data) return false;
		Object.keys(data).forEach(key => { if (key.endsWith('[]')) return; const field = document.querySelector(`[name="${key}"]`); if (field) { field.value = data[key]; } });
		const container = document.getElementById('voucher-items-container');
		if (container) { container.innerHTML = ''; }
		const types = Array.isArray(data['payment_type[]']) ? data['payment_type[]'] : (data['payment_type[]'] ? [data['payment_type[]']] : []);
		const budgets = Array.isArray(data['budget_type[]']) ? data['budget_type[]'] : [];
		const names = Array.isArray(data['name[]']) ? data['name[]'] : [];
		const amounts = Array.isArray(data['amount[]']) ? data['amount[]'] : [];
		const descs = Array.isArray(data['item_description[]']) ? data['item_description[]'] : [];
		const maxLen = Math.max(types.length, budgets.length, names.length, amounts.length, descs.length);
		if (maxLen > 0) {
			for (let i = 0; i < maxLen; i++) {
				const itemData = { payment_type: types[i] || '', budget_type: budgets[i] || '', name: names[i] || (document.getElementById('payee_name')?.value || ''), amount: amounts[i] || '', description: descs[i] || '' };
				addVoucherItem(itemData);
			}
			if (types[0]) { lockPaymentType(types[0]); }
		}
		calculateTotal();
		updateCurrencySymbol();
		return true;
	} catch (_) { return false; }
}

function clearDraft() { try { localStorage.removeItem(getDraftKey()); } catch (_) { } }

document.addEventListener('DOMContentLoaded', function () {
	const voucherForm = document.getElementById('voucherForm');
	if (voucherForm) {
		voucherForm.addEventListener('submit', function (event) {
			// Fields are not disabled anymore, so we don't need complex re-enabling logic
			// Just ensuring draft clearing happens
			clearDraft();
		});
		voucherForm.setAttribute('onsubmit', 'return validateForm()');
	}
});

function debounce(fn, wait) { let t; return function () { const ctx = this, args = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(ctx, args); }, wait); }; }

function filterTable() {
	const input = document.getElementById('searchInput');
	const filter = input.value.toLowerCase();
	const table = document.querySelector('.data-table tbody');
	const rows = table.getElementsByTagName('tr');
	for (let i = 0; i < rows.length; i++) {
		const cells = rows[i].getElementsByTagName('td');
		let found = false;
		for (let j = 0; j < cells.length; j++) {
			const cell = cells[j];
			if (cell) {
				const textValue = cell.textContent || cell.innerText;
				if (textValue.toLowerCase().indexOf(filter) > -1) { found = true; break; }
			}
		}
		rows[i].style.display = found ? '' : 'none';
	}
}

function filterByStatus(status) {
	const table = document.querySelector('.data-table tbody');
	const rows = table.getElementsByTagName('tr');
	for (let i = 0; i < rows.length; i++) {
		const statusCell = rows[i].querySelector('.status-badge');
		if (statusCell) {
			const rowStatus = statusCell.textContent.toLowerCase().trim();
			rows[i].style.display = (status === 'all' || rowStatus === status) ? '' : 'none';
		}
	}
}

// --- Instrumentation & resilience layer (v10+) ---
(function () {
	function minimalFallbackAdd() {
		if (typeof window.addVoucherItem === 'function') return;
		window.addVoucherItem = function () {
			try {
				var c = document.getElementById('voucher-items-container'); if (!c) { console.warn('[voucher] container missing'); return; }
				var payee = (document.getElementById('payee_name') || {}).value || '';
				var idx = (c.children.length || 0) + 1;
				var div = document.createElement('div');
				div.className = 'voucher-item no-label';
				div.id = 'item-' + idx;
				div.innerHTML = '\n <div class="form-group no-label"><select aria-label="Payment Type" name="payment_type[]" required onchange="if(typeof syncPaymentTypes===\'function\') syncPaymentTypes(this.value)">\n <option value="">Select Type</option><option value="Bank Transfer">Bank Transfer</option><option value="Cash Payment">Cash Payment</option><option value="Cheque">Cheque</option><option value="Mobile Payment">Mobile Payment</option></select></div>\n <div class="form-group no-label"><select aria-label="Budget Type" name="budget_type[]" required>\n <option value="">Select Budget</option><option value="Operational Expenses">Operational Expenses</option><option value="Procurement &amp; Supplies">Procurement &amp; Supplies</option><option value="Employee Costs">Employee Costs</option><option value="Sales &amp; Marketing">Sales &amp; Marketing</option><option value="Logistics &amp; Delivery">Logistics &amp; Delivery</option><option value="Administration &amp; Management">Administration &amp; Management</option><option value="Projects &amp; Capital Expenditure (CAPEX)">Projects &amp; Capital Expenditure (CAPEX)</option><option value="Financial Obligations">Financial Obligations</option><option value="Tax &amp; Compliance">Tax &amp; Compliance</option><option value="Others / Miscellaneous">Others / Miscellaneous</option></select></div>\n <div class="form-group no-label"><input aria-label="Name" type="text" name="name[]" required readonly value="' + payee + '"></div>\n <div class="form-group no-label"><input aria-label="Amount" type="number" name="amount[]" step="0.01" min="0" required oninput="calculateTotal()" placeholder="0.00"></div>\n <div class="form-group no-label"><input aria-label="Item Description" type="text" name="item_description[]" placeholder="e.g. Masks, Reflector"></div>\n <button type="button" class="icon-btn icon-danger remove-item" title="Delete item" aria-label="Delete item" onclick="removeVoucherItem(\'item-' + idx + '\')" style="justify-self:end;align-self:center;">✕</button>';
				c.appendChild(div);
				if (typeof window.calculateTotal === 'function') { window.calculateTotal(); }
				console.info('[voucher] minimal fallback row appended');
			} catch (e) { console.error('[voucher] minimal fallback failed', e); }
		};
	}
	function bindAddButton() {
		var btn = document.querySelector('.add-item');
		if (!btn) { return; }
		// Avoid double binding marker
		if (btn.dataset.enhanced) { return; }
		btn.dataset.enhanced = '1';
		btn.addEventListener('click', function (ev) {
			if (typeof window.addVoucherItem === 'function') { return; } // inline onclick will fire if defined
			console.warn('[voucher] addVoucherItem undefined at click, installing minimal fallback');
			minimalFallbackAdd();
			try { window.addVoucherItem(); } catch (e) { console.error('[voucher] fallback invocation failed', e); }
		});
	}
	function selfTest() {
		return {
			scriptVersion: 'v10',
			hasAdd: typeof window.addVoucherItem === 'function',
			hasContainer: !!document.getElementById('voucher-items-container'),
			itemCount: document.querySelectorAll('#voucher-items-container .voucher-item').length,
			csp: (function () { try { return document.querySelector('meta[http-equiv="Content-Security-Policy"]')?.content || 'header'; } catch (_) { return 'n/a'; } })()
		};
	}
	window.voucherSelfTest = selfTest;
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindAddButton);
	} else { bindAddButton(); }
	// If after load addVoucherItem still missing (rare race), install fallback automatically
	setTimeout(function () { if (typeof window.addVoucherItem !== 'function') { console.warn('[voucher] addVoucherItem missing post-load; installing minimal fallback'); minimalFallbackAdd(); } }, 400);
})();
