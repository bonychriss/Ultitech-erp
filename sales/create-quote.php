<?php
if (file_exists('../includes/functions.php')) {
    require_once '../includes/functions.php';
} else {
    require_once '../../includes/functions.php';
}

global $pdo;
$customers = $pdo->query("SELECT id, name, phone, email FROM erp_customers ORDER BY name")->fetchAll();

// Get products for JS
$products = $pdo->query("SELECT id, name, sku, unit_price, unit, description, image_path FROM erp_products WHERE status = 'active' ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Quotation - ERP</title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    
    <style>
        * { 
            margin:0; 
            padding:0; 
            box-sizing:border-box; 
        } 
        body { 
            background: #f8fafc; 
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; 
        } 
        .page-wrapper { 
            margin-left: 220px; 
            min-height: 100vh; 
            padding: 32px; 
            width: calc(100% - 220px); 
        }
        @media (max-width: 768px) { 
            .page-wrapper { 
                margin-left: 0; 
                padding: 16px; 
                width: 100%; 
            } 
        }
        .sidebar { 
            z-index: 50; 
        }
        
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(10px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
        .animate-fade-in { 
            animation: fadeIn 0.4s ease-out forwards; 
        }
        
        /* Clean custom scrollbar for dropdowns */
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
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper" id="react-root"></div>

<script>
    window.APP_DATA = {
        customers: <?= json_encode($customers) ?>,
        products: <?= json_encode($products) ?>
    };
</script>

<script type="text/babel">
    const { useState, useEffect, useRef } = React;

    function CreateQuoteApp() {
        const { customers, products } = window.APP_DATA;
        
        const [isSubmitting, setIsSubmitting] = useState(false);
        const [formData, setFormData] = useState({
            customer_id: '',
            date: new Date().toISOString().split('T')[0],
            expiry_date: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0], // default 7 days from now as shown in mockup (05/19 to 05/26)
            lead_time: '',
            reference: '',
            notes: ''
        });
        
        const [items, setItems] = useState([
            { id: Date.now(), product_id: '', description: '', quantity: 1, unit_price: 0, tax_rate: 18, total: 0, searchQuery: '', showDropdown: false, discount: 0, isManual: false }
        ]);

        const [globalDiscount, setGlobalDiscount] = useState(0);
        const [globalDiscountType, setGlobalDiscountType] = useState('%'); // '%' or 'Value'

        const handleFormChange = (e) => {
            const { name, value } = e.target;
            setFormData(prev => ({ ...prev, [name]: value }));
        };

        const updateItem = (id, field, value) => {
            setItems(prevItems => {
                return prevItems.map(item => {
                    if (item.id === id) {
                        const updatedItem = { ...item, [field]: value };
                        
                        // Recalculate total if quantity, unit_price or discount changed
                        if (['quantity', 'unit_price', 'discount'].includes(field)) {
                            const qty = parseFloat(updatedItem.quantity) || 0;
                            const price = parseFloat(updatedItem.unit_price) || 0;
                            const disc = parseFloat(updatedItem.discount) || 0;
                            const sub = qty * price;
                            const discountedSub = sub * (1 - (disc / 100));
                            updatedItem.total = Number(discountedSub.toFixed(2));
                        }
                        
                        return updatedItem;
                    }
                    return item;
                });
            });
        };

        const addItem = (isManual = false) => {
            setItems([...items, { 
                id: Date.now(), product_id: '', description: '', quantity: 1, unit_price: 0, tax_rate: 18, total: 0, searchQuery: '', showDropdown: false, discount: 0, isManual: isManual 
            }]);
        };

        const removeItem = (id) => {
            if (items.length > 1) {
                setItems(items.filter(item => item.id !== id));
            }
        };

        const clearAllItems = () => {
            if (confirm("Are you sure you want to clear all line items?")) {
                setItems([
                    { id: Date.now(), product_id: '', description: '', quantity: 1, unit_price: 0, tax_rate: 18, total: 0, searchQuery: '', showDropdown: false, discount: 0, isManual: false }
                ]);
            }
        };

        const selectProduct = (itemId, product) => {
            setItems(prevItems => prevItems.map(item => {
                if (item.id === itemId) {
                    const qty = parseFloat(item.quantity) || 1;
                    const price = parseFloat(product.unit_price) || 0;
                    const disc = parseFloat(item.discount) || 0;
                    const totalVal = qty * price * (1 - (disc / 100));
                    return {
                        ...item,
                        product_id: product.id,
                        searchQuery: product.name,
                        description: product.description || product.name,
                        unit_price: price,
                        total: Number(totalVal.toFixed(2)),
                        showDropdown: false,
                        isManual: false
                    };
                }
                return item;
            }));
        };

        // Find customer details dynamically
        const selectedCustomerObj = customers.find(c => String(c.id) === String(formData.customer_id));
        const customerPhone = selectedCustomerObj ? selectedCustomerObj.phone || '-' : '-';
        const customerEmail = selectedCustomerObj ? selectedCustomerObj.email || '-' : '-';

        // Calculations
        const subtotal = items.reduce((sum, item) => sum + (parseFloat(item.total) || 0), 0);
        
        // Calculate global discount
        const discountAmt = globalDiscountType === '%' 
            ? subtotal * (parseFloat(globalDiscount || 0) / 100) 
            : parseFloat(globalDiscount || 0);

        const taxableAmount = Math.max(0, subtotal - discountAmt);

        // Compute total tax correctly based on line items tax_rate applied to discounted totals
        const totalTax = items.reduce((sum, item) => {
            const itemTotal = parseFloat(item.total) || 0;
            const itemProportionalDiscount = subtotal > 0 ? (itemTotal / subtotal) * discountAmt : 0;
            const itemTaxable = Math.max(0, itemTotal - itemProportionalDiscount);
            const taxRate = parseFloat(item.tax_rate) || 0;
            return sum + (itemTaxable * (taxRate / 100));
        }, 0);

        const grandTotal = taxableAmount + totalTax;

        const formatCurrency = (val) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);

        const handleSubmit = async (e, saveAsDraft = false) => {
            if (e) e.preventDefault();
            
            if (items.length === 0) {
                alert('Please add at least one item');
                return;
            }
            if (!formData.customer_id) {
                alert('Please select a customer');
                return;
            }

            setIsSubmitting(true);
            
            const submitData = new FormData();
            submitData.append('action', 'create');
            submitData.append('customer_id', formData.customer_id);
            submitData.append('date', formData.date);
            submitData.append('expiry_date', formData.expiry_date);
            submitData.append('lead_time', formData.lead_time);
            submitData.append('reference', formData.reference);
            submitData.append('notes', formData.notes);
            
            items.forEach((item, index) => {
                submitData.append(`items[${index}][product_id]`, item.product_id || '');
                submitData.append(`items[${index}][description]`, item.description || item.searchQuery || '');
                submitData.append(`items[${index}][quantity]`, item.quantity);
                submitData.append(`items[${index}][unit_price]`, item.unit_price);
                submitData.append(`items[${index}][tax_rate]`, item.tax_rate);
                submitData.append(`items[${index}][total]`, item.total);
            });

            try {
                const response = await fetch('../api/quotes.php', {
                    method: 'POST',
                    body: submitData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    window.location.href = 'view-quote.php?id=' + result.id;
                } else {
                    throw new Error(result.message || 'Failed to create quote');
                }
            } catch (error) {
                alert('Error: ' + error.message);
                setIsSubmitting(false);
            }
        };

        return (
            <div className="max-w-7xl mx-auto animate-fade-in pb-16">
                <form onSubmit={(e) => handleSubmit(e, false)}>
                    
                    {/* Top Breadcrumb & Actions Bar */}
                    <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                        <div>
                            <div className="text-xs text-slate-400 font-medium mb-1">
                                Orders <span className="mx-1 text-slate-300">&gt;</span> <span className="text-slate-500">Create quotation</span>
                            </div>
                            <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Create Quotation</h1>
                            <p className="text-sm text-slate-500 mt-1">Create a new quotation by adding items from the catalogue or manually.</p>
                        </div>
                        <div className="flex gap-3">
                            <button 
                                type="button"
                                onClick={(e) => handleSubmit(null, true)}
                                disabled={isSubmitting}
                                className="px-4 py-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-lg text-sm font-medium transition-all shadow-sm flex items-center gap-2 hover:border-slate-300 disabled:opacity-50"
                            >
                                <i className="fa-regular fa-floppy-disk text-slate-400"></i>
                                Save Draft
                            </button>
                            <button 
                                type="submit" 
                                disabled={isSubmitting}
                                className="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition-all shadow-sm flex items-center gap-2 disabled:opacity-50"
                            >
                                {isSubmitting ? <i className="fas fa-spinner fa-spin"></i> : <i className="fa-solid fa-circle-check"></i>}
                                Create Quotation
                            </button>
                        </div>
                    </div>

                    {/* Top Cards: Customer and Quotation Details */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        
                        {/* Customer Details Card */}
                        <div className="bg-white rounded-xl border border-slate-100 p-6 shadow-sm flex flex-col justify-between">
                            <div>
                                <div className="flex items-center gap-2 text-slate-800 font-semibold text-base mb-5">
                                    <i className="fa-solid fa-user text-blue-500"></i>
                                    Customer Details
                                </div>
                                
                                <div className="mb-4">
                                    <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Customer *</label>
                                    <select 
                                        name="customer_id" 
                                        value={formData.customer_id}
                                        onChange={handleFormChange}
                                        required 
                                        className="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none transition-all text-slate-700 font-medium"
                                    >
                                        <option value="">- Select a customer -</option>
                                        {customers.map(c => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </select>
                                </div>

                                <div className="grid grid-cols-2 gap-4 mt-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Phone</label>
                                        <div className="text-sm font-medium text-slate-700 py-1">{customerPhone}</div>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Email</label>
                                        <div className="text-sm font-medium text-slate-700 py-1 break-all">{customerEmail}</div>
                                    </div>
                                </div>
                            </div>

                            <div className="text-right mt-6 border-t border-slate-50 pt-4">
                                <a href="customers.php" className="text-blue-600 hover:text-blue-700 font-semibold text-xs inline-flex items-center gap-1.5 transition-colors">
                                    Manage customers
                                    <i className="fa-solid fa-arrow-right-long"></i>
                                </a>
                            </div>
                        </div>

                        {/* Quotation Details Card */}
                        <div className="bg-white rounded-xl border border-slate-100 p-6 shadow-sm">
                            <div className="flex items-center gap-2 text-slate-800 font-semibold text-base mb-5">
                                <i className="fa-solid fa-calendar-days text-blue-500"></i>
                                Quotation Details
                            </div>

                            <div className="grid grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Quote Date *</label>
                                    <div className="relative">
                                        <input 
                                            type="date" 
                                            name="date" 
                                            value={formData.date}
                                            onChange={handleFormChange}
                                            required 
                                            className="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none transition-all text-slate-700 font-medium"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Valid Until</label>
                                    <div className="relative">
                                        <input 
                                            type="date" 
                                            name="expiry_date" 
                                            value={formData.expiry_date}
                                            onChange={handleFormChange}
                                            className="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none transition-all text-slate-700 font-medium"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Lead Time (Days)</label>
                                    <input 
                                        type="number" 
                                        name="lead_time" 
                                        placeholder="e.g. 10" 
                                        value={formData.lead_time}
                                        onChange={handleFormChange}
                                        min="0"
                                        className="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none transition-all text-slate-700 font-medium"
                                    />
                                </div>
                            </div>

                            <div className="mt-4">
                                <label className="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Reference (Optional)</label>
                                <input 
                                    type="text" 
                                    name="reference" 
                                    placeholder="e.g. QTN-0001" 
                                    value={formData.reference}
                                    onChange={handleFormChange}
                                    className="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none transition-all text-slate-700 font-medium"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Bottom Core Form Section: Line Items and Notes (Left) / Order Summary (Right) */}
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        
                        {/* Left Side: Line Items & Notes */}
                        <div className="lg:col-span-2 space-y-6">
                            
                            {/* Line Items Card */}
                            <div className="bg-white rounded-xl border border-slate-100 p-6 shadow-sm overflow-visible">
                                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-3">
                                    <div className="flex items-center gap-2 text-slate-800 font-semibold text-base">
                                        <i className="fa-solid fa-list-ul text-blue-500"></i>
                                        Line Items
                                    </div>
                                    <div className="flex gap-2">
                                        <button 
                                            type="button" 
                                            onClick={() => addItem(false)}
                                            className="px-3.5 py-1.5 bg-white border border-slate-200 hover:bg-slate-50 hover:border-slate-300 text-slate-600 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm"
                                        >
                                            <i className="fa-solid fa-images text-slate-400"></i>
                                            Add from Catalogue
                                        </button>
                                        <button 
                                            type="button" 
                                            onClick={() => addItem(true)}
                                            className="px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm"
                                        >
                                            <i className="fa-solid fa-plus"></i>
                                            Add Manual Item
                                        </button>
                                    </div>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm border-collapse min-w-[700px]">
                                        <thead>
                                            <tr className="border-b border-slate-100 text-[10px] font-semibold text-slate-400 uppercase tracking-wider bg-slate-50/50">
                                                <th className="py-3 px-2 w-8 text-center">#</th>
                                                <th className="py-3 px-3 w-56">Item</th>
                                                <th className="py-3 px-3">Description</th>
                                                <th className="py-3 px-3 w-28 text-center">Qty</th>
                                                <th className="py-3 px-3 w-32">Unit Price</th>
                                                <th className="py-3 px-3 w-20">Disc %</th>
                                                <th className="py-3 px-3 w-24">Tax %</th>
                                                <th className="py-3 px-3 w-32 text-right">Line Total</th>
                                                <th className="py-3 px-2 w-10 text-center"></th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {items.map((item, index) => {
                                                const matchingProducts = item.searchQuery && item.showDropdown
                                                    ? products.filter(p => p.name.toLowerCase().includes(item.searchQuery.toLowerCase()) || p.sku.toLowerCase().includes(item.searchQuery.toLowerCase()))
                                                    : [];
                                                    
                                                // Find selected product to show image and code
                                                const currentProductObj = products.find(p => String(p.id) === String(item.product_id));
                                                
                                                return (
                                                    <tr key={item.id} className="hover:bg-slate-50/50 transition-colors group">
                                                        
                                                        {/* Number Column */}
                                                        <td className="py-4 px-2 align-top text-center text-xs font-bold text-slate-400">
                                                            {index + 1}
                                                        </td>
                                                        
                                                        {/* Item Selection Column */}
                                                        <td className="py-4 px-3 align-top">
                                                            <div className="flex gap-2">
                                                                
                                                                {/* Product Image Thumbnail */}
                                                                <div className="w-10 h-10 bg-slate-100 rounded-lg flex items-center justify-center text-slate-400 shrink-0 border border-slate-200 overflow-hidden">
                                                                    {currentProductObj && currentProductObj.image_path ? (
                                                                        <img 
                                                                            src={`../stock/uploads/products/${currentProductObj.id}/medium/${currentProductObj.image_path}`} 
                                                                            alt="" 
                                                                            className="w-full h-full object-contain"
                                                                            onError={(e) => { e.target.style.display = 'none'; }}
                                                                        />
                                                                    ) : (
                                                                        <i className="fa-solid fa-box text-sm"></i>
                                                                    )}
                                                                </div>

                                                                {/* Product Search Input */}
                                                                <div className="relative w-full">
                                                                    {item.isManual ? (
                                                                        <input 
                                                                            type="text" 
                                                                            placeholder="Manual item name..." 
                                                                            value={item.searchQuery}
                                                                            onChange={(e) => updateItem(item.id, 'searchQuery', e.target.value)}
                                                                            className="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none bg-white font-medium text-slate-800" 
                                                                        />
                                                                    ) : (
                                                                        <input 
                                                                            type="text" 
                                                                            placeholder="Search product..." 
                                                                            value={item.searchQuery}
                                                                            onChange={(e) => {
                                                                                updateItem(item.id, 'searchQuery', e.target.value);
                                                                                updateItem(item.id, 'showDropdown', true);
                                                                            }}
                                                                            onFocus={() => updateItem(item.id, 'showDropdown', true)}
                                                                            className="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none bg-white font-medium text-slate-800" 
                                                                        />
                                                                    )}
                                                                    
                                                                    {/* SKU Info */}
                                                                    {currentProductObj && (
                                                                        <div className="text-[10px] text-slate-400 font-medium mt-1 uppercase">
                                                                            {currentProductObj.sku}
                                                                        </div>
                                                                    )}

                                                                    {item.showDropdown && item.searchQuery && !item.isManual && (
                                                                        <div className="absolute z-30 mt-1 w-64 bg-white shadow-xl rounded-xl py-1 text-sm border border-slate-100 max-h-60 overflow-y-auto custom-scroll">
                                                                            {matchingProducts.length > 0 ? matchingProducts.map(p => (
                                                                                <div 
                                                                                    key={p.id} 
                                                                                    onClick={() => selectProduct(item.id, p)}
                                                                                    className="cursor-pointer py-2 px-3.5 hover:bg-blue-50 hover:text-blue-700 text-slate-700 flex flex-col border-b border-slate-50 last:border-0"
                                                                                >
                                                                                    <span className="font-semibold truncate text-xs">{p.name}</span>
                                                                                    <div className="flex justify-between text-[10px] text-slate-400 mt-0.5">
                                                                                        <span>{p.sku}</span>
                                                                                        <span className="font-medium text-blue-600">TSh {formatCurrency(p.unit_price)}</span>
                                                                                    </div>
                                                                                </div>
                                                                            )) : (
                                                                                <div className="py-2 px-3.5 text-slate-400 text-xs italic">
                                                                                    No matching products
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    )}
                                                                    {/* Backdrop wrapper to handle click outside */}
                                                                    {item.showDropdown && <div className="fixed inset-0 z-10" onClick={() => updateItem(item.id, 'showDropdown', false)}></div>}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        
                                                        {/* Description Column */}
                                                        <td className="py-4 px-3 align-top">
                                                            <textarea 
                                                                placeholder="Enter item description..." 
                                                                value={item.description}
                                                                onChange={(e) => updateItem(item.id, 'description', e.target.value)}
                                                                rows="2" 
                                                                className="w-full border border-slate-200 bg-slate-50/50 rounded-lg px-2.5 py-1.5 text-xs text-slate-600 focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none resize-y"
                                                            />
                                                        </td>
                                                        
                                                        {/* Quantity Column */}
                                                        <td className="py-4 px-3 align-top text-center">
                                                            <div className="inline-flex items-center border border-slate-200 rounded-lg overflow-hidden bg-white shadow-sm">
                                                                <button 
                                                                    type="button" 
                                                                    onClick={() => updateItem(item.id, 'quantity', Math.max(1, (parseFloat(item.quantity) || 1) - 1))}
                                                                    className="px-2 py-1 text-slate-500 hover:bg-slate-50 border-r border-slate-200 font-bold transition-colors text-xs"
                                                                >
                                                                    -
                                                                </button>
                                                                <input 
                                                                    type="number" 
                                                                    value={item.quantity} 
                                                                    onChange={(e) => updateItem(item.id, 'quantity', Math.max(1, parseFloat(e.target.value) || 1))}
                                                                    className="w-10 text-center text-xs border-0 focus:ring-0 p-1 font-semibold text-slate-700" 
                                                                />
                                                                <button 
                                                                    type="button" 
                                                                    onClick={() => updateItem(item.id, 'quantity', (parseFloat(item.quantity) || 1) + 1)}
                                                                    className="px-2 py-1 text-slate-500 hover:bg-slate-50 border-l border-slate-200 font-bold transition-colors text-xs"
                                                                >
                                                                    +
                                                                </button>
                                                            </div>
                                                        </td>
                                                        
                                                        {/* Unit Price Column */}
                                                        <td className="py-4 px-3 align-top">
                                                            <input 
                                                                type="number" 
                                                                value={item.unit_price}
                                                                onChange={(e) => updateItem(item.id, 'unit_price', Math.max(0, parseFloat(e.target.value) || 0))}
                                                                min="0" step="0.01" required
                                                                className="w-full border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs text-right focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none bg-white font-medium text-slate-800" 
                                                            />
                                                        </td>
                                                        
                                                        {/* Discount Column */}
                                                        <td className="py-4 px-3 align-top">
                                                            <input 
                                                                type="number" 
                                                                value={item.discount}
                                                                onChange={(e) => updateItem(item.id, 'discount', Math.max(0, Math.min(100, parseFloat(e.target.value) || 0)))}
                                                                min="0" max="100"
                                                                className="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs text-center focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none bg-white font-medium text-slate-850" 
                                                            />
                                                        </td>
                                                        
                                                        {/* Tax rate Column */}
                                                        <td className="py-4 px-3 align-top">
                                                            <select 
                                                                value={item.tax_rate} 
                                                                onChange={(e) => updateItem(item.id, 'tax_rate', parseFloat(e.target.value))}
                                                                className="w-full border border-slate-200 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none bg-white font-medium text-slate-700"
                                                            >
                                                                <option value="18">18%</option>
                                                                <option value="0">0%</option>
                                                            </select>
                                                        </td>
                                                        
                                                        {/* Line Total Column */}
                                                        <td className="py-4 px-3 align-top text-right text-xs font-semibold text-slate-700 bg-slate-50/20">
                                                            {formatCurrency(item.total)}
                                                        </td>
                                                        
                                                        {/* Action Column */}
                                                        <td className="py-4 px-2 align-top text-center">
                                                            <button 
                                                                type="button" 
                                                                onClick={() => removeItem(item.id)}
                                                                disabled={items.length === 1}
                                                                className="text-slate-400 hover:text-red-500 disabled:opacity-30 disabled:hover:text-slate-400 transition-colors focus:outline-none py-1 px-1.5"
                                                                title="Remove item"
                                                            >
                                                                <i className="fa-regular fa-trash-can"></i>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                )
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="flex justify-between items-center mt-6 border-t border-slate-100 pt-5">
                                    <button 
                                        type="button" 
                                        onClick={() => addItem(false)}
                                        className="px-4 py-2 border border-slate-200 hover:bg-slate-50 rounded-lg text-xs font-bold text-slate-600 transition-colors flex items-center gap-1.5 shadow-sm"
                                    >
                                        <i className="fa-solid fa-plus text-slate-400"></i>
                                        Add Row
                                    </button>
                                    <button 
                                        type="button" 
                                        onClick={clearAllItems}
                                        className="text-xs font-semibold text-red-500 hover:text-red-600 transition-colors py-2"
                                    >
                                        Clear All
                                    </button>
                                </div>
                            </div>
                            
                            {/* Notes / Terms Card */}
                            <div className="bg-white rounded-xl border border-slate-100 p-6 shadow-sm">
                                <div className="flex items-center gap-2 text-slate-800 font-semibold text-base mb-4">
                                    <i className="fa-solid fa-file-signature text-blue-500"></i>
                                    Notes / Terms
                                </div>
                                <textarea 
                                    name="notes" 
                                    value={formData.notes}
                                    onChange={handleFormChange}
                                    rows="4" 
                                    placeholder="Add any notes or terms for this quotation..."
                                    className="w-full border border-slate-200 rounded-lg px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/25 focus:border-blue-500 focus:outline-none bg-white placeholder-slate-400 font-medium text-slate-700 transition-all"
                                ></textarea>
                                <p className="text-[11px] text-slate-400 mt-2 font-medium">These notes will be visible on the quotation.</p>
                            </div>
                        </div>

                        {/* Right Side: Order Summary Card */}
                        <div className="lg:col-span-1">
                            <div className="bg-white rounded-xl border border-slate-100 p-6 shadow-sm sticky top-6">
                                <div className="flex items-center gap-2 text-slate-800 font-semibold text-base mb-5 border-b border-slate-100 pb-3">
                                    <i className="fa-solid fa-calculator text-blue-500"></i>
                                    Order Summary
                                </div>

                                <div className="space-y-4">
                                    <div className="flex justify-between py-1.5 text-sm text-slate-500 font-medium">
                                        <span>Subtotal (Excl. Tax)</span>
                                        <span className="font-semibold text-slate-800">TZS {formatCurrency(subtotal)}</span>
                                    </div>
                                    
                                    {/* Global Discount Row */}
                                    <div className="flex justify-between items-center py-2 border-t border-b border-slate-50">
                                        <span className="text-sm text-slate-500 font-medium shrink-0">Discount</span>
                                        <div className="flex items-center gap-2 shrink-0">
                                            <input 
                                                type="number" 
                                                value={globalDiscount}
                                                onChange={(e) => setGlobalDiscount(Math.max(0, parseFloat(e.target.value) || 0))}
                                                className="w-16 border border-slate-200 rounded-lg px-2 py-1 text-xs text-center font-medium focus:ring-1 focus:ring-blue-500 focus:outline-none"
                                            />
                                            <select 
                                                value={globalDiscountType}
                                                onChange={(e) => setGlobalDiscountType(e.target.value)}
                                                className="border border-slate-200 rounded-lg px-1 py-1 text-xs font-semibold focus:ring-1 focus:ring-blue-500 focus:outline-none bg-white text-slate-600"
                                            >
                                                <option value="%">%</option>
                                                <option value="Value">TZS</option>
                                            </select>
                                            <span className="font-semibold text-slate-700 text-xs shrink-0 w-20 text-right">
                                                -{formatCurrency(discountAmt)}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="flex justify-between py-1.5 text-sm text-slate-500 font-medium">
                                        <span>Tax (18%)</span>
                                        <span className="font-semibold text-slate-800">TZS {formatCurrency(totalTax)}</span>
                                    </div>
                                    
                                    <div className="border-t border-slate-100 pt-4 flex justify-between items-center">
                                        <span className="font-bold text-slate-900 text-sm uppercase tracking-wider">Grand Total</span>
                                        <span className="font-extrabold text-blue-600 text-xl tracking-tight">
                                            TZS {formatCurrency(grandTotal)}
                                        </span>
                                    </div>

                                    {/* Currency Info Alert */}
                                    <div className="bg-blue-50/70 border border-blue-100 rounded-xl p-3.5 text-blue-700 text-xs flex items-center gap-2.5 mt-6">
                                        <i className="fa-solid fa-circle-info text-blue-500 text-sm"></i>
                                        <span className="font-semibold text-[11px] leading-tight">All amounts are in Tanzanian Shilling (TZS)</span>
                                    </div>

                                    {/* Footer Buttons Block */}
                                    <div className="space-y-3 pt-6 border-t border-slate-100 mt-6">
                                        <button 
                                            type="submit" 
                                            disabled={isSubmitting}
                                            className="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold transition-all shadow-md shadow-blue-500/10 flex items-center justify-center gap-2 disabled:opacity-50"
                                        >
                                            {isSubmitting ? <i className="fas fa-spinner fa-spin"></i> : <i className="fa-solid fa-circle-check text-base"></i>}
                                            Create Quotation
                                        </button>
                                        <button 
                                            type="button" 
                                            onClick={(e) => handleSubmit(null, true)}
                                            disabled={isSubmitting}
                                            className="w-full py-3 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-xl text-sm font-semibold transition-all flex items-center justify-center gap-2 disabled:opacity-50"
                                        >
                                            <i className="fa-regular fa-floppy-disk text-slate-400"></i>
                                            Save Draft
                                        </button>
                                        <a 
                                            href="quotes.php"
                                            className="w-full py-3 bg-white hover:bg-red-50 text-red-500 border border-red-100 hover:border-red-200 rounded-xl text-sm font-semibold transition-all flex items-center justify-center gap-2"
                                        >
                                            <i className="fa-regular fa-circle-xmark"></i>
                                            Cancel
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </form>
            </div>
        );
    }

    const root = ReactDOM.createRoot(document.getElementById('react-root'));
    root.render(<CreateQuoteApp />);
</script>
</body>
</html>

