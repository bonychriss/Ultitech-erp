<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$_SESSION['active_module'] = 'sales';


$search = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$limit = 10;
$offset = ($page - 1) * $limit;
$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
$showCreatedToast = (($_GET['msg'] ?? '') === 'created');

try {
    $where = "WHERE 1=1";
    $params = [];
    
    if ($search) {
        $where .= " AND (company_name LIKE ? OR customer_code LIKE ? OR contact_person LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $scope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('customers') : array('', array());
    if (!empty($scope[0])) {
        $where .= $scope[0];
        $params = array_merge($params, $scope[1]);
    }

    // Count total
    $stmt = $salesDb->prepare("SELECT COUNT(*) FROM customers $where");
    $stmt->execute($params);
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    
    // Fetch records
    $query = "SELECT *, COALESCE((SELECT SUM(total_amount) FROM invoices i WHERE i.customer_id = customers.id AND i.status != 'cancelled'), 0) as total_purchases FROM customers $where ORDER BY company_name ASC LIMIT $limit OFFSET $offset";
    $stmt = $salesDb->prepare($query);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Error loading customers: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers | Sales Module</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/sales-mobile.css" rel="stylesheet">

    <script>
        tailwind.config = { corePlugins: { preflight: false } };
    </script>
    
    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* Base resets and layout compatibility */
        * { margin:0; padding:0; box-sizing:border-box; } 
        body {
            background-color: #F9F9F9;
            font-family: 'Outfit', system-ui, -apple-system, sans-serif;
            color: #374151;
            font-size: 16px;
        }
        
        /* Layout wrapper */
        .main-content {
            padding: 0;
            max-width: 100%;
            margin: 0 auto;
            min-height: calc(100vh - 64px);
        }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
    </style>
</head>
<body>
    <?php include '../../../includes/header_employee.php'; ?>
    
    <div class="main-content" id="react-root"></div>

    <script>
        window.APP_DATA = {
            customers: <?= sales_json_script($customers) ?>,
            search: <?= sales_json_script($search) ?>,
            page: <?= (int)$page ?>,
            totalPages: <?= (int)$total_pages ?>,
            showCreatedToast: <?= $showCreatedToast ? 'true' : 'false' ?>
        };
    </script>

    <script type="text/babel">
        const { useEffect, useState } = React;

        function CustomersApp() {
            const { customers, search: initialSearch, page, totalPages, showCreatedToast } = window.APP_DATA;
            const [search, setSearch] = useState(initialSearch || '');

            useEffect(() => {
                if (!showCreatedToast) return;

                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    window.history.replaceState({}, '', url.toString());
                } catch (err) {
                    // Ignore URL cleanup errors and keep the toast behavior.
                }

                if (window.Swal) {
                    window.Swal.fire({
                        toast: true,
                        position: 'top',
                        icon: 'success',
                        title: 'Customer created successfully.',
                        showConfirmButton: false,
                        timer: 2600,
                        timerProgressBar: true,
                        background: '#f0fdf4',
                        color: '#166534',
                        customClass: {
                            popup: 'shadow-lg'
                        }
                    });
                }
            }, [showCreatedToast]);

            const handleSearchSubmit = (e) => {
                e.preventDefault();
                window.location.href = `?page=1&search=${encodeURIComponent(search)}`;
            };

            const handleClearSearch = () => {
                window.location.href = '?page=1&search=';
            };

            const formatCurrency = (val) => new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val || 0);

            const getCurrencySymbol = (curr) => {
                const symbols = { 'USD': '$', 'TZS': 'TSh ', 'KES': 'KSh ', 'EUR': 'Ã¢â€šÂ¬', 'GBP': 'Ã‚Â£' };
                return symbols[curr] || (curr ? curr + ' ' : '$');
            };

            // Pagination renderer
            const renderPagination = () => {
                if (totalPages <= 1) return null;
                
                const pages = [];
                for (let i = 1; i <= totalPages; i++) {
                    pages.push(
                        <a 
                            key={i}
                            href={`?page=${i}&search=${encodeURIComponent(search)}`}
                            className={`relative inline-flex items-center px-4 py-2 border text-sm font-medium transition-colors ${
                                page === i 
                                ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' 
                                : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                            }`}
                        >
                            {i}
                        </a>
                    );
                }

                return (
                    <div className="bg-white px-4 py-3 border-t border-gray-200 flex items-center justify-between sm:px-6 mt-4 rounded-b-lg">
                        <div className="flex-1 flex items-center justify-center sm:justify-center">
                            <nav className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                <a 
                                    href={page > 1 ? `?page=${page - 1}&search=${encodeURIComponent(search)}` : '#'}
                                    className={`relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium ${page <= 1 ? 'text-gray-300 cursor-not-allowed pointer-events-none' : 'text-gray-500 hover:bg-gray-50'}`}
                                >
                                    <span className="sr-only">Previous</span>
                                    <i className="fas fa-chevron-left w-5 h-5 flex items-center justify-center"></i>
                                </a>
                                {pages}
                                <a 
                                    href={page < totalPages ? `?page=${page + 1}&search=${encodeURIComponent(search)}` : '#'}
                                    className={`relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium ${page >= totalPages ? 'text-gray-300 cursor-not-allowed pointer-events-none' : 'text-gray-500 hover:bg-gray-50'}`}
                                >
                                    <span className="sr-only">Next</span>
                                    <i className="fas fa-chevron-right w-5 h-5 flex items-center justify-center"></i>
                                </a>
                            </nav>
                        </div>
                    </div>
                );
            };

            return (
                <div className="bg-[#F9F9F9]">
                    <div className="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
                        <div className="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                            <a href="/select-module.php" className="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                                <i className="fas fa-arrow-left text-sm"></i> Modules
                            </a>
                            <div className="flex items-center gap-2 min-w-0">
                                <h1 className="text-xl font-bold text-gray-900 truncate m-0">Customers</h1>
                            </div>
                            <div className="flex-1 min-w-[8px]"></div>
                            <a href="add.php" className="btn dr-btn-primary border-0 rounded-md fw-bold px-3 py-2 inline-flex items-center gap-2">
                                <i className="fas fa-plus text-sm"></i> Add customer
                            </a>
                        </div>
                        <div className="px-4 py-2 flex flex-wrap items-center gap-2 text-base text-gray-600 bg-gray-50/80 border-b border-gray-100">
                            <span className="inline-flex items-center gap-1.5"><i className="fas fa-users text-gray-400 text-sm"></i>Manage client relationships</span>
                        </div>
                    </div>

                    <div className="px-4 pt-4 pb-8 animate-fade-in">
                        {/* Search Card */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-4">
                        <form onSubmit={handleSearchSubmit} className="flex flex-col sm:flex-row gap-3">
                            <div className="relative flex-grow">
                                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i className="fas fa-search text-gray-400"></i>
                                </div>
                                <input 
                                    type="text" 
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search by name, code or contact person..." 
                                    className="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                />
                            </div>
                            <button type="submit" className="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                Search
                            </button>
                            {initialSearch && (
                                <button type="button" onClick={handleClearSearch} className="inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                    Clear
                                </button>
                            )}
                        </form>
                    </div>

                    {/* Data Table */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-[#1c2331]">
                                    <tr>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-white uppercase tracking-wider">Profile</th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-white uppercase tracking-wider">Code</th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-white uppercase tracking-wider">Company</th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-white uppercase tracking-wider">Contact</th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-white uppercase tracking-wider">Type</th>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-white uppercase tracking-wider">Total Purchases</th>
                                        <th scope="col" className="relative px-6 py-3 text-right text-xs font-bold text-white uppercase tracking-wider">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-100">
                                    {customers.length === 0 ? (
                                        <tr>
                                            <td colSpan="7" className="px-6 py-12 text-center text-gray-500">
                                                <div className="flex flex-col items-center">
                                                    <i className="fas fa-users text-4xl text-gray-300 mb-3"></i>
                                                    <p className="text-gray-500 text-lg">No customers found.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    ) : (
                                        customers.map((c) => {
                                            const initials = c.company_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
                                            const colors = ['bg-blue-100 text-blue-600', 'bg-green-100 text-green-600', 'bg-yellow-100 text-yellow-600', 'bg-purple-100 text-purple-600', 'bg-pink-100 text-pink-600'];
                                            const colorIndex = c.company_name.charCodeAt(0) % colors.length;
                                            const avatarColor = colors[colorIndex];

                                            return (
                                            <tr 
                                                key={c.id} 
                                                className="hover:bg-blue-50 transition-colors cursor-pointer"
                                                onClick={() => window.location.href = `view.php?id=${c.id}`}
                                            >
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className={`h-10 w-10 rounded-full flex items-center justify-center font-bold text-sm ${avatarColor}`}>
                                                        {initials}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                                    {c.customer_code}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="text-sm font-bold text-gray-900">
                                                        <span className="hover:text-blue-600 transition-colors">{c.company_name}</span>
                                                    </div>
                                                    <div className="text-sm text-gray-500">{c.email}</div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="text-sm text-gray-900 font-medium">{c.contact_person}</div>
                                                    <div className="text-sm text-gray-500">{c.phone}</div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className="px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-md border border-gray-200 bg-gray-100 text-gray-800 capitalize">
                                                        {c.customer_type}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className="text-sm font-bold text-gray-900">
                                                        {getCurrencySymbol(c.currency || 'TZS')}{formatCurrency(c.total_purchases)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <a 
                                                        href={`edit.php?id=${c.id}&module=sales`} 
                                                        className="inline-flex items-center justify-center h-8 w-8 rounded-full text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-colors" 
                                                        title="Edit"
                                                        onClick={(e) => e.stopPropagation()}
                                                    >
                                                        <i className="fas fa-edit text-lg"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {renderPagination()}
                    </div>
                </div>
            </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('react-root'));
        root.render(<CustomersApp />);
    </script>
</body>
</html>

