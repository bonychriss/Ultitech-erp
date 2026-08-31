<?php
require_once '../../../includes/config.php';
require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$_SESSION['active_module'] = 'sales';

// Check if ID is provided
if (!isset($_GET['id'])) {
    header("Location: index.php?module=sales");
    exit;
}

$id = $_GET['id'];

// Fetch customer details
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        die("Customer not found.");
    }

    // Fetch recent orders for this customer
    $stmtOrders = $pdo->prepare("SELECT * FROM sales_orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmtOrders->execute([$id]);
    $recent_orders = $stmtOrders->fetchAll();

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Customer | Sales Module</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    
    <style>
        /* Base resets and layout compatibility */
        * { margin:0; padding:0; box-sizing:border-box; } 
        body { background:#f4f6f9; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; } 
        
        /* Layout wrapper */
        .main-content { padding: 24px; max-width: 1400px; margin: 0 auto; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
    </style>
</head>
<body>
    <?php include '../../../includes/header_employee.php'; ?>
    
    <div class="main-content" id="react-root"></div>

    <script>
        // Use PHP function if it exists, otherwise provide fallback logic for React
        function getStatusColor(status) {
            const s = String(status).toLowerCase();
            if (['completed', 'delivered', 'paid', 'approved'].includes(s)) return 'green';
            if (['pending', 'processing', 'draft'].includes(s)) return 'blue';
            if (['cancelled', 'rejected', 'failed'].includes(s)) return 'red';
            return 'gray';
        }
    
        window.APP_DATA = {
            customer: <?= json_encode($customer) ?>,
            recentOrders: <?= json_encode($recent_orders) ?>
        };
    </script>

    <script type="text/babel">
        function formatCurrency(val) {
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val || 0);
        }

        function getCurrencySymbol(curr) {
            const symbols = { 'USD': '$', 'TZS': 'TSh ', 'KES': 'KSh ', 'EUR': 'â‚¬', 'GBP': 'Â£' };
            return symbols[curr] || (curr ? curr + ' ' : '$');
        }

        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function ViewCustomerApp() {
            const { customer, recentOrders } = window.APP_DATA;
            const currencySymbol = getCurrencySymbol(customer.currency || 'TZS');

            return (
                <div className="animate-fade-in">
                    {/* Header */}
                    <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                        <div>
                            <h2 className="text-2xl font-bold text-gray-900 tracking-tight">Customer Profile</h2>
                            <p className="mt-1 text-sm text-gray-500">View customer details and history</p>
                        </div>
                        <div className="flex gap-2">
                            <a href={`../orders/create.php?customer_id=${customer.id}`} className="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors">
                                <i className="fas fa-plus mr-2"></i> New Quote
                            </a>
                            <a href={`edit.php?id=${customer.id}&module=sales`} className="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                <i className="fas fa-pencil-alt mr-2"></i> Edit
                            </a>
                            <a href="index.php" className="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                <i className="fas fa-arrow-left mr-2"></i> Back
                            </a>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        {/* Customer Info Sidebar */}
                        <div className="lg:col-span-1 space-y-6">
                            {/* Profile Card */}
                            <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                                <div className="p-6 text-center">
                                    <div className="w-20 h-20 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                                        <i className="fas fa-building"></i>
                                    </div>
                                    <h3 className="text-xl font-bold text-gray-900 mb-1">{customer.company_name}</h3>
                                    <p className="text-sm text-gray-500 mb-4">{customer.customer_code}</p>
                                    
                                    <div className="flex justify-center gap-2 mb-6 text-sm">
                                        <span className="px-3 py-1 rounded-md border border-gray-200 bg-gray-50 text-gray-800 font-medium capitalize">
                                            {customer.customer_type}
                                        </span>
                                        <span className={`px-3 py-1 rounded-md font-medium text-white ${parseFloat(customer.current_balance) > 0 ? 'bg-red-500' : 'bg-green-500'}`}>
                                            Balance: {currencySymbol}{formatCurrency(customer.current_balance)}
                                        </span>
                                    </div>

                                    <div className="text-left border-t border-gray-100 pt-4 space-y-3">
                                        <div>
                                            <p className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Contact Person</p>
                                            <p className="text-sm text-gray-900">{customer.contact_person || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Email</p>
                                            <p className="text-sm text-gray-900">{customer.email || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Phone</p>
                                            <p className="text-sm text-gray-900">{customer.phone || 'N/A'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Address</p>
                                            <p className="text-sm text-gray-900 whitespace-pre-wrap">
                                                {[customer.address, customer.city, customer.country].filter(Boolean).join(', ') || 'N/A'}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Financial Details Card */}
                            <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                                <div className="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                                    <h4 className="font-bold text-gray-900 text-sm">Financial Details</h4>
                                </div>
                                <div className="p-6">
                                    <ul className="space-y-4">
                                        <li className="flex justify-between items-center text-sm">
                                            <span className="text-gray-500">Payment Terms</span>
                                            <span className="font-bold text-gray-900">{customer.payment_terms || 'N/A'}</span>
                                        </li>
                                        <li className="flex justify-between items-center text-sm border-t border-gray-100 pt-3">
                                            <span className="text-gray-500">Credit Limit</span>
                                            <span className="font-bold text-gray-900">{currencySymbol}{formatCurrency(customer.credit_limit)}</span>
                                        </li>
                                        <li className="flex justify-between items-center text-sm border-t border-gray-100 pt-3">
                                            <span className="text-gray-500">TIN Number</span>
                                            <span className="text-gray-900">{customer.tin || 'N/A'}</span>
                                        </li>
                                        <li className="flex justify-between items-center text-sm border-t border-gray-100 pt-3">
                                            <span className="text-gray-500">VRN Number</span>
                                            <span className="text-gray-900">{customer.vrn || 'N/A'}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        {/* Recent Activity Table */}
                        <div className="lg:col-span-2">
                            <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden h-full flex flex-col">
                                <div className="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                                    <h4 className="font-bold text-gray-900 text-sm">Recent Sales Orders</h4>
                                </div>
                                <div className="flex-1 overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Order #</th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Date</th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Total</th>
                                                <th scope="col" className="relative px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-100">
                                            {recentOrders.length === 0 ? (
                                                <tr>
                                                    <td colSpan="5" className="px-6 py-12 text-center text-gray-500">
                                                        <div className="flex flex-col items-center">
                                                            <i className="fas fa-box-open text-3xl text-gray-300 mb-3"></i>
                                                            <p className="text-gray-500">No recent orders found.</p>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ) : (
                                                recentOrders.map(order => {
                                                    const colorName = getStatusColor(order.status);
                                                    
                                                    // Mapping Tailwind classes dynamically requires complete string names or pre-defined classes
                                                    // Generating class map to ensure Tailwind compiles them correctly
                                                    const statusClasses = {
                                                        green: 'bg-green-50 text-green-700 border-green-200',
                                                        blue: 'bg-blue-50 text-blue-700 border-blue-200',
                                                        red: 'bg-red-50 text-red-700 border-red-200',
                                                        gray: 'bg-gray-50 text-gray-700 border-gray-200',
                                                    };
                                                    const badgeClass = statusClasses[colorName] || statusClasses.gray;

                                                    return (
                                                        <tr 
                                                            key={order.id} 
                                                            className="hover:bg-blue-50 transition-colors cursor-pointer"
                                                            onClick={() => window.location.href = `../orders/view.php?id=${order.id}`}
                                                        >
                                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-blue-600">
                                                                <span className="hover:underline">{order.order_number}</span>
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                                {formatDate(order.created_at)}
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap">
                                                                <span className={`px-2.5 py-1 inline-flex text-xs leading-5 font-semibold rounded-md border capitalize ${badgeClass}`}>
                                                                    {order.status}
                                                                </span>
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                                                {currencySymbol}{formatCurrency(order.total_amount)}
                                                            </td>
                                                            <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                                <a 
                                                                    href={`../orders/view.php?id=${order.id}`} 
                                                                    className="inline-flex items-center justify-center h-8 w-8 rounded-full text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-colors" 
                                                                    title="View Order"
                                                                    onClick={(e) => e.stopPropagation()}
                                                                >
                                                                    <i className="fas fa-chevron-right"></i>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    );
                                                })
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('react-root'));
        root.render(<ViewCustomerApp />);
    </script>
</body>
</html>

