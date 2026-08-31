<?php
if (file_exists('../../includes/functions.php')) {
    require_once '../../includes/functions.php';
} else {
    require_once '../includes/functions.php';
}
global $pdo;
$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT q.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone, c.address as customer_address, c.tax_id as customer_tax_id, u.full_name as created_by_name FROM erp_quotes q JOIN erp_customers c ON q.customer_id = c.id LEFT JOIN users u ON q.created_by = u.id WHERE q.id = ?");
$stmt->execute([$id]);
$quote = $stmt->fetch();
if (!$quote)
    die("Quote not found");
$items = $pdo->prepare("SELECT qi.*, p.name as product_name FROM erp_quote_items qi JOIN erp_products p ON qi.product_id = p.id WHERE qi.quote_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
// Fetch Bank Accounts for display
$bankAccounts = $pdo->query("SELECT * FROM erp_bank_accounts WHERE status = 'active'")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quote <?= htmlspecialchars($quote['quote_number']) ?> - ERP</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>

    <!-- Configure Tailwind theme colors to match Odoo-like aesthetics -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        odoo: {
                            brand: '#714B67',
                            brandLight: '#8a5e7f',
                            brandDark: '#5b3c53',
                            action: '#008784',
                            actionDark: '#006c6a',
                        }
                    }
                }
            }
        }
    </script>
    
    <style>
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        .page-wrapper { margin-left: 220px; min-height: 100vh; padding: 0; width: calc(100% - 220px); display: flex; flex-direction: column; }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0; width: 100%; } }
        .sidebar { z-index: 50; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
        
        @media print {
            .sidebar, .action-bar, .control-panel, .no-print { display: none !important; }
            .page-wrapper { margin-left: 0 !important; width: 100% !important; padding: 0 !important; background: white; }
            body { background: white; }
            .print-container { margin: 0; padding: 0; box-shadow: none; border: none; }
            
            tr, table tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            thead {
                display: table-header-group !important;
            }
            .totals-area, .totals-table, .chatter, .note-box, .sheet-header-title, .form-grid, .print-container > div {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
        }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper" id="react-root"></div>

<script>
    // Inject PHP data
    window.APP_DATA = {
        quote: <?= json_encode($quote) ?>,
        items: <?= json_encode($items) ?>,
        bankAccounts: <?= json_encode($bankAccounts) ?>,
        company: {
            logo_url: <?= json_encode(getCompanyLogoUrl()) ?>,
            name: <?= json_encode(($nameVal = getCompanySetting('company_name')) && trim($nameVal) !== '' ? $nameVal : (defined('COMPANY_NAME') ? COMPANY_NAME : 'ULTIMATE GENERAL TRADING')) ?>,
            address: <?= json_encode(($addrVal = getCompanySetting('company_address')) && trim($addrVal) !== '' ? $addrVal : 'Plot 123, Standard Street, Dar es Salaam') ?>,
            phone: <?= json_encode(($phoneVal = getCompanySetting('company_phone')) && trim($phoneVal) !== '' ? $phoneVal : '+255 123 456 789') ?>,
            email: <?= json_encode(($emailVal = getCompanySetting('company_email')) && trim($emailVal) !== '' ? $emailVal : 'info@company.com') ?>,
            tin: <?= json_encode(getCompanySetting('company_tin') ?: '') ?>,
            vat: <?= json_encode(getCompanySetting('company_vat') ?: '') ?>,
            location: <?= json_encode(getCompanySetting('company_location') ?: '') ?>
        }
    };
</script>

<script type="text/babel">
    const { useState, useEffect } = React;

    function ViewQuoteApp() {
        const { quote: initialQuote, items, bankAccounts } = window.APP_DATA;
        const [quote, setQuote] = useState(initialQuote);
        const [isProcessing, setIsProcessing] = useState(false);

        const formatDate = (dateStr) => {
            if (!dateStr) return '-';
            return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        };

        const formatCurrency = (amount) => {
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount || 0);
        };

        const handleAction = async (action, confirmMsg = null) => {
            if (confirmMsg && !confirm(confirmMsg)) return;
            
            setIsProcessing(true);
            try {
                const formData = new FormData();
                formData.append('action', action);
                formData.append('id', quote.id);
                
                const response = await fetch('../api/quotes.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    if (action === 'convert_to_invoice' && result.invoice_id) {
                        alert('Quotation converted to invoice successfully!');
                        window.location.href = `view-invoice.php?id=${result.invoice_id}`;
                        return;
                    }
                    // Update state or reload
                    alert('Action completed successfully.');
                    window.location.reload();
                } else {
                    alert('Failed: ' + result.message);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                setIsProcessing(false);
            }
        };

        // Status pipeline definition
        const statuses = [
            { id: 'draft', label: 'Quotation' },
            { id: 'sent', label: 'Quotation Sent' },
            { id: 'accepted', label: 'Accepted' },
            { id: 'rejected', label: 'Rejected', hidden: quote.status !== 'rejected' },
            { id: 'converted', label: 'Converted', hidden: quote.status !== 'converted' && quote.status !== 'draft' && quote.status !== 'sent' && quote.status !== 'accepted' }
        ].filter(s => !s.hidden);

        const pipelineIndex = statuses.findIndex(s => s.id === quote.status);

        return (
            <div className="flex-1 flex flex-col">
                {/* Control Panel / Breadcrumbs */}
                <div className="bg-white border-b border-gray-200 px-6 py-3 flex justify-between items-center no-print">
                    <div className="flex items-center gap-2 text-sm text-gray-500">
                        <a href="quotes.php" className="hover:text-odoo-brand transition-colors">Quotations</a>
                        <i className="fas fa-chevron-right text-xs text-gray-400 mx-1"></i>
                        <span className="font-semibold text-gray-900">{quote.quote_number}</span>
                    </div>
                </div>

                {/* Sticky Action Bar */}
                <div className="bg-white border-b border-gray-200 px-6 py-3 flex flex-wrap justify-between items-center sticky top-0 z-40 shadow-sm no-print gap-4">
                    <div className="flex flex-wrap items-center gap-2">
                        {quote.status === 'draft' && (
                            <button onClick={() => handleAction('mark_sent')} disabled={isProcessing} className="px-4 py-2 bg-odoo-brand text-white text-sm font-semibold rounded shadow-sm hover:bg-odoo-brandDark transition-colors disabled:opacity-50 ring-1 ring-odoo-brand">
                                Mark as Sent
                            </button>
                        )}
                        {(quote.status === 'draft' || quote.status === 'sent') && (
                            <button onClick={() => handleAction('convert_to_invoice', 'Convert this quotation to an invoice?')} disabled={isProcessing} className="px-4 py-2 bg-odoo-action text-white text-sm font-semibold rounded shadow-sm hover:bg-odoo-actionDark transition-colors disabled:opacity-50 ring-1 ring-odoo-action">
                                Create Invoice
                            </button>
                        )}
                        <button onClick={() => window.print()} className="px-4 py-2 bg-white text-gray-700 text-sm font-semibold rounded shadow-sm border border-gray-300 hover:bg-gray-50 transition-colors">
                            <i className="fas fa-print mr-1.5"></i> Print
                        </button>
                        {(quote.status === 'draft' || quote.status === 'sent') && (
                            <a href={`edit-quote.php?id=${quote.id}`} className="px-4 py-2 bg-white text-gray-700 text-sm font-semibold rounded shadow-sm border border-gray-300 hover:bg-gray-50 transition-colors inline-block">
                                Edit
                            </a>
                        )}
                        {(quote.status === 'draft' || quote.status === 'sent') && (
                            <button onClick={() => handleAction('cancel_quote', 'Are you sure you want to cancel this quotation?')} disabled={isProcessing} className="px-4 py-2 bg-white text-red-600 text-sm font-semibold rounded shadow-sm border border-gray-300 hover:bg-red-50 transition-colors">
                                Cancel
                            </button>
                        )}
                    </div>

                    {/* Status Pipeline */}
                    <div className="flex items-center overflow-hidden border border-gray-300 rounded-md shadow-sm">
                        {statuses.map((status, index) => {
                            const isCurrent = quote.status === status.id;
                            const isPast = pipelineIndex > index;
                            
                            let bgClass = "bg-white text-gray-500";
                            let borderClass = "border-l border-gray-300";
                            if (isCurrent) {
                                bgClass = "bg-blue-50 text-blue-700 font-bold";
                            } else if (isPast) {
                                bgClass = "bg-gray-100 text-gray-700";
                            }
                            
                            if (index === 0) borderClass = "";

                            return (
                                <div key={status.id} className={`px-4 py-2 text-sm relative flex items-center ${bgClass} ${borderClass}`}>
                                    {isPast && <i className="fas fa-check-circle text-green-500 mr-1.5 line-through-none opacity-70"></i>}
                                    {status.label}
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Document Main Content */}
                <div className="flex-1 p-6 flex justify-center no-print">
                    <div className="w-full max-w-5xl bg-white shadow-md border border-gray-200 p-10 print-container animate-fade-in relative overflow-hidden ring-1 ring-black ring-opacity-5">
                        
                        {/* Status Watermark for Print / View */}
                        {(quote.status === 'rejected' || quote.status === 'cancelled') && (
                            <div className="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-9xl font-bold text-red-500 opacity-10 rotate-[-30deg] pointer-events-none uppercase tracking-widest z-0">
                                {quote.status}
                            </div>
                        )}
                        {quote.status === 'converted' && (
                            <div className="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-9xl font-bold text-emerald-500 opacity-10 rotate-[-15deg] pointer-events-none uppercase tracking-widest z-0">
                                INVOICED
                            </div>
                        )}

                        <div className="relative z-10">
                            {/* Header */}
                            <div className="flex justify-between items-start mb-12">
                                <div>
                                    <h1 className="text-4xl font-extrabold text-gray-900 tracking-tight">Quotation</h1>
                                    <h2 className="text-2xl font-bold text-odoo-brand mt-1">{quote.quote_number}</h2>
                                </div>
                                <div className="text-right flex flex-col items-end">
                                    {window.APP_DATA.company?.logo_url ? (
                                        <img src={window.APP_DATA.company.logo_url} alt="Company Logo" className="h-16 object-contain opacity-90" onError={(e) => e.target.style.display='none'} />
                                    ) : null}
                                    <div className="mt-4 text-sm text-gray-500 max-w-[300px]">
                                        <p className="font-bold text-gray-800">{window.APP_DATA.company?.name}</p>
                                        <p className="whitespace-pre-wrap">
                                            {window.APP_DATA.company?.address}
                                            {window.APP_DATA.company?.location ? `, ${window.APP_DATA.company.location}` : ''}
                                        </p>
                                        {window.APP_DATA.company?.phone && <p>Phone: {window.APP_DATA.company.phone}</p>}
                                        {window.APP_DATA.company?.email && <p>Email: {window.APP_DATA.company.email}</p>}
                                        {window.APP_DATA.company?.tin && <p>TIN: {window.APP_DATA.company.tin}</p>}
                                        {window.APP_DATA.company?.vat && <p>VAT: {window.APP_DATA.company.vat}</p>}
                                    </div>
                                </div>
                            </div>

                            {/* Info Grid */}
                            <div className="grid grid-cols-2 gap-10 mb-12">
                                <div>
                                    <h3 className="text-xs font-bold text-gray-400 tracking-wider uppercase mb-2">Customer Address</h3>
                                    <p className="font-bold text-lg text-gray-900 mb-1" style={{ fontSize: '1.25rem' }}>{quote.customer_name}</p>
                                    <p className="text-gray-600 leading-relaxed whitespace-pre-wrap">{quote.customer_address}</p>
                                    {quote.customer_email && <p className="text-gray-600">{quote.customer_email}</p>}
                                    {quote.customer_phone && <p className="text-gray-600">{quote.customer_phone}</p>}
                                    {(() => {
                                        const taxId = quote.customer_tax_id || '';
                                        if (!taxId) return null;
                                        if (taxId.includes('/')) {
                                            const parts = taxId.split('/');
                                            const tin = parts[0].trim();
                                            const vrn = parts[1].trim();
                                            return (
                                                <>
                                                    {tin && <p className="text-gray-600">TIN: {tin}</p>}
                                                    {vrn && <p className="text-gray-600">VRN: {vrn} Tax ID: {taxId}</p>}
                                                </>
                                            );
                                        } else {
                                            return <p className="text-gray-600">TIN: {taxId}</p>;
                                        }
                                    })()}
                                </div>
                                <div className="bg-gray-50 p-6 rounded-lg border border-gray-100">
                                    <div className="grid grid-cols-2 gap-y-4">
                                        <div>
                                            <p className="text-xs font-bold text-gray-400 tracking-wider uppercase mb-1">Quotation Date</p>
                                            <p className="font-semibold text-gray-900">{formatDate(quote.quote_date || quote.created_at)}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-gray-400 tracking-wider uppercase mb-1">Expiration</p>
                                            <p className="font-semibold text-gray-900">{formatDate(quote.expiry_date)}</p>
                                        </div>
                                        {quote.reference && (
                                            <div className="col-span-2">
                                                <p className="text-xs font-bold text-gray-400 tracking-wider uppercase mb-1">Reference</p>
                                                <p className="font-semibold text-gray-900">{quote.reference}</p>
                                            </div>
                                        )}
                                        <div className="col-span-2">
                                            <p className="text-xs font-bold text-gray-400 tracking-wider uppercase mb-1">Salesperson</p>
                                            <p className="font-semibold text-gray-900">{quote.created_by_name || 'System User'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Line Items */}
                            <div className="mb-10">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="border-b-2 border-gray-800 text-sm">
                                            <th className="py-3 px-2 font-bold text-gray-800 w-[50%]">Description</th>
                                            <th className="py-3 px-2 font-bold text-gray-800 text-right">Quantity</th>
                                            <th className="py-3 px-2 font-bold text-gray-800 text-right">Unit Price</th>
                                            <th className="py-3 px-2 font-bold text-gray-800 text-right">Taxes</th>
                                            <th className="py-3 px-2 font-bold text-gray-800 text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200">
                                        {items.map((item, idx) => (
                                            <tr key={idx} className="group">
                                                <td className="py-4 px-2">
                                                    <p className="font-semibold text-gray-900">{item.product_name || 'Custom Item'}</p>
                                                    {item.description && <p className="text-sm text-gray-500 mt-1 whitespace-pre-wrap">{item.description}</p>}
                                                </td>
                                                <td className="py-4 px-2 text-right text-gray-700">{parseFloat(item.quantity).toFixed(2)}</td>
                                                <td className="py-4 px-2 text-right text-gray-700">{formatCurrency(item.unit_price)}</td>
                                                <td className="py-4 px-2 text-right text-gray-700">{parseFloat(item.tax_rate) > 0 ? `${parseFloat(item.tax_rate)}%` : '-'}</td>
                                                <td className="py-4 px-2 text-right font-medium text-gray-900">{formatCurrency(item.total)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Totals */}
                            <div className="flex justify-end mb-12">
                                <div className="w-1/2 max-w-sm">
                                    <div className="flex justify-between py-2 border-b border-gray-100 text-gray-600">
                                        <span>Untaxed Amount</span>
                                        <span className="font-medium text-gray-900">{formatCurrency(quote.subtotal)}</span>
                                    </div>
                                    {parseFloat(quote.tax_amount) > 0 && (
                                        <div className="flex justify-between py-2 border-b border-gray-100 text-gray-600">
                                            <span>Taxes</span>
                                            <span className="font-medium text-gray-900">{formatCurrency(quote.tax_amount)}</span>
                                        </div>
                                    )}
                                    <div className="flex justify-between py-4 text-xl font-bold border-b-2 border-gray-800">
                                        <span className="text-gray-900">Total</span>
                                        <span className="text-odoo-brand">TSh {formatCurrency(quote.total_amount)}</span>
                                    </div>
                                </div>
                            </div>

                            {/* Terms and Bank Accounts */}
                            <div className="border-t border-gray-200 pt-8 grid grid-cols-1 md:grid-cols-2 gap-8 text-sm">
                                {quote.notes && (
                                    <div>
                                        <h4 className="font-bold text-gray-900 mb-2 tracking-wide uppercase text-xs">Terms & Conditions</h4>
                                        <div className="text-gray-600 whitespace-pre-wrap p-4 bg-gray-50 rounded-lg border border-gray-100">
                                            {quote.notes}
                                        </div>
                                    </div>
                                )}
                                
                                {bankAccounts && bankAccounts.length > 0 && (
                                    <div>
                                        <h4 className="font-bold text-gray-900 mb-2 tracking-wide uppercase text-xs">Payment Details</h4>
                                        <div className="grid gap-3">
                                            {bankAccounts.map((account, idx) => (
                                                <div key={idx} className="p-4 bg-blue-50/50 rounded-lg border border-blue-100 flex items-start gap-3">
                                                    <i className="fas fa-university text-blue-500 mt-1"></i>
                                                    <div>
                                                        <p className="font-bold text-gray-900">{account.bank_name}</p>
                                                        <p className="text-gray-700 font-mono mt-0.5">{account.account_number}</p>
                                                        <p className="text-gray-500 text-xs mt-1 uppercase">{account.account_name}</p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    const root = ReactDOM.createRoot(document.getElementById('react-root'));
    root.render(<ViewQuoteApp />);
</script>
</body>
</html>
