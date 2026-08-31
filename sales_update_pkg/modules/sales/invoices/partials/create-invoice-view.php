<?php
/** @var array $products */
/** @var array $customers */
/** @var string $catalogueUrl */
/** @var string $customerCatalogueUrl */
/** @var string $predefinedType */
/** @var string|null $error */
/** @var string $nextInvoiceNumber */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Invoice</title>
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = { corePlugins: { preflight: false } };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/sales-mobile.css" rel="stylesheet">

    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html:has(body.page-sales-invoice-create),
        body.page-sales-invoice-create {
            background-color: #f8fafc !important;
        }

        body.page-sales-invoice-create {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #334155;
            font-size: 16px;
            min-height: 100vh;
        }

        body.page-sales-invoice-create header.employee-header {
            background: #f8fafc !important;
            box-shadow: none !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }

        body.page-sales-invoice-create .employee-header .header-content {
            background: transparent;
        }

        body.page-sales-invoice-create #native-sidebar {
            background: var(--sidebar-bg) !important;
            border-right: 1px solid var(--sidebar-border) !important;
            color: var(--sidebar-text) !important;
        }

        body.page-sales-invoice-create .layout-main-wrapper {
            background-color: #f8fafc;
            width: 100%;
            max-width: 100%;
        }

        body.page-sales-invoice-create .layout-main-wrapper > .flex-grow-1 {
            flex: 1 1 0%;
            min-width: 0;
            max-width: none;
            width: 100%;
            background-color: #f8fafc;
        }

        .main-content {
            padding: 0;
            max-width: 100%;
            margin: 0 auto;
            min-height: calc(100vh - 64px);
        }

        body.page-sales-invoice-create .main-content {
            flex: 1 1 auto;
            width: 100% !important;
            max-width: none !important;
            min-width: 0;
            background-color: #f8fafc;
        }

        body.page-sales-invoice-create #react-root {
            width: 100%;
            min-width: 0;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.4s ease-out forwards;
        }

        /* Custom scrollbar for product list */
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>

<body class="page-sales-invoice-create">
    <?php include __DIR__ . '/../../../../includes/header_employee.php'; ?>

    <div class="main-content" id="react-root"></div>

    <script>
        window.APP_DATA = {
            products: <?= json_encode($products) ?>,
            customers: <?= json_encode($customers) ?>,
            currentUserName: <?= json_encode((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System Admin')) ?>,
            error: <?= json_encode($error ?? null) ?>,
            catalogueUrl: <?= json_encode($catalogueUrl) ?>,
            customerCatalogueUrl: <?= json_encode($customerCatalogueUrl) ?>,
            stockUploadsBase: <?= json_encode(app_url('/stock/uploads/products')) ?>,
            isRoadmaster: <?= json_encode(isRoadmaster()) ?>,
            predefinedType: <?= json_encode($predefinedType) ?>,
            nextInvoiceNumber: <?= json_encode($nextInvoiceNumber ?? '') ?>,
            taxMode: <?= json_encode($companyTaxMode ?? 'exclusive') ?>
        };
    </script>


    <script type="text/babel">
        const { useState, useEffect, useMemo } = React;

        function InvoiceCreateApp() {
            const { products, customers, currentUserName, error, catalogueUrl, customerCatalogueUrl, stockUploadsBase, isRoadmaster, predefinedType, nextInvoiceNumber, taxMode } = window.APP_DATA;
            
            const [items, setItems] = useState([
                { id: Date.now(), product_id: '', quantity: 1, unit_price: 0, discount: 0, tax_percent: 18, line_total: 0, description: '', stock_quantity: 0, max_stock: 0, image: '', searchQuery: '', showDropdown: false }
            ]);

            const [formData, setFormData] = useState({
                customer_id: '',
                invoice_date: new Date().toISOString().split('T')[0],
                due_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
                lead_time: '',
                discount_amount: 0,
                tax_percentage: 18,
                shipping_charges: 0,
                order_type: predefinedType || 'spare',
            });

            const saveStateAndGo = (url) => {
                try {
                    localStorage.setItem('invoice_form_draft', JSON.stringify({ formData, items }));
                } catch (e) {}
                window.location.href = url;
            };

            const defaultLineTax = () => (parseFloat(formData.tax_percentage) > 0 ? parseFloat(formData.tax_percentage) : 18);

            useEffect(() => {
                const saved = localStorage.getItem('invoice_form_draft');
                if (saved) {
                    try {
                        const draft = JSON.parse(saved);
                        if (draft.formData) {
                            setFormData(prev => ({ ...prev, ...draft.formData }));
                        }
                        if (draft.items && draft.items.length) {
                            setItems(draft.items);
                        }
                        localStorage.removeItem('invoice_form_draft');
                    } catch (e) {}
                }

                const pickedFromStorage = localStorage.getItem('selected_customer_id');
                const pickedFromQuery = new URLSearchParams(window.location.search).get('customer_id');
                const picked = pickedFromQuery || pickedFromStorage;
                if (picked) {
                    setFormData(prev => ({ ...prev, customer_id: String(picked) }));
                    localStorage.removeItem('selected_customer_id');
                    try {
                        const url = new URL(window.location.href);
                        if (url.searchParams.has('customer_id')) {
                            url.searchParams.delete('customer_id');
                            window.history.replaceState({}, '', url.toString());
                        }
                    } catch (e) {}
                }
            }, []);

            useEffect(() => {
                const key = 'sales_catalogue_guide_invoice_seen';
                if (!localStorage.getItem(key)) {
                    Swal.fire({
                        title: 'New Feature: Catalogue',
                        html: '<div style="font-size:14px; color:#374151; margin-top:6px;">You can select products quickly from the Catalogue.</div>',
                        icon: 'info',
                        showCancelButton: true,
                        confirmButtonText: 'Open Catalogue',
                        cancelButtonText: 'Later',
                    }).then(result => {
                        if (result.isConfirmed) {
                            window.location.href = catalogueUrl;
                        }
                    });
                    localStorage.setItem(key, '1');
                }

                // Load from catalogue if exists
                try {
                    const raw = localStorage.getItem('sales_catalogue_items');
                    if (raw) {
                        const catItems = JSON.parse(raw);
                        if (Array.isArray(catItems) && catItems.length > 0) {
                            const newItems = catItems.map(ci => {
                                const prod = products.find(p => p.id == ci.product_id);
                                if (!prod) return null;
                                const qty = parseFloat(ci.quantity) || 1;
                                const price = parseFloat(prod.selling_price) || 0;
                                return {
                                    id: Date.now() + Math.random(),
                                    product_id: prod.id,
                                    quantity: qty,
                                    unit_price: price,
                                    discount: 0,
                                    tax_percent: 18,
                                    line_total: qty * price,
                                    description: prod.description || '',
                                    stock_quantity: prod.stock_quantity || 0,
                                    max_stock: prod.stock_quantity || 0,
                                    image: prod.main_image ? `${stockUploadsBase}/${prod.id}/thumbnail/${prod.main_image}` : '',
                                    searchQuery: prod.name,
                                    showDropdown: false
                                };
                            }).filter(Boolean);

                            if (newItems.length > 0) {
                                setItems(newItems);
                            }
                        }
                        localStorage.removeItem('sales_catalogue_items');
                    }
                } catch (e) { }
            }, [catalogueUrl, products]);

            const closeAllDropdowns = () => {
                setItems(prev => prev.map(it => ({ ...it, showDropdown: false, focusIndex: -1 })));
            };

            useEffect(() => {
                const onDocClick = (e) => {
                    if (e.target.closest('.product-search-cell') || e.target.closest('.product-search-dropdown')) {
                        return;
                    }
                    setItems(prev => {
                        if (!prev.some(it => it.showDropdown)) {
                            return prev;
                        }
                        return prev.map(it => ({ ...it, showDropdown: false, focusIndex: -1 }));
                    });
                };
                document.addEventListener('click', onDocClick, true);
                return () => document.removeEventListener('click', onDocClick, true);
            }, []);

            const handleItemChange = (index, field, value) => {
                const newItems = [...items];
                const item = { ...newItems[index] };

                if (field === 'product_id') {
                    const prod = products.find(p => p.id == value);
                    if (prod) {
                        item.product_id = prod.id;
                        item.searchQuery = prod.name;
                        item.unit_price = parseFloat(prod.selling_price) || 0;
                        item.description = prod.description || '';
                        item.stock_quantity = prod.stock_quantity || 0;
                        item.max_stock = prod.stock_quantity || 0;
                        item.image = prod.main_image ? `${stockUploadsBase}/${prod.id}/thumbnail/${prod.main_image}` : '';
                        
                        // Roadmaster: Auto-switch to Truck mode if a vehicle is added
                        if (isRoadmaster && prod.item_type === 'vehicle' && formData.order_type !== 'truck') {
                            setFormData(prev => ({ ...prev, order_type: 'truck' }));
                            if (window.Swal) {
                                Swal.fire({
                                    toast: true, position: 'top-end', icon: 'info',
                                    title: 'Vehicle detected. Switching to Truck Invoice.',
                                    showConfirmButton: false, timer: 3000
                                });
                            }
                        }
                    } else {
                        item.product_id = '';
                        item.searchQuery = '';
                        item.unit_price = 0;
                        item.description = '';
                        item.stock_quantity = 0;
                        item.max_stock = 0;
                        item.image = '';
                    }
                } else {
                    item[field] = value;
                    if (field === 'searchQuery') {
                        item.showDropdown = true;
                        item.focusIndex = 0;
                        const el = document.getElementById(`item-search-${index}`);
                        if (el) {
                            const rect = el.getBoundingClientRect();
                            item.dropdownPos = { left: rect.left + window.scrollX, top: rect.top + window.scrollY };
                        }
                    }
                    if (field === 'showDropdown' && value === false) {
                        item.focusIndex = -1;
                    }
                }

                item.line_total = recalcLineTotal(item);
                newItems[index] = item;
                setItems(newItems);
            };

            const selectProduct = (index, product) => {
                if (!product) {
                    return;
                }
                setItems(prev => {
                    const newItems = [...prev];
                    const item = { ...newItems[index] };
                    item.product_id = product.id;
                    item.searchQuery = product.name || '';
                    item.unit_price = parseFloat(product.selling_price) || 0;
                    item.description = product.description || '';
                    item.stock_quantity = product.stock_quantity || 0;
                    item.max_stock = product.stock_quantity || 0;
                    item.image = product.main_image ? `${stockUploadsBase}/${product.id}/thumbnail/${product.main_image}` : '';
                    item.showDropdown = false;
                    item.focusIndex = -1;

                    if (item.tax_percent === undefined || item.tax_percent === null) {
                        item.tax_percent = defaultLineTax();
                    }
                    item.line_total = recalcLineTotal(item);
                    newItems[index] = item;
                    return newItems;
                });

                if (isRoadmaster && product.item_type === 'vehicle' && formData.order_type !== 'truck') {
                    setFormData(prev => ({ ...prev, order_type: 'truck' }));
                    if (window.Swal) {
                        Swal.fire({
                            toast: true, position: 'top-end', icon: 'info',
                            title: 'Vehicle detected. Switching to Truck Invoice.',
                            showConfirmButton: false, timer: 3000
                        });
                    }
                }
            };

            const handleInputKey = (index, e, matchingProducts) => {
                if (!matchingProducts) matchingProducts = [];
                if (e.key === 'ArrowDown') {
                    if (!items[index].showDropdown) {
                        openDropdown(index);
                        return;
                    }
                    moveFocus(index, 1, matchingProducts.length || 1);
                } else if (e.key === 'ArrowUp') {
                    moveFocus(index, -1, matchingProducts.length || 1);
                } else if (e.key === 'Enter') {
                    if (items[index].showDropdown) {
                        e.preventDefault();
                        const fi = items[index].focusIndex >= 0 ? items[index].focusIndex : 0;
                        const prod = matchingProducts[fi];
                        if (prod) selectProduct(index, prod);
                    }
                } else if (e.key === 'Escape') {
                    closeDropdown(index);
                }
            };

            const openDropdown = (index) => {
                const el = document.getElementById(`item-search-${index}`);
                setItems(prev => {
                    const newItems = prev.map((it, i) => ({
                        ...it,
                        showDropdown: i === index,
                        focusIndex: -1,
                    }));
                    if (!el) {
                        newItems[index] = { ...newItems[index], showDropdown: true, focusIndex: -1 };
                        return newItems;
                    }
                    const rect = el.getBoundingClientRect();
                    const pos = { left: rect.left + window.scrollX, top: rect.top + window.scrollY };
                    newItems[index] = { ...newItems[index], showDropdown: true, dropdownPos: pos, focusIndex: -1 };
                    return newItems;
                });
                if (el) {
                    setTimeout(() => { try { el.focus(); } catch (e) {} }, 50);
                }
            };

            const moveFocus = (index, delta, max) => {
                const newItems = [...items];
                const cur = (newItems[index].focusIndex || 0);
                let next = cur + delta;
                if (next < 0) next = max - 1;
                if (next >= max) next = 0;
                newItems[index] = { ...newItems[index], focusIndex: next };
                setItems(newItems);
                setTimeout(() => {
                    const el = document.getElementById(`prod-${index}-${next}`);
                    if (el && typeof el.scrollIntoView === 'function') el.scrollIntoView({ block: 'nearest' });
                }, 10);
            };

            const closeDropdown = (index) => {
                const newItems = [...items];
                newItems[index] = { ...newItems[index], showDropdown: false, focusIndex: -1 };
                setItems(newItems);
            };

            const recalcLineTotal = (item) => {
                const qty = parseFloat(item.quantity) || 0;
                const price = parseFloat(item.unit_price) || 0;
                const disc = parseFloat(item.discount) || 0;
                let total = qty * price;
                total = total - (total * (disc / 100));
                return total;
            };

            const emptyLineItem = () => ({
                id: Date.now() + Math.random(),
                product_id: '',
                quantity: 1,
                unit_price: 0,
                discount: 0,
                tax_percent: defaultLineTax(),
                line_total: 0,
                description: '',
                stock_quantity: 0,
                max_stock: 0,
                image: '',
                searchQuery: '',
                showDropdown: false,
            });

            const addItem = () => {
                closeAllDropdowns();
                setItems(prev => [...prev.map(it => ({ ...it, showDropdown: false })), emptyLineItem()]);
            };

            const removeItem = (index) => {
                closeAllDropdowns();
                if (items.length > 1) {
                    setItems(items.filter((_, i) => i !== index));
                } else {
                    setItems([emptyLineItem()]);
                }
            };

            const clearAllItems = () => {
                if (confirm("Are you sure you want to clear all line items?")) {
                    closeAllDropdowns();
                    setItems([emptyLineItem()]);
                }
            };

            const totals = useMemo(() => {
                const grossSubtotal = items.reduce((sum, item) => sum + (parseFloat(item.line_total) || 0), 0);
                const discountAmt = parseFloat(formData.discount_amount) || 0;
                const afterDisc = Math.max(0, grossSubtotal - discountAmt);
                const shipping = parseFloat(formData.shipping_charges) || 0;

                if (taxMode === 'inclusive') {
                    const subtotal = items.reduce((sum, item) => {
                        const gross = parseFloat(item.line_total) || 0;
                        const tp = parseFloat(item.tax_percent);
                        const pct = Number.isFinite(tp) ? tp : (parseFloat(formData.tax_percentage) || 18);
                        if (pct <= 0) return sum + gross;
                        return sum + gross / (1 + (pct / 100));
                    }, 0);
                    const afterDiscSubtotal = Math.max(0, subtotal - discountAmt);
                    let taxAmt = Math.max(0, grossSubtotal - subtotal);
                    if (subtotal > 0 && afterDiscSubtotal < subtotal) {
                        taxAmt *= afterDiscSubtotal / subtotal;
                    }
                    const grandTotal = afterDiscSubtotal + taxAmt + shipping;
                    return { subtotal, taxAmt, grandTotal };
                }

                const subtotal = grossSubtotal;
                const taxAmt = items.reduce((sum, item) => {
                    const base = parseFloat(item.line_total) || 0;
                    const tp = parseFloat(item.tax_percent);
                    const pct = Number.isFinite(tp) ? tp : (parseFloat(formData.tax_percentage) || 18);
                    return sum + base * (pct / 100);
                }, 0);
                const grandTotal = afterDisc + taxAmt + shipping;

                return { subtotal, taxAmt, grandTotal };
            }, [items, formData.discount_amount, formData.tax_percentage, formData.shipping_charges, taxMode]);

            // Selected customer details
            const selectedCustomerObj = customers.find(c => String(c.id) === String(formData.customer_id));
            const formatCurrency = (val) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);

            return (
                <div className="quote-page">
                    <style>{`/* Create Invoice Layout */
.quote-page {
    width: 100%;
    max-width: 1000px;
    margin-left: 120px;
    margin-right: auto;
    padding: 32px;
    background: #f8fafc;
}
.quote-layout, .quote-left { min-width:0; max-width:100% }
.quote-card { max-width:100%; box-sizing:border-box }
.quote-table-wrap { overflow: visible }
.quote-header { display:flex; justify-content:space-between; align-items:center; gap:18px; margin-bottom:32px }
.quote-title h1 { font-size:28px; font-weight:800; color:#1e293b; margin:0 }
.quote-title p { color:#64748b; font-size:14px; margin-top:6px }
.quote-back-link { color:#94a3b8; font-weight:500; display:inline-flex; align-items:center; gap:8px; text-decoration:none; white-space:nowrap }
.quote-back-link:hover { color:#475569; text-decoration:none }
.quote-actions { display:flex; gap:12px; align-items:center }
.quote-layout { display:flex; flex-direction:column; gap:24px; align-items:stretch }
.quote-left { min-width:0; display:flex; flex-direction:column; gap:20px }
.quote-top-grid { display:grid; grid-template-columns: 1fr; gap:20px }
.quote-card { background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); padding:24px }
.quote-card-header { display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #f1f5f9; font-size:18px; font-weight:700; color:#0f172a }
.quote-card-header i { color:#2563eb }
.quote-field { display:block; font-size:14px; font-weight:500; color:#1e293b; margin-bottom:8px }
.quote-input, .quote-select, .quote-textarea { width:100%; height:46px; border:1px solid #e2e8f0; border-radius:10px; padding:0 16px; font-size:14px; color:#1e293b; background:#ffffff; }
.quote-textarea { min-height:90px; padding:12px; resize:vertical }
.quote-input:focus, .quote-select:focus, .quote-textarea:focus { border-color:#3b82f6; box-shadow:0 0 0 4px rgba(59,130,246,0.1) }
.quote-summary { position:static; width:calc(100% + 180px); max-width:none; margin-right:-180px; display:block }
.quote-summary .quote-card { width:100%; max-width:none; padding:18px }
.quote-summary-heading { display:flex; align-items:center; gap:8px; margin-bottom:10px; font-size:14px; font-weight:700; color:#0f172a }
.quote-form-row { display:grid; grid-template-columns:220px 1fr; align-items:start; gap:24px; margin-bottom:20px }
.quote-form-row:last-child { margin-bottom:0 }
.quote-form-label { font-size:14px; font-weight:500; color:#1e293b; padding-top:12px }
.quote-form-label.required::after { content:' *'; color:#ef4444; }
.quote-static-box { min-height:46px; display:flex; align-items:center; padding:0 16px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; font-size:14px; color:#475569 }
.quote-inline-actions { display:flex; align-items:center; gap:12px; flex-wrap:wrap }
.quote-inline-link { color:#2563eb; font-size:13px; font-weight:700; text-decoration:none }
.quote-inline-link:hover { text-decoration:none; color:#1d4ed8 }
.summary-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #f1f5f9; font-size:12px; color:#475569 }
.summary-row strong { color:#0f172a }
.summary-total { display:flex; justify-content:space-between; align-items:center; padding:12px 0; margin-top:4px; border-top:1px solid #e2e8f0 }
.summary-total span:first-child { font-size:12px; font-weight:800; text-transform:uppercase; color:#0f172a }
.summary-total span:last-child { font-size:18px; font-weight:900; color:#2563eb }
.summary-input { width:78px; height:32px; border:1px solid #e2e8f0; border-radius:8px; padding:0 8px; text-align:right; font-size:12px }
.quote-table-wrap { width:100%; max-width:100%; overflow-x:visible; overflow-y:visible; -ms-overflow-style:none; scrollbar-width:none }
.quote-table-wrap::-webkit-scrollbar { display:none; width:0; height:0 }
.quote-table { width:100%; min-width:0; border-collapse:separate; border-spacing:0; table-layout:fixed }
.quote-table th { background:#f8fafc; color:#64748b; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; padding:14px 10px; border-bottom:1px solid #e2e8f0; text-align:left; white-space:normal; line-height:1.2 }
.quote-table td { padding:14px 10px; border-bottom:1px solid #eef2f7; vertical-align:middle; overflow:hidden }
.quote-table .col-num { width:32px }
.quote-table .col-image { width:58px }
.quote-table .col-item { width:17% }
.quote-table .col-desc { width:18% }
.quote-table .col-qty { width:92px }
.quote-table .col-price { width:11% }
.quote-table .col-disc { width:58px }
.quote-table .col-tax { width:64px }
.quote-table .col-total { width:11% }
.quote-table .col-action { width:44px }
.li-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; gap:12px; flex-wrap:wrap; padding-bottom:16px; border-bottom:1px solid #f1f5f9 }
.line-items-card { width:calc(100% + 180px); max-width:none; margin-right:-180px }
.li-header-title { font-weight:800; font-size:16px; color:#0f172a; display:flex; align-items:center; gap:8px }
.li-header-actions { display:flex; gap:10px; flex-wrap:wrap }
.li-btn-catalogue { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; font-weight:700; color:#334155; background:#fff; border:1px solid #cbd5e1; border-radius:10px; text-decoration:none }
.li-btn-catalogue:hover { background:#f8fafc; border-color:#94a3b8 }
.li-btn-manual { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; font-weight:700; color:#fff; background:#3b82f6; border:1px solid #3b82f6; border-radius:10px; cursor:pointer; box-shadow:0 4px 12px rgba(59,130,246,0.2) }
.li-btn-manual:hover { background:#2563eb }
.li-item-cell { min-width:0; max-width:100% }
.li-item-card { display:block; min-width:0; max-width:100% }
.li-col-image { text-align:center; vertical-align:middle; padding:10px 6px !important }
.li-item-thumb { width:44px; height:44px; border-radius:8px; background:#f1f5f9; border:1px solid #e2e8f0; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0 }
.li-item-thumb img { width:100%; height:100%; object-fit:contain }
.li-item-meta { min-width:0; flex:1 }
.li-item-name { font-weight:700; font-size:13px; color:#0f172a; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100% }
.li-item-code { display:inline-block; margin-top:4px; padding:2px 8px; font-size:11px; font-weight:600; color:#64748b; background:#f1f5f9; border-radius:6px }
.li-item-change { font-size:11px; color:#2563eb; font-weight:700; cursor:pointer; margin-top:4px; display:inline-block }
.li-item-change:hover { text-decoration:underline }
.li-search-input { width:100%; max-width:100%; min-width:0; height:38px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-size:12px; box-sizing:border-box }
.li-desc-input { width:100%; max-width:100%; min-width:0; height:38px; border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; font-size:12px; color:#334155; background:#fafafa; box-sizing:border-box }
.li-qty-stepper { display:inline-flex; align-items:center; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; background:#fff; max-width:100% }
.li-qty-stepper button { width:30px; height:34px; border:0; background:#f8fafc; color:#475569; font-size:14px; font-weight:700; cursor:pointer; line-height:1; flex-shrink:0 }
.li-qty-stepper button:hover { background:#e2e8f0 }
.li-qty-stepper input { width:36px; min-width:0; height:34px; border:0; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; text-align:center; font-size:12px; font-weight:700; color:#0f172a; padding:0 }
.li-unit-price { font-weight:800; font-size:13px; color:#0f172a; white-space:nowrap }
.li-price-input { width:100%; max-width:100%; min-width:0; height:34px; border:1px solid #e2e8f0; border-radius:8px; padding:0 8px; font-size:12px; font-weight:700; text-align:right; box-sizing:border-box }
.li-disc-input { width:100%; max-width:52px; height:34px; border:1px solid #e2e8f0; border-radius:8px; padding:0 4px; font-size:12px; text-align:center; box-sizing:border-box }
.li-tax-select { width:100%; max-width:58px; height:34px; border:1px solid #e2e8f0; border-radius:8px; padding:0 4px; font-size:11px; font-weight:600; color:#0f172a; background:#fff; box-sizing:border-box }
.li-line-total { font-weight:800; font-size:13px; color:#0f172a; text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
.li-action-btn { width:34px; height:34px; border:1px solid #fecaca; background:#fff; color:#ef4444; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center }
.li-action-btn:hover { background:#fef2f2 }
.li-footer { display:flex; gap:12px; margin-top:16px; flex-wrap:wrap }
.li-btn-add-row { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; font-weight:700; color:#334155; background:#fff; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer }
.li-btn-add-row:hover { background:#f8fafc }
.li-btn-clear { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; font-weight:700; color:#ef4444; background:#fff; border:1px solid #fecaca; border-radius:10px; cursor:pointer }
.li-btn-clear:hover { background:#fef2f2 }
.col-num { color:#94a3b8; font-weight:700; font-size:12px; text-align:center }
.col-action { text-align:center; padding:8px 4px !important }
.quote-card:has(.quote-table) { overflow:hidden }
.btn-primary { background:#3b82f6; color:#ffffff; border:1px solid #3b82f6; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:600; box-shadow:0 4px 12px rgba(59,130,246,0.2) }
.btn-primary:hover { background:#2563eb }
.btn-secondary { background:#ffffff; color:#334155; border:1px solid #cbd5e1; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:700 }
.btn-danger { background:#7c3aed; color:#ffffff; border:1px solid #6d28d9; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:700 }
.btn-danger:hover { background:#6d28d9 }
.summary-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; align-items:center; justify-content:flex-end }
.summary-actions .btn-primary,
.summary-actions .btn-secondary,
.summary-actions .btn-danger {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:auto;
    min-width:110px;
    text-decoration:none;
    line-height:1.2;
}
@media (max-width: 1200px) { .quote-page { margin-left: 40px; margin-right: auto } .line-items-card { width:calc(100% + 90px); margin-right:-90px } .quote-summary { width:calc(100% + 90px); margin-right:-90px; position:static; } .quote-summary .quote-card { max-width:none } }
@media (max-width:850px) { .quote-page { padding:16px } .quote-top-grid, .quote-form-grid { grid-template-columns:1fr } .quote-header { flex-direction:column; align-items:flex-start } .quote-form-row { grid-template-columns:1fr; gap:8px; margin-bottom:18px } .quote-form-label { padding-top:0 } }
`}</style>
                    <form method="POST">
                        {/* Totals posted to invoice handler */}
                        <input type="hidden" name="subtotal" value={totals.subtotal.toFixed(2)} />
                        <input type="hidden" name="tax_amount" value={totals.taxAmt.toFixed(2)} />
                        <input type="hidden" name="total_amount" value={totals.grandTotal.toFixed(2)} />
                        {!isRoadmaster ? (
                            <input type="hidden" name="order_type" value={formData.order_type} />
                        ) : null}

                        {/* Top Header */}
                        <div className="quote-header">
                            <div className="quote-title">
                                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Create Invoice</h1>
                                <p className="text-sm text-slate-500 mt-1">Draft a new invoice and add items from the catalogue or manually.</p>
                            </div>
                            <a href="../invoices/index.php" className="quote-back-link">
                                <i className="fas fa-arrow-left text-xs"></i> Back to Invoices
                            </a>
                        </div>

                        {error && (
                            <div className="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded text-sm text-red-700 font-medium">
                                <i className="fas fa-exclamation-circle mr-2"></i>{error}
                            </div>
                        )}

                        <div className="quote-layout">
                            <div className="quote-left">
                                <div className="quote-top-grid">
                                    <div className="quote-card">
                                        <div className="quote-card-header">General Information</div>
                                        <div className="quote-form-row">
                                            <label className="quote-form-label required">Customer</label>
                                            <div>
                                                <select 
                                                    name="customer_id" 
                                                    value={formData.customer_id}
                                                    onChange={e => setFormData({ ...formData, customer_id: e.target.value })}
                                                    required 
                                                    className="quote-select"
                                                >
                                                    <option value="">Select Customer</option>
                                                    {customers.map(c => (
                                                        <option key={c.id} value={c.id}>
                                                            {c.company_name} {c.contact_person ? `(${c.contact_person})` : ''}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>
                                        <div className="quote-form-row">
                                            <label className="quote-form-label required">Invoice date</label>
                                            <div>
                                                <input type="date" name="invoice_date" value={formData.invoice_date} onChange={e => setFormData({ ...formData, invoice_date: e.target.value })} required className="quote-input" />
                                            </div>
                                        </div>
                                        <div className="quote-form-row">
                                            <label className="quote-form-label required">Due date</label>
                                            <div>
                                                <input type="date" name="due_date" value={formData.due_date} onChange={e => setFormData({ ...formData, due_date: e.target.value })} required className="quote-input" />
                                            </div>
                                        </div>
                                        <div className="quote-form-row">
                                            <label className="quote-form-label">Lead time</label>
                                            <div>
                                                <input type="number" name="lead_time" placeholder="e.g. 10" value={formData.lead_time} onChange={e => setFormData({ ...formData, lead_time: e.target.value })} min="0" className="quote-input" />
                                            </div>
                                        </div>
                                        <div className="quote-form-row">
                                            <label className="quote-form-label">Next invoice #</label>
                                            <div className="quote-static-box">{nextInvoiceNumber || '-'}</div>
                                        </div>
                                        {isRoadmaster ? (
                                            <div className="quote-form-row">
                                                <label className="quote-form-label">Document type</label>
                                                <div>
                                                    <select
                                                        name="order_type"
                                                        value={formData.order_type}
                                                        onChange={(e) => setFormData({ ...formData, order_type: e.target.value })}
                                                        className="quote-select"
                                                    >
                                                        <option value="spare">Spare invoice</option>
                                                        <option value="truck">Truck invoice</option>
                                                    </select>
                                                </div>
                                            </div>
                                        ) : null}
                                        <div className="quote-form-row">
                                            <label className="quote-form-label">Customer tools</label>
                                            <div>
                                                <div className="quote-inline-actions">
                                                    <a href={customerCatalogueUrl} onClick={(e) => { e.preventDefault(); saveStateAndGo(customerCatalogueUrl); }} className="li-btn-catalogue" style={{textDecoration:'none', fontSize:12, padding:'6px 10px'}}>
                                                        <i className="fa-solid fa-table-cells"></i> Customer catalogue
                                                    </a>
                                                    <a href="../customers/index.php" className="quote-inline-link">Manage customers</a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="quote-card line-items-card">
                                    <div className="li-header">
                                        <div className="li-header-title">
                                            <i className="fa-solid fa-list-ul" style={{color:'#2563eb'}}></i>
                                            Line Items
                                        </div>
                                        <div className="li-header-actions">
                                            <a href={catalogueUrl} onClick={(e) => { e.preventDefault(); closeAllDropdowns(); saveStateAndGo(catalogueUrl); }} className="li-btn-catalogue">
                                                <i className="fa-solid fa-table-cells"></i> Add from Catalogue
                                            </a>
                                            <button type="button" onClick={(e) => { e.stopPropagation(); addItem(); }} className="li-btn-manual">
                                                <i className="fa-solid fa-plus"></i> Add Manual Item
                                            </button>
                                        </div>
                                    </div>

                                    <div className="quote-table-wrap">
                                        <table className="quote-table">
                                            <thead>
                                                <tr>
                                                    <th className="col-num">#</th>
                                                    <th className="col-image">Image</th>
                                                    <th className="col-item">Item</th>
                                                    <th className="col-desc">Description</th>
                                                    <th className="col-qty">Qty</th>
                                                    <th className="col-price">Unit Price</th>
                                                    <th className="col-disc">Disc %</th>
                                                    <th className="col-tax">Tax %</th>
                                                    <th className="col-total" style={{textAlign:'right'}}>Line Total</th>
                                                    <th className="col-action">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {items.map((item, index) => {
                                                    const matchingProducts = item.showDropdown
                                                        ? products.filter(p => {
                                                            if (!item.searchQuery) return true;
                                                            const q = item.searchQuery.toLowerCase();
                                                            return p.name.toLowerCase().includes(q) || (p.product_code && p.product_code.toLowerCase().includes(q));
                                                        })
                                                        : [];
                                                    const currentProductObj = products.find(p => String(p.id) === String(item.product_id));
                                                    const hasProduct = item.product_id && (currentProductObj || item.searchQuery);
                                                    const displayName = currentProductObj ? currentProductObj.name : (item.searchQuery || '');
                                                    const displayCode = currentProductObj ? (currentProductObj.product_code || '') : '';
                                                    const taxVal = item.tax_percent !== undefined && item.tax_percent !== null ? item.tax_percent : defaultLineTax();
                                                    return (
                                                        <tr key={item.id}>
                                                            <td className="col-num">
                                                                <input type="hidden" name={`items[${index}][product_id]`} value={item.product_id} />
                                                                <input type="hidden" name={`items[${index}][line_total]`} value={item.line_total} />
                                                                {index + 1}
                                                            </td>
                                                            <td className="li-col-image">
                                                                <div className="li-item-thumb" title={displayName || 'Product'}>
                                                                    {item.image ? <img src={item.image} alt="" /> : <i className="fa-solid fa-box text-slate-400"></i>}
                                                                </div>
                                                            </td>
                                                            <td className="li-item-cell col-item product-search-cell">
                                                                {hasProduct && !item.showDropdown ? (
                                                                    <div className="li-item-card">
                                                                        <div className="li-item-meta">
                                                                            <div className="li-item-name">{displayName}</div>
                                                                            {displayCode ? <span className="li-item-code">{displayCode}</span> : null}
                                                                            <span className="li-item-change" onClick={() => openDropdown(index)}>Change product</span>
                                                                        </div>
                                                                    </div>
                                                                ) : null}
                                                                <div className="relative" style={{display: hasProduct && !item.showDropdown ? 'none' : 'block'}}>
                                                                    <input
                                                                        id={`item-search-${index}`}
                                                                        type="text"
                                                                        placeholder="Search product..."
                                                                        value={item.searchQuery}
                                                                        onChange={(e) => handleItemChange(index, 'searchQuery', e.target.value)}
                                                                        onFocus={() => openDropdown(index)}
                                                                        onKeyDown={(e) => handleInputKey(index, e, matchingProducts)}
                                                                        className="li-search-input"
                                                                    />
                                                                    {item.showDropdown && (
                                                                        <div className="product-search-dropdown" onMouseDown={(e) => e.preventDefault()}>
                                                                            <div className="bg-white shadow-2xl rounded-xl py-2 text-sm border border-slate-100 max-h-72 overflow-y-auto custom-scroll" style={{position:'fixed', left:(item.dropdownPos ? item.dropdownPos.left : 0) + 'px', top:(item.dropdownPos ? item.dropdownPos.top : 0) + 'px', transform:'translateY(calc(-100% - 6px))', width:320, paddingLeft:8, paddingRight:8, zIndex:9999}}>
                                                                                {matchingProducts.length > 0 ? matchingProducts.map((p, mi) => (
                                                                                    <div
                                                                                        id={`prod-${index}-${mi}`}
                                                                                        key={p.id}
                                                                                        onMouseDown={(e) => e.preventDefault()}
                                                                                        onClick={(e) => { e.preventDefault(); e.stopPropagation(); selectProduct(index, p); }}
                                                                                        onMouseEnter={() => { setItems(prev => { const ni = [...prev]; ni[index] = { ...ni[index], focusIndex: mi }; return ni; }); }}
                                                                                        className={'cursor-pointer py-2 px-3.5 flex items-center gap-3 border-b border-slate-100 last:border-0 ' + (item.focusIndex === mi ? 'bg-blue-50 text-blue-700' : 'hover:bg-blue-50 hover:text-blue-700')}
                                                                                    >
                                                                                        <div style={{width:56, height:48, background:'#f1f5f9', borderRadius:8, display:'flex', alignItems:'center', justifyContent:'center', overflow:'hidden', flex:'0 0 56px'}}>
                                                                                            {p.main_image ? (
                                                                                                <img src={`${stockUploadsBase}/${p.id}/thumbnail/${p.main_image}`} alt="" style={{width:'100%', height:'100%', objectFit:'contain'}} />
                                                                                            ) : (
                                                                                                <i className="fa-solid fa-box text-sm text-slate-400"></i>
                                                                                            )}
                                                                                        </div>
                                                                                        <div style={{flex:1, minWidth:0}}>
                                                                                            <div className="font-semibold truncate text-sm text-slate-800">{p.name}</div>
                                                                                            <div className="flex justify-between text-[11px] text-slate-500 mt-0.5">
                                                                                                <span className="truncate">{p.product_code}</span>
                                                                                                <span className="font-medium text-blue-600">TSh {formatCurrency(p.selling_price)}</span>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                )) : (
                                                                                    <div className="py-2 px-3.5 text-slate-400 text-xs italic">No matching products</div>
                                                                                )}
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </td>
                                                            <td className="col-desc">
                                                                <input type="text" name={`items[${index}][description]`} value={item.description} onChange={(e) => handleItemChange(index, 'description', e.target.value)} className="li-desc-input" placeholder="Description" />
                                                            </td>
                                                            <td className="col-qty">
                                                                <div className="li-qty-stepper">
                                                                    <button type="button" onClick={() => handleItemChange(index, 'quantity', Math.max(1, (parseFloat(item.quantity) || 1) - 1))}>-</button>
                                                                    <input type="number" name={`items[${index}][quantity]`} min="1" value={item.quantity} onChange={(e) => handleItemChange(index, 'quantity', Math.max(1, parseFloat(e.target.value) || 1))} />
                                                                    <button type="button" onClick={() => handleItemChange(index, 'quantity', (parseFloat(item.quantity) || 1) + 1)}>+</button>
                                                                </div>
                                                            </td>
                                                            <td className="col-price">
                                                                <input type="number" step="0.01" name={`items[${index}][unit_price]`} value={item.unit_price} onChange={(e) => handleItemChange(index, 'unit_price', Math.max(0, parseFloat(e.target.value) || 0))} className="li-price-input" />
                                                            </td>
                                                            <td className="col-disc">
                                                                <input type="number" step="0.01" min="0" max="100" name={`items[${index}][discount]`} value={item.discount} onChange={(e) => handleItemChange(index, 'discount', Math.max(0, Math.min(100, parseFloat(e.target.value) || 0)))} className="li-disc-input" />
                                                            </td>
                                                            <td className="col-tax">
                                                                <select className="li-tax-select" value={taxVal} onChange={(e) => handleItemChange(index, 'tax_percent', parseFloat(e.target.value) || 0)}>
                                                                    {[0, 10, 18, 20].map(t => (
                                                                        <option key={t} value={t}>{t}%</option>
                                                                    ))}
                                                                </select>
                                                            </td>
                                                            <td className="li-line-total col-total">{formatCurrency(item.line_total)}</td>
                                                            <td className="col-action">
                                                                <button type="button" className="li-action-btn" title="Remove row" onClick={(e) => { e.stopPropagation(); removeItem(index); }}>
                                                                    <i className="fa-regular fa-trash-can"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>

                                    <div className="li-footer">
                                        <button type="button" onClick={(e) => { e.stopPropagation(); addItem(); }} className="li-btn-add-row">
                                            <i className="fa-solid fa-plus"></i> Add Row
                                        </button>
                                        <button type="button" onClick={(e) => { e.stopPropagation(); clearAllItems(); }} className="li-btn-clear">
                                            <i className="fa-regular fa-trash-can"></i> Clear All
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <aside className="quote-summary">
                                <div className="quote-card">
                                    <div className="quote-summary-heading"><i className="fa-solid fa-calculator" style={{color:'#2563eb'}}></i><strong>Invoice Summary</strong></div>
                                    <div>
                                        <div className="summary-row"><span>{taxMode === 'inclusive' ? 'Subtotal (excl. tax)' : 'Subtotal'}</span><strong>TZS {formatCurrency(totals.subtotal)}</strong></div>
                                        <div className="summary-row"><span>Discount Amount (-)</span><input type="number" step="0.01" name="discount_amount" value={formData.discount_amount} onChange={e=>setFormData({ ...formData, discount_amount: Math.max(0, parseFloat(e.target.value) || 0) })} className="summary-input"/></div>
                                        <div className="summary-row"><span>{taxMode === 'inclusive' ? 'Tax included (%)' : 'Tax (%)'}</span><div style={{display:'flex', gap:8, alignItems:'center'}}><input type="number" step="0.01" name="tax_percentage" value={formData.tax_percentage} onChange={e=>setFormData({ ...formData, tax_percentage: Math.max(0, Math.min(100, parseFloat(e.target.value) || 0)) })} className="summary-input"/><span style={{minWidth:80, textAlign:'right'}}>TZS {formatCurrency(totals.taxAmt)}</span></div></div>
                                        <div className="summary-row"><span>Shipping Charges (+)</span><input type="number" step="0.01" name="shipping_charges" value={formData.shipping_charges} onChange={e=>setFormData({ ...formData, shipping_charges: Math.max(0, parseFloat(e.target.value) || 0) })} className="summary-input"/></div>
                                        <div className="summary-total"><span>Grand Total</span><span>TZS {formatCurrency(totals.grandTotal)}</span></div>
                                        <div className="summary-actions">
                                            <button type="submit" className="btn-primary">Create Invoice</button>
                                            <a href="../invoices/index.php" className="btn-danger">Cancel</a>
                                        </div>
                                    </div>
                                </div>
                            </aside>
                        </div>
                    </form>
                </div>
            );
        }

        // Attach global handlers to show runtime errors in-page for debugging
        (function(){
            const rootEl = document.getElementById('react-root');
            window.addEventListener('error', function (ev) {
                try {
                    const msg = ev && ev.message ? ev.message : String(ev);
                    if (rootEl) rootEl.innerText = 'JS ERROR: ' + msg;
                } catch (e) { }
            });
            window.addEventListener('unhandledrejection', function (ev) {
                try {
                    const reason = ev && ev.reason ? (ev.reason.message || String(ev.reason)) : String(ev);
                    if (rootEl) rootEl.innerText = 'Unhandled Rejection: ' + reason;
                } catch (e) { }
            });

            try {
                const root = ReactDOM.createRoot(rootEl);
                root.render(<InvoiceCreateApp />);
            } catch (err) {
                console && console.error && console.error(err);
                if (rootEl) rootEl.innerText = 'JS ERROR: ' + (err && err.message ? err.message : String(err));
            }
        })();
    </script>
</body>

</html>

