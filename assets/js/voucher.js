// Voucher form functionality
let itemCount = 0;

// Initialize form when document loads
document.addEventListener('DOMContentLoaded', function() {
    // Set up payee name event listener first
    const payeeInput = document.getElementById('payee_name');
    if (payeeInput) {
        // Update name fields when payee name changes
        payeeInput.addEventListener('input', function() {
            updatePayeeNames(this.value);
        });
        
        // Trigger initial update with current payee name value
        if (payeeInput.value) {
            updatePayeeNames(payeeInput.value);
        }
    }
    
    // Restore from local draft first; otherwise add an initial item
    const restored = loadDraft();
    if (!restored) { addVoucherItem(); }
    
    // Update currency symbol when currency changes
    const currencySelect = document.getElementById('currency');
    if (currencySelect) {
        currencySelect.addEventListener('change', updateCurrencySymbol);
        updateCurrencySymbol();
    }
    
    // Calculate total on page load
    calculateTotal();
    // Autosave wiring
    const form = document.getElementById('voucherForm');
    if (form) {
        const scheduleAutoSave = debounce(function(){ saveDraft(true); }, 600);
        form.addEventListener('input', scheduleAutoSave, true);
        form.addEventListener('change', scheduleAutoSave, true);
        window.addEventListener('beforeunload', function(){ try { saveDraft(true); } catch(_){} });
    }
});

function addVoucherItem(data = null) {
    itemCount++;
    const container = document.getElementById('voucher-items-container');
    const payeeName = document.getElementById('payee_name')?.value || '';
    
    const itemDiv = document.createElement('div');
    itemDiv.className = 'voucher-item';
    itemDiv.id = 'item-' + itemCount;
    
    // Get existing payment type if any
    const existingType = document.querySelector('select[name="payment_type[]"]')?.value;
    const isFirstItem = !existingType;
    
    // Make sure we have the latest payee name
    const currentPayeeName = document.getElementById('payee_name')?.value || payeeName;
    
    itemDiv.innerHTML = `
        <div class="form-group">
            <label>Payment Type</label>
            <select name="payment_type[]" required onchange="lockPaymentType(this.value)" ${!isFirstItem ? 'disabled' : ''}>
                <option value="">Select Type</option>
                <option value="Bank Transfer" ${(data && data.payment_type === 'Bank Transfer') || (!isFirstItem && existingType === 'Bank Transfer') ? 'selected' : ''}>Bank Transfer</option>
                <option value="Cash Payment" ${(data && data.payment_type === 'Cash Payment') || (!isFirstItem && existingType === 'Cash Payment') ? 'selected' : ''}>Cash Payment</option>
                <option value="Cheque" ${(data && data.payment_type === 'Cheque') || (!isFirstItem && existingType === 'Cheque') ? 'selected' : ''}>Cheque</option>
                <option value="Mobile Payment" ${(data && data.payment_type === 'Mobile Payment') || (!isFirstItem && existingType === 'Mobile Payment') ? 'selected' : ''}>Mobile Payment</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Budget Type</label>
            <select name="budget_type[]" required onchange="calculateTotal()">
                <option value="">Select Budget</option>
                <option value="Operational Expenses" ${data && data.budget_type === 'Operational Expenses' ? 'selected' : ''}>Operational Expenses</option>
                <option value="Procurement &amp; Supplies" ${data && data.budget_type === 'Procurement &amp; Supplies' ? 'selected' : ''}>Procurement &amp; Supplies</option>
                <option value="Employee Costs" ${data && data.budget_type === 'Employee Costs' ? 'selected' : ''}>Employee Costs</option>
                <option value="Sales &amp; Marketing" ${data && data.budget_type === 'Sales &amp; Marketing' ? 'selected' : ''}>Sales &amp; Marketing</option>
                <option value="Logistics &amp; Delivery" ${data && data.budget_type === 'Logistics &amp; Delivery' ? 'selected' : ''}>Logistics &amp; Delivery</option>
                <option value="Administration &amp; Management" ${data && data.budget_type === 'Administration &amp; Management' ? 'selected' : ''}>Administration &amp; Management</option>
                <option value="Projects &amp; Capital Expenditure (CAPEX)" ${data && data.budget_type === 'Projects &amp; Capital Expenditure (CAPEX)' ? 'selected' : ''}>Projects &amp; Capital Expenditure (CAPEX)</option>
                <option value="Financial Obligations" ${data && data.budget_type === 'Financial Obligations' ? 'selected' : ''}>Financial Obligations</option>
                <option value="Tax &amp; Compliance" ${data && data.budget_type === 'Tax &amp; Compliance' ? 'selected' : ''}>Tax &amp; Compliance</option>
                <option value="Others / Miscellaneous" ${data && data.budget_type === 'Others / Miscellaneous' ? 'selected' : ''}>Others / Miscellaneous</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name[]" required placeholder="e.g. NAFIS" 
                   value="${data ? data.name : currentPayeeName}" onchange="calculateTotal()" readonly>
        </div>
        
        <div class="form-group">
            <label>Amount</label>
            <input type="number" name="amount[]" step="0.01" min="0" required 
                   value="${data ? data.amount : ''}" oninput="calculateTotal()" placeholder="0.00">
        </div>
        
        <div class="form-group">
            <label>Item Description</label>
            <input type="text" name="item_description[]" placeholder="e.g. Masks, Reflector" 
                   value="${data ? data.description : ''}">
        </div>

        <button type="button" class="icon-btn icon-danger remove-item" title="Delete item" aria-label="Delete item" onclick="removeVoucherItem('item-${itemCount}')" style="justify-self:end; align-self:center;">
            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
                <polyline points="3 6 5 6 21 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M10 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M14 11v6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    `;
    
    container.appendChild(itemDiv);
    calculateTotal();
}

// Update all name fields when payee name changes
function updatePayeeNames(payeeName) {
    const nameInputs = document.querySelectorAll('input[name="name[]"]');
    nameInputs.forEach(input => {
        input.value = payeeName;
    });
}

// Lock payment type after first selection and update all items
function lockPaymentType(selectedType) {
    if (!selectedType) return;
    
    const paymentSelects = document.querySelectorAll('select[name="payment_type[]"]');
    paymentSelects.forEach((select, idx) => {
        select.value = selectedType;
        const isFirst = idx === 0;
        select.disabled = !isFirst;
        if (!isFirst) {
            select.style.backgroundColor = '#f8f9fa';
            select.style.cursor = 'not-allowed';
        } else {
            select.style.backgroundColor = '';
            select.style.cursor = '';
        }
        const legacy = select.parentElement.querySelector('.lock-icon');
        if (legacy) legacy.remove();
    });
}

function removeVoucherItem(itemId) {
    const item = document.getElementById(itemId);
    if (item) {
        item.remove();
        calculateTotal();
    }
}

function calculateTotal() {
    const amountInputs = document.querySelectorAll('input[name="amount[]"]');
    let total = 0;
    
    amountInputs.forEach(input => {
        const value = parseFloat(input.value) || 0;
        total += value;
    });
    
    const totalElement = document.getElementById('total-amount');
    if (totalElement) {
        totalElement.textContent = total.toFixed(2);
    }
}

function updateCurrencySymbol() {
    const currencySelect = document.getElementById('currency');
    const currencySymbol = document.getElementById('currency-symbol');
    
    if (currencySelect && currencySymbol) {
        currencySymbol.textContent = currencySelect.value;
    }
}

// Form validation
function validateForm() {
    const requiredFields = document.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;
    let firstInvalidField = null;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.style.borderColor = '#e74c3c';
            isValid = false;
            if (!firstInvalidField) {
                firstInvalidField = field;
                field.focus();
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else {
            field.style.borderColor = '#ddd';
        }
    });
    
    // Check if at least one item exists
    const container = document.getElementById('voucher-items-container');
    if (!container || container.children.length === 0) {
        const addItemBtn = document.querySelector('.add-item');
        if (addItemBtn) {
            addItemBtn.style.animation = 'pulse 0.5s';
            setTimeout(() => addItemBtn.style.animation = '', 500);
        }
        alert('Please add at least one payment item by clicking "Add Item".');
        return false;
    }

    // Check if payment type is selected for first item
    const firstPaymentType = container.querySelector('select[name="payment_type[]"]')?.value;
    if (!firstPaymentType) {
        alert('Please select a payment type for this voucher.');
        return false;
    }
    
    // Validate amounts
    const amountInputs = document.querySelectorAll('input[name="amount[]"]');
    let hasValidAmount = false;
    
    amountInputs.forEach(input => {
        const value = parseFloat(input.value) || 0;
        if (value > 0) {
            hasValidAmount = true;
        }
    });
    
    if (!hasValidAmount) {
        alert('Please enter at least one valid amount greater than 0.');
        return false;
    }
    
    if (!isValid && firstInvalidField) {
        firstInvalidField.focus();
        alert('Please fill in all required fields.');
    }
    
    return isValid;
}

// Print functionality
function printVoucher() {
    window.print();
}

// Auto-save draft functionality (optional enhancement)
function getDraftKey(){ return 'voucherDraft:create'; }

function saveDraft(isAuto = false) {
    const form = document.getElementById('voucherForm');
    if (!form) return;
    const data = {};
    ['payee_name','date_created','currency','supporting_documents','description','applicant','department_manager','checked_by','prepared_by','general_manager']
      .forEach(name => { const el = form.querySelector('[name="'+name+'"]'); if (el) data[name] = el.value; });
    const getAllVals = (selector) => Array.from(form.querySelectorAll(selector)).map(el => el.value);
    data['payment_type[]'] = getAllVals('select[name="payment_type[]"], input[name="payment_type[]"]');
    data['budget_type[]'] = getAllVals('select[name="budget_type[]"], input[name="budget_type[]"]');
    data['name[]'] = getAllVals('input[name="name[]"]');
    data['amount[]'] = getAllVals('input[name="amount[]"]');
    data['item_description[]'] = getAllVals('input[name="item_description[]"]');
    try { localStorage.setItem(getDraftKey(), JSON.stringify({ page:'create', ts:Date.now(), data })); } catch(_){}
    if (!isAuto) {
        const saveMessage = document.createElement('div');
        saveMessage.className = 'success-message';
        saveMessage.textContent = 'Draft saved locally';
        saveMessage.style.position = 'fixed'; saveMessage.style.top = '20px'; saveMessage.style.right = '20px'; saveMessage.style.zIndex = '9999';
        document.body.appendChild(saveMessage);
        setTimeout(() => { saveMessage.remove(); }, 2000);
    }
}

// Load draft functionality
function loadDraft() {
    try {
        const raw = localStorage.getItem(getDraftKey());
        if (!raw) return false;
        const parsed = JSON.parse(raw);
        const data = parsed && parsed.data ? parsed.data : null;
        if (!data) return false;
        Object.keys(data).forEach(key => {
            if (key.endsWith('[]')) return;
            const field = document.querySelector(`[name="${key}"]`);
            if (field) { field.value = data[key]; }
        });
        const container = document.getElementById('voucher-items-container');
        if (container) { container.innerHTML = ''; }
        const types = Array.isArray(data['payment_type[]']) ? data['payment_type[]'] : (data['payment_type[]'] ? [data['payment_type[]']] : []);
        const budgets = Array.isArray(data['budget_type[]']) ? data['budget_type[]'] : [];
        const names = Array.isArray(data['name[]']) ? data['name[]'] : [];
        const amounts = Array.isArray(data['amount[]']) ? data['amount[]'] : [];
        const descs = Array.isArray(data['item_description[]']) ? data['item_description[]'] : [];
        const maxLen = Math.max(types.length, budgets.length, names.length, amounts.length, descs.length);
        if (maxLen > 0) {
            for (let i=0; i<maxLen; i++) {
                const itemData = {
                    payment_type: types[i] || '',
                    budget_type: budgets[i] || '',
                    name: names[i] || (document.getElementById('payee_name')?.value || ''),
                    amount: amounts[i] || '',
                    description: descs[i] || ''
                };
                addVoucherItem(itemData);
            }
            if (types[0]) { lockPaymentType(types[0]); }
        }
        calculateTotal();
        updateCurrencySymbol();
        return true;
    } catch(_) { return false; }
}

// Clear draft
function clearDraft() { try { localStorage.removeItem(getDraftKey()); } catch(_){} }

// Event listeners for form submission
document.addEventListener('DOMContentLoaded', function() {
    const voucherForm = document.getElementById('voucherForm');
    if (voucherForm) {
        voucherForm.addEventListener('submit', function(event) {
            // Re-enable disabled payment type selects before submitting
            const paymentSelects = document.querySelectorAll('select[name="payment_type[]"]');
            paymentSelects.forEach(select => {
                select.disabled = false;
            });

            // Clear draft on successful submission
            clearDraft();
        });

        // Add the validation check to the form's onsubmit attribute for better compatibility
        voucherForm.setAttribute('onsubmit', 'return validateForm()');
    }
});

// Debounce helper
function debounce(fn, wait){ let t; return function(){ const ctx=this, args=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx,args); }, wait); }; }

// Search and filter functionality for tables
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
                if (textValue.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
        }
        
        rows[i].style.display = found ? '' : 'none';
    }
}

// Status filter
function filterByStatus(status) {
    const table = document.querySelector('.data-table tbody');
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const statusCell = rows[i].querySelector('.status-badge');
        if (statusCell) {
            const rowStatus = statusCell.textContent.toLowerCase().trim();
            if (status === 'all' || rowStatus === status) {
                rows[i].style.display = '';
            } else {
                rows[i].style.display = 'none';
            }
        }
    }
}