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
$error = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = $_POST['company_name'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $city = $_POST['city'] ?? '';
    $country = $_POST['country'] ?? '';
    $tin = $_POST['tin'] ?? '';
    $vrn = $_POST['vrn'] ?? '';
    $customer_type = $_POST['customer_type'] ?? 'retail';
    $payment_terms = $_POST['payment_terms'] ?? 'Immediate';
    $currency = $_POST['currency'] ?? 'TZS';
    $credit_limit = floatval($_POST['credit_limit'] ?? 0);
    $notes = $_POST['notes'] ?? '';

    // Basic Validation (keep edit usable even when optional fields are empty)
    if (trim((string)$company_name) === '' || trim((string)$contact_person) === '' || trim((string)$email) === '' || trim((string)$phone) === '') {
        $error = "Company name, contact person, email and phone are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
         $error = "Invalid email format.";
    } else {
        try {
            // Ensure columns exist
            ensureCustomerColumnsExist();

            $stmt = $pdo->prepare("UPDATE customers SET 
                company_name = ?, contact_person = ?, email = ?, phone = ?, 
                address = ?, city = ?, country = ?, tax_number = ?, 
                tin = ?, vrn = ?,
                customer_type = ?, payment_terms = ?, currency = ?, credit_limit = ?, notes = ?
                WHERE id = ?");
            
            $stmt->execute([
                $company_name, $contact_person, $email, $phone, 
                $address !== '' ? $address : null,
                $city !== '' ? $city : null,
                $country !== '' ? $country : null,
                ($tin !== '' ? ($tin . ($vrn ? " / $vrn" : "")) : null),
                $tin !== '' ? $tin : null,
                $vrn !== '' ? $vrn : null,
                $customer_type, $payment_terms, $currency, $credit_limit, $notes,
                $id
            ]);

            header("Location: view.php?id=$id&status=updated&module=sales");
            exit;
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Fetch current data
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        die("Customer not found.");
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Customer | Sales Module</title>
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
        window.APP_DATA = {
            customer: <?= json_encode($customer) ?>,
            error: <?= json_encode($error) ?>,
            postData: <?= json_encode($_POST) ?>
        };
    </script>
    <script type="text/babel">
        function EditCustomerApp() {
            const { customer, error, postData } = window.APP_DATA;
            const [formData, setFormData] = React.useState({
                company_name: postData.company_name ?? customer.company_name ?? '',
                contact_person: postData.contact_person ?? customer.contact_person ?? '',
                email: postData.email ?? customer.email ?? '',
                phone: postData.phone ?? customer.phone ?? '',
                tin: postData.tin ?? customer.tin ?? '',
                vrn: postData.vrn ?? customer.vrn ?? '',
                address: postData.address ?? customer.address ?? '',
                city: postData.city ?? customer.city ?? '',
                country: postData.country ?? customer.country ?? '',
                customer_type: postData.customer_type ?? customer.customer_type ?? 'retail',
                payment_terms: postData.payment_terms ?? customer.payment_terms ?? 'Immediate',
                currency: postData.currency ?? customer.currency ?? 'TZS',
                credit_limit: postData.credit_limit ?? customer.credit_limit ?? 0,
                notes: postData.notes ?? customer.notes ?? ''
            });

            const handleChange = (e) => {
                const { name, value } = e.target;
                setFormData(prev => ({ ...prev, [name]: value }));
            };

            return (
                <div className="animate-fade-in max-w-5xl mx-auto">
                    {/* Header */}
                    <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                        <div>
                            <h2 className="text-2xl font-bold text-gray-900 tracking-tight">Edit Customer</h2>
                            <p className="mt-1 text-sm text-gray-500">Update customer information</p>
                        </div>
                        <a href={`view.php?id=${customer.id}`} className="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                            <i className="fas fa-arrow-left mr-2"></i> Cancel
                        </a>
                    </div>

                    {error && (
                        <div className="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <i className="fas fa-exclamation-circle text-red-500"></i>
                                </div>
                                <div className="ml-3">
                                    <p className="text-sm text-red-700">{error}</p>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-100 bg-gray-50/50">
                            <h4 className="font-bold text-gray-900 text-sm">Customer Details</h4>
                        </div>
                        <div className="p-6">
                            <form action="" method="POST">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Customer Code</label>
                                        <input type="text" className="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-500 cursor-not-allowed sm:text-sm" value={customer.customer_code} disabled readOnly />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Company Name <span className="text-red-500">*</span></label>
                                        <input type="text" name="company_name" required value={formData.company_name} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>

                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Contact Person <span className="text-red-500">*</span></label>
                                        <input type="text" name="contact_person" required value={formData.contact_person} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Email <span className="text-red-500">*</span></label>
                                        <input type="email" name="email" required value={formData.email} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>

                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Phone <span className="text-red-500">*</span></label>
                                        <input type="text" name="phone" required value={formData.phone} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">TIN Number</label>
                                        <input type="text" name="tin" value={formData.tin} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>

                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">VRN Number</label>
                                        <input type="text" name="vrn" value={formData.vrn} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>
                                    
                                    <div className="md:col-span-2">
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Address</label>
                                        <textarea name="address" value={formData.address} onChange={handleChange} rows="2" className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors"></textarea>
                                    </div>

                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">City</label>
                                        <input type="text" name="city" value={formData.city} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Country</label>
                                        <input type="text" name="country" value={formData.country} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>

                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Customer Type <span className="text-red-500">*</span></label>
                                        <select name="customer_type" required value={formData.customer_type} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors capitalize">
                                            {['retail', 'wholesale', 'corporate', 'government'].map(type => (
                                                <option key={type} value={type}>{type.charAt(0).toUpperCase() + type.slice(1)}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Payment Terms <span className="text-red-500">*</span></label>
                                        <select name="payment_terms" required value={formData.payment_terms} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors">
                                            {['Immediate', 'Net 15', 'Net 30', 'Net 60', 'Net 90'].map(term => (
                                                <option key={term} value={term}>{term}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Currency <span className="text-red-500">*</span></label>
                                        <select name="currency" required value={formData.currency} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors">
                                            {['USD', 'TZS', 'KES', 'EUR', 'GBP'].map(curr => (
                                                <option key={curr} value={curr}>{curr}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Credit Limit ({formData.currency}) <span className="text-red-500">*</span></label>
                                        <input type="number" step="0.01" name="credit_limit" required value={formData.credit_limit} onChange={handleChange} className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors" />
                                    </div>

                                    <div className="md:col-span-2">
                                        <label className="block text-sm font-bold text-gray-700 mb-1">Notes</label>
                                        <textarea name="notes" value={formData.notes} onChange={handleChange} rows="3" className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm transition-colors"></textarea>
                                    </div>
                                </div>

                                <div className="mt-8 pt-6 border-t border-gray-100 flex gap-3">
                                    <button type="submit" className="inline-flex items-center px-5 py-2.5 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                        <i className="fas fa-save mr-2"></i> Update Customer
                                    </button>
                                    <a href="index.php" className="inline-flex items-center px-5 py-2.5 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                        Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('react-root'));
        root.render(<EditCustomerApp />);
    </script>
</body>
</html>

