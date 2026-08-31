<?php 
// Clear opcache to prevent caching issues - UPDATED 2025-11-29
if (function_exists('opcache_reset')) { opcache_reset(); }
require_once '../../includes/functions.php';
global $pdo;

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES,'UTF-8'); } }

$quotes = [];
$error_message = '';

try {
    $status = $_GET['status'] ?? 'all';
    $search = $_GET['search'] ?? '';

    // Inspect columns for compatibility (schema drift handling)
    $cols = [];
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM erp_quotes");
        $cols = array_map(fn($r) => $r['Field'], $colStmt->fetchAll());
    } catch (Throwable $e) { /* ignore */ }

    $hasQuoteDate = in_array('quote_date', $cols, true);
    $hasCreatedAt = in_array('created_at', $cols, true);
    $hasUpdatedAt = in_array('updated_at', $cols, true);
    $orderCol = $hasQuoteDate ? 'quote_date' : ($hasCreatedAt ? 'created_at' : ($hasUpdatedAt ? 'updated_at' : 'id'));

    $hasExpiryDate = in_array('expiry_date', $cols, true);
    $hasValidUntil = in_array('valid_until', $cols, true);
    $expiryCol = $hasExpiryDate ? 'expiry_date' : ($hasValidUntil ? 'valid_until' : null);

    $amountFields = array_intersect(['total_amount','total','subtotal'], $cols);
    $amountField = reset($amountFields) ?: 'total_amount';

    $sql = "SELECT q.*, c.name AS customer_name FROM erp_quotes q JOIN erp_customers c ON q.customer_id = c.id WHERE 1=1";
    $params = [];
    
    // Status Filter
    if ($status !== 'all') { 
        $sql .= " AND q.status = ?"; 
        $params[] = $status; 
    }
    
    // Search Filter
    if (!empty($search)) {
        $sql .= " AND (q.quote_number LIKE ? OR c.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY q.$orderCol DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $quotes = $stmt->fetchAll();

    // Map dynamic fields for rendering convenience
    foreach ($quotes as &$q) {
        if (!isset($q['quote_date']) && $hasQuoteDate && isset($q[$orderCol])) { $q['quote_date'] = $q[$orderCol]; }
        if ($expiryCol && !isset($q['expiry_date']) && isset($q[$expiryCol])) { $q['expiry_date'] = $q[$expiryCol]; }
        if (!isset($q['total_amount']) && isset($q[$amountField])) { $q['total_amount'] = $q[$amountField]; }
    }
    unset($q);
} catch (PDOException $e) {
    $error_message = 'Database Error: ' . $e->getMessage();
    if (defined('APP_ENV') && APP_ENV === 'production') {
        error_log($e->getMessage());
        $error_message = 'Unable to load quotes. Please ensure the database is updated.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quotations - ERP</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    
    <style>
        /* Base resets and sidebar compatibility */
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        .page-wrapper { margin-left: 220px; min-height: 100vh; padding: 24px; width: calc(100% - 220px); }
        @media (max-width: 768px) { .page-wrapper { margin-left: 0; padding: 16px; width: 100%; } }
        /* Prevent Tailwind from breaking the custom sidebar */
        .sidebar { z-index: 50; }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper" id="react-root">
    <!-- React app mounts here -->
</div>

<script>
    // Pass PHP data to frontend state
    window.INITIAL_QUOTES = <?= json_encode($quotes) ?>;
    window.INITIAL_STATUS = <?= json_encode($status) ?>;
    window.INITIAL_SEARCH = <?= json_encode($search) ?>;
    window.ERROR_MESSAGE = <?= json_encode($error_message) ?>;
</script>

<script type="text/babel">
    const { useState, useEffect, useMemo } = React;

    function QuotesApp() {
        const [quotes, setQuotes] = useState(window.INITIAL_QUOTES || []);
        const [search, setSearch] = useState(window.INITIAL_SEARCH || '');
        const [statusFilter, setStatusFilter] = useState(window.INITIAL_STATUS || 'all');
        const [error, setError] = useState(window.ERROR_MESSAGE || '');
        const [selectedQuotes, setSelectedQuotes] = useState(new Set());
        const [isConverting, setIsConverting] = useState(false);

        const handleSearchChange = (e) => {
            setSearch(e.target.value);
        };

        const handleStatusChange = (e) => {
            setStatusFilter(e.target.value);
        };

        // Client-side filtering
        const filteredQuotes = useMemo(() => {
            return quotes.filter(q => {
                const searchLower = search.toLowerCase();
                const matchesSearch = !search || 
                    (q.quote_number && q.quote_number.toLowerCase().includes(searchLower)) ||
                    (q.customer_name && q.customer_name.toLowerCase().includes(searchLower));
                
                const matchesStatus = statusFilter === 'all' || q.status === statusFilter;

                return matchesSearch && matchesStatus;
            });
        }, [quotes, search, statusFilter]);

        const handleSelectAll = (e) => {
            if (e.target.checked) {
                const convertibleIds = filteredQuotes
                    .filter(q => q.status === 'accepted' || q.status === 'sent')
                    .map(q => q.id);
                setSelectedQuotes(new Set(convertibleIds));
            } else {
                setSelectedQuotes(new Set());
            }
        };

        const toggleQuoteSelection = (id) => {
            const newSet = new Set(selectedQuotes);
            if (newSet.has(id)) {
                newSet.delete(id);
            } else {
                newSet.add(id);
            }
            setSelectedQuotes(newSet);
        };

        const allConvertibleSelected = filteredQuotes.length > 0 && 
            filteredQuotes.filter(q => q.status === 'accepted' || q.status === 'sent').length > 0 &&
            filteredQuotes.filter(q => q.status === 'accepted' || q.status === 'sent').every(q => selectedQuotes.has(q.id));

        const getStatusBadge = (status) => {
            const badges = {
                draft: 'bg-amber-100 text-amber-800 border-amber-200',
                sent: 'bg-blue-100 text-blue-800 border-blue-200',
                accepted: 'bg-emerald-100 text-emerald-800 border-emerald-200',
                rejected: 'bg-red-100 text-red-800 border-red-200',
                converted: 'bg-emerald-100 text-emerald-800 border-emerald-200'
            };
            const className = badges[status] || 'bg-gray-100 text-gray-800 border-gray-200';
            return (
                <span className={`px-2.5 py-1 border rounded-md text-xs font-semibold uppercase tracking-wider ${className}`}>
                    {status}
                </span>
            );
        };

        const bulkConvertToInvoice = async () => {
            if (selectedQuotes.size === 0) return;
            if (!confirm(`Convert ${selectedQuotes.size} quotation(s) to invoice(s)?`)) return;

            setIsConverting(true);
            let successCount = 0;
            let failCount = 0;
            const errors = [];

            for (const quoteId of selectedQuotes) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'convert_to_invoice');
                    formData.append('id', quoteId);
                    
                    const response = await fetch('../api/quotes.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    
                    if (result.success) {
                        successCount++;
                    } else {
                        failCount++;
                        errors.push(`Quote #${quoteId}: ${result.message}`);
                    }
                } catch (error) {
                    failCount++;
                    errors.push(`Quote #${quoteId}: ${error.message}`);
                }
            }

            setIsConverting(false);
            
            let message = `Successfully converted ${successCount} quotation(s) to invoice(s).`;
            if (failCount > 0) {
                message += `\n\nFailed: ${failCount}\n${errors.join('\n')}`;
            }
            alert(message);
            window.location.reload();
        };

        const convertToInvoice = async (id) => {
            if (!confirm('Convert this quotation to an invoice?')) return; 
            try { 
                const formData = new FormData(); 
                formData.append('action', 'convert_to_invoice'); 
                formData.append('id', id); 
                const response = await fetch('../api/quotes.php', { method: 'POST', body: formData }); 
                const result = await response.json(); 
                if (result.success) { 
                    alert('Quotation converted to invoice successfully!'); 
                    window.location.href = 'view-invoice.php?id=' + result.invoice_id; 
                } else { 
                    alert('Failed: ' + result.message); 
                } 
            } catch (error) { 
                alert('Error: ' + error.message); 
            } 
        };

        const formatDate = (dateStr) => {
            if (!dateStr) return '-';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        };

        const formatCurrency = (amount) => {
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount || 0);
        };

        const DropdownMenu = ({ quote, canConvert }) => {
            const [isOpen, setIsOpen] = useState(false);
            return (
                <div className="relative inline-block text-left" onMouseLeave={() => setIsOpen(false)}>
                    <div>
                        <button 
                            type="button" 
                            className="inline-flex items-center justify-center w-8 h-8 rounded-full text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none transition-colors" 
                            onMouseEnter={() => setIsOpen(true)}
                        >
                            <i className="fas fa-ellipsis-v"></i>
                        </button>
                    </div>

                    {isOpen && (
                        <div className="origin-top-right absolute right-0 mt-2 w-36 rounded-lg shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-20 overflow-hidden">
                            <div className="py-1">
                                <a href={`view-quote.php?id=${quote.id}`} className="block px-4 py-2.5 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 flex items-center gap-3 font-medium transition-colors">
                                    <i className="fas fa-eye w-4 text-center"></i> View
                                </a>
                                {canConvert && (
                                    <button onClick={() => convertToInvoice(quote.id)} className="w-full text-left block px-4 py-2.5 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 flex items-center gap-3 font-medium transition-colors">
                                        <i className="fas fa-file-invoice w-4 text-center"></i> Convert
                                    </button>
                                )}
                                <div className="border-t border-gray-100 my-1"></div>
                                <button className="w-full text-left block px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 hover:text-red-700 flex items-center gap-3 font-medium transition-colors">
                                    <i className="fas fa-trash-alt w-4 text-center"></i> Delete
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            );
        };

        return (
            <div className="max-w-[1400px] mx-auto animate-fade-in" style={{ animation: 'fadeIn 0.5s ease-out forwards' }}>
                <style>{`
                    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
                `}</style>
                
                {/* Header Section */}
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                    <div>
                        <h2 className="text-3xl font-bold text-gray-900 tracking-tight">Quotations</h2>
                        <p className="mt-1 text-sm text-gray-500">Manage and convert your sales estimates</p>
                    </div>
                    
                    <div className="flex flex-wrap gap-3">
                        <a href="../index.php" className="inline-flex items-center gap-2 px-4 py-2 border border-gray-200 rounded-lg shadow-sm text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all">
                            <i className="fas fa-arrow-left"></i> Dashboard
                        </a>
                        {selectedQuotes.size > 0 && (
                            <button 
                                onClick={bulkConvertToInvoice} 
                                disabled={isConverting}
                                className="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 transition-all disabled:opacity-50 disabled:cursor-not-allowed transform active:scale-95"
                            >
                                {isConverting ? <i className="fas fa-spinner fa-spin"></i> : <i className="fas fa-file-invoice"></i>}
                                Generate Invoices ({selectedQuotes.size})
                            </button>
                        )}
                        <a href="create-quote.php" className="inline-flex items-center gap-2 px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all transform hover:translate-y-[-1px] active:scale-95">
                            <i className="fas fa-plus"></i> New Quotation
                        </a>
                    </div>
                </div>

                {error && (
                    <div className="mb-6 p-4 rounded-xl bg-red-50 border border-red-100 text-red-800 flex items-center gap-3 shadow-sm">
                        <i className="fas fa-exclamation-circle text-red-500 text-lg"></i>
                        <p className="font-medium text-sm">{error}</p>
                    </div>
                )}

                {/* Main Card */}
                <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    
                    {/* Filters Toolbar */}
                    <div className="p-5 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-4 bg-gray-50/50">
                        <div className="relative w-full sm:max-w-md">
                            <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                <i className="fas fa-search text-gray-400"></i>
                            </div>
                            <input 
                                type="text" 
                                value={search}
                                onChange={handleSearchChange}
                                placeholder="Search by number or customer..." 
                                className="block w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 text-sm font-medium text-gray-900 placeholder-gray-400 bg-white shadow-sm transition-all"
                            />
                        </div>
                        
                        <div className="w-full sm:w-auto relative">
                            <select 
                                value={statusFilter}
                                onChange={handleStatusChange}
                                className="appearance-none block w-full sm:w-56 pl-4 pr-10 py-2.5 text-sm font-medium text-gray-700 border-gray-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 rounded-xl border bg-white shadow-sm transition-all cursor-pointer"
                            >
                                <option value="all">All Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="sent">Sent</option>
                                <option value="accepted">Accepted</option>
                                <option value="rejected">Rejected</option>
                                <option value="converted">Converted</option>
                            </select>
                            <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-500">
                                <i className="fas fa-chevron-down text-xs"></i>
                            </div>
                        </div>
                    </div>

                    {/* Table Container */}
                    <div className="overflow-x-auto min-h-[400px]">
                        <table className="min-w-full divide-y divide-gray-100">
                            <thead className="bg-gray-50/80">
                                <tr>
                                    <th scope="col" className="px-6 py-4 text-left">
                                        <div className="flex items-center">
                                            <input 
                                                type="checkbox" 
                                                checked={allConvertibleSelected}
                                                onChange={handleSelectAll}
                                                className="h-4.5 w-4.5 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded cursor-pointer transition-colors"
                                            />
                                        </div>
                                    </th>
                                    <th scope="col" className="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Quote #</th>
                                    <th scope="col" className="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Customer</th>
                                    <th scope="col" className="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date</th>
                                    <th scope="col" className="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Expiry</th>
                                    <th scope="col" className="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Amount</th>
                                    <th scope="col" className="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" className="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-50">
                                {filteredQuotes.length === 0 ? (
                                    <tr>
                                        <td colSpan="8" className="px-6 py-24 text-center">
                                            <div className="mx-auto h-20 w-20 text-gray-200 mb-6 font-light"><i className="fas fa-file-invoice fa-3x"></i></div>
                                            <h3 className="text-lg font-semibold text-gray-900 mb-2">No quotations found</h3>
                                            <p className="text-gray-500 mb-8 max-w-sm mx-auto">Get started by creating a new quotation to send estimates to your customers.</p>
                                            <a href="create-quote.php" className="inline-flex items-center gap-2 px-5 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition-all transform hover:-translate-y-1">
                                                <i className="fas fa-plus"></i> Create First Quotation
                                            </a>
                                        </td>
                                    </tr>
                                ) : (
                                    filteredQuotes.map((q) => {
                                        const canConvert = q.status === 'accepted' || q.status === 'sent';
                                        return (
                                            <tr key={q.id} className="hover:bg-indigo-50/30 transition-colors group">
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="flex items-center">
                                                        <input 
                                                            type="checkbox" 
                                                            checked={selectedQuotes.has(q.id)}
                                                            onChange={() => toggleQuoteSelection(q.id)}
                                                            disabled={!canConvert}
                                                            className="h-4.5 w-4.5 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded cursor-pointer disabled:opacity-40 disabled:bg-gray-100 disabled:cursor-not-allowed transition-colors"
                                                        />
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <a href={`view-quote.php?id=${q.id}`} className="font-mono text-sm font-bold text-indigo-600 hover:text-indigo-800 transition-colors">{q.quote_number}</a>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="flex items-center">
                                                        <div className="h-8 w-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs mr-3 shadow-sm border border-indigo-200">
                                                            {q.customer_name ? q.customer_name.charAt(0).toUpperCase() : '?'}
                                                        </div>
                                                        <div className="text-sm font-semibold text-gray-900">{q.customer_name}</div>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium">
                                                    {formatDate(q.quote_date || q.created_at)}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium">
                                                    {formatDate(q.expiry_date)}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm">
                                                    <span className="font-bold text-gray-900">TSh {formatCurrency(q.total_amount)}</span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    {getStatusBadge(q.status)}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                                    <div className="flex items-center justify-center gap-2 lg:opacity-60 lg:group-hover:opacity-100 transition-opacity">
                                                        <a href={`view-quote.php?id=${q.id}`} className="w-8 h-8 rounded-full text-indigo-600 bg-indigo-50 hover:bg-indigo-100 flex items-center justify-center transition-colors" title="View Details">
                                                            <i className="fas fa-eye"></i>
                                                        </a>
                                                        <DropdownMenu quote={q} canConvert={canConvert} />
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
                
                {/* Pagination placeholder - could add if backend supports it */}
                {filteredQuotes.length > 0 && (
                    <div className="flex items-center justify-between mt-6 px-1">
                        <p className="text-sm text-gray-500 font-medium">
                            Showing <span className="font-bold text-gray-900">{filteredQuotes.length}</span> quotes
                        </p>
                    </div>
                )}
            </div>
        );
    }

    const root = ReactDOM.createRoot(document.getElementById('react-root'));
    root.render(<QuotesApp />);
</script>
</body>
</html>

