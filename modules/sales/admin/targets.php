<?php
require_once '../../../includes/config.php';
require_once '../functions.php';

// Auth checks
if (session_status() == PHP_SESSION_NONE) session_start();
// (In valid environment: require_once '../../../includes/auth.php'; checkAuthentication('admin');)

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_target'])) {
    ensureSalesTargetsSchema();
    $user_id = $_POST['user_id'];
    $period = $_POST['period']; // 'YYYY-MM' or 'YYYY'
    $target_amount = $_POST['target_amount'];
    
    // Insert or Update
    $stmt = $pdo->prepare("INSERT INTO sales_targets (user_id, period, target_amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE target_amount = ?");
    if ($stmt->execute([$user_id, $period, $target_amount, $target_amount])) {
        $message = 'Target saved successfully!';
    } else {
        $error = 'Error saving target.';
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_company_target'])) {
    ensureSalesTargetsSchema();
    $year = $_POST['year'];
    $target_amount = $_POST['company_target_amount'];
    
    // Use user_id = 0 for company-wide yearly target
    $stmt = $pdo->prepare("INSERT INTO sales_targets (user_id, period, target_amount) VALUES (0, ?, ?) ON DUPLICATE KEY UPDATE target_amount = ?");
    if ($stmt->execute([$year, $target_amount, $target_amount])) {
        header("Location: targets.php?module=sales&success=company_target_saved");
        exit;
    } else {
        $error = 'Error saving company target.';
    }
}

// Fetch users
$users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all targets for overview
ensureSalesTargetsSchema();
$currentYear = date('Y');
$targets = $pdo->query("
    SELECT st.*, COALESCE(u.username, 'Company-Wide') as username 
    FROM sales_targets st 
    LEFT JOIN users u ON st.user_id = u.id 
    ORDER BY st.period DESC, username ASC
")->fetchAll(PDO::FETCH_ASSOC);

$companyTargetStmt = $pdo->prepare("SELECT target_amount FROM sales_targets WHERE user_id = 0 AND period = ?");
$companyTargetStmt->execute([$currentYear]);
$currentCompanyTarget = $companyTargetStmt->fetchColumn() ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Sales Targets | Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>

    <style>
        body { 
            background-color: #f8fafc; 
            font-family: 'Outfit', sans-serif;
            color: #1e293b;
        }
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
            users: <?= json_encode($users) ?>,
            targets: <?= json_encode($targets) ?>,
            currentYear: <?= json_encode($currentYear) ?>,
            currentCompanyTarget: <?= json_encode($currentCompanyTarget) ?>,
            success: <?= json_encode($message) ?>,
            error: <?= json_encode($error ?? '') ?>
        };
    </script>

    <script type="text/babel">
        const { useState, useMemo } = React;

        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-TZ', {
                style: 'currency',
                currency: 'TZS',
                minimumFractionDigits: 0
            }).format(amount);
        }

        function TargetsApp() {
            const [users] = useState(window.APP_DATA.users);
            const [targets] = useState(window.APP_DATA.targets);
            const [year, setYear] = useState(window.APP_DATA.currentYear);
            const [companyTarget, setCompanyTarget] = useState(window.APP_DATA.currentCompanyTarget);
            
            const [selectedUserId, setSelectedUserId] = useState('');
            const [targetPeriod, setTargetPeriod] = useState('');
            const [individualAmount, setIndividualAmount] = useState('');

            return (
                <div className="animate-fade-in space-y-8">
                    {/* Header */}
                    <div className="flex justify-between items-center">
                        <div>
                            <h1 className="text-2xl font-extrabold text-slate-900 tracking-tight flex items-center gap-3">
                                <span className="p-2 bg-indigo-600/10 text-indigo-600 rounded-xl">
                                    <i className="fas fa-bullseye text-xl"></i>
                                </span>
                                Sales Target Management
                            </h1>
                            <p className="mt-1 text-slate-500 text-sm font-medium ml-14">Set and monitor sales expectations for your team</p>
                        </div>
                        <a href="../dashboard/index.php" className="inline-flex items-center px-4 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-600 text-xs font-bold rounded-xl transition-all shadow-sm">
                            <i className="fas fa-arrow-left mr-2"></i> Dashboard
                        </a>
                    </div>

                    {window.APP_DATA.success && (
                        <div className="p-4 bg-emerald-50 border border-emerald-100 text-emerald-700 rounded-2xl flex items-center gap-3 shadow-sm">
                            <i className="fas fa-check-circle text-lg"></i>
                            <span className="font-bold text-sm tracking-tight">{window.APP_DATA.success}</span>
                        </div>
                    )}

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        {/* Forms Column */}
                        <div className="lg:col-span-1 space-y-6">
                            {/* Company Target Form */}
                            <div className="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
                                <h3 className="text-sm font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                                    <i className="fas fa-building text-slate-300"></i>
                                    Company Yearly Target
                                </h3>
                                <form method="POST" className="space-y-4">
                                    <div>
                                        <label className="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Reporting Year</label>
                                        <input 
                                            type="text" 
                                            name="year" 
                                            value={year}
                                            onChange={(e) => setYear(e.target.value)}
                                            className="block w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all text-center"
                                            required
                                            maxLength={4}
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Total Target (TZS)</label>
                                        <input 
                                            type="number" 
                                            name="company_target_amount" 
                                            value={companyTarget}
                                            onChange={(e) => setCompanyTarget(e.target.value)}
                                            className="block w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                            required
                                        />
                                    </div>
                                    <button 
                                        type="submit" 
                                        name="save_company_target"
                                        className="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-xl transition-all shadow-lg shadow-indigo-500/20 active:translate-y-px"
                                    >
                                        <i className="fas fa-save mr-2"></i> Update Global Target
                                    </button>
                                </form>
                            </div>

                            {/* Representative Target Form */}
                            <div className="bg-white rounded-3xl border border-slate-200 shadow-sm p-6">
                                <h3 className="text-sm font-bold text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                                    <i className="fas fa-user-tag text-slate-300"></i>
                                    Individual Rep Target
                                </h3>
                                <form method="POST" className="space-y-4">
                                    <div>
                                        <label className="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Select Sales Representative</label>
                                        <select 
                                            name="user_id" 
                                            value={selectedUserId}
                                            onChange={(e) => setSelectedUserId(e.target.value)}
                                            className="block w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                            required
                                        >
                                            <option value="">Choose User...</option>
                                            {users.map(u => (
                                                <option key={u.id} value={u.id}>{u.username}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Period (YYYY-MM or YYYY)</label>
                                        <input 
                                            type="text" 
                                            name="period" 
                                            placeholder="e.g. 2026-03"
                                            value={targetPeriod}
                                            onChange={(e) => setTargetPeriod(e.target.value)}
                                            className="block w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Goal Amount (TZS)</label>
                                        <input 
                                            type="number" 
                                            name="target_amount" 
                                            value={individualAmount}
                                            onChange={(e) => setIndividualAmount(e.target.value)}
                                            className="block w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 focus:outline-none focus:ring-4 focus:ring-indigo-500/10 focus:border-indigo-500 transition-all"
                                            required
                                        />
                                    </div>
                                    <button 
                                        type="submit" 
                                        name="save_target"
                                        className="w-full py-3 bg-white border border-indigo-600 text-indigo-600 hover:bg-indigo-50 text-xs font-bold rounded-xl transition-all active:translate-y-px"
                                    >
                                        <i className="fas fa-plus mr-2"></i> Save Set Target
                                    </button>
                                </form>
                            </div>
                        </div>

                        {/* List Column */}
                        <div className="lg:col-span-2">
                            <div className="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden h-full flex flex-col">
                                <div className="p-6 border-b border-slate-50 flex justify-between items-center">
                                    <h2 className="text-lg font-bold text-slate-800 tracking-tight">Existing Sales Targets</h2>
                                    <div className="text-[10px] font-bold text-slate-400 uppercase tracking-wider bg-slate-50 px-3 py-1 rounded-full border border-slate-100">
                                        Total Records: {targets.length}
                                    </div>
                                </div>
                                <div className="flex-1 overflow-auto">
                                    <table className="w-full text-left">
                                        <thead>
                                            <tr className="bg-slate-50/50 border-b border-slate-100">
                                                <th className="px-6 py-4 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Representative</th>
                                                <th className="px-6 py-4 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Period</th>
                                                <th className="px-6 py-4 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Goal Amount</th>
                                                <th className="px-6 py-4 text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Last Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-50">
                                            {targets.map(target => (
                                                <tr key={target.id} className="hover:bg-slate-50/50 transition-colors group">
                                                    <td className="px-6 py-4">
                                                        <div className="flex items-center gap-3">
                                                            <div className={`p-1.5 rounded-lg ${target.user_id == 0 ? 'bg-indigo-600/10 text-indigo-600' : 'bg-slate-100 text-slate-500'}`}>
                                                                <i className={`fas ${target.user_id == 0 ? 'fa-globe' : 'fa-user'} text-xs`}></i>
                                                            </div>
                                                            <span className={`text-sm font-medium tracking-tight ${target.user_id == 0 ? 'text-indigo-600 font-bold' : 'text-slate-700'}`}>
                                                                {target.username}
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span className="text-xs font-bold text-slate-600 bg-slate-100 px-2 py-1 rounded-md">
                                                            {target.period}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span className="text-sm font-medium text-slate-900 tracking-tight">
                                                            {formatCurrency(target.target_amount)}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span className="text-[10px] font-medium text-slate-400 uppercase tracking-tighter">
                                                            {new Date(target.updated_at).toLocaleDateString()}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                            {targets.length === 0 && (
                                                <tr>
                                                    <td colSpan="4" className="px-6 py-12 text-center">
                                                        <div className="text-slate-300 mb-2">
                                                            <i className="fas fa-inbox text-4xl"></i>
                                                        </div>
                                                        <p className="text-sm font-medium text-slate-400 tracking-tight">No targets defined yet</p>
                                                    </td>
                                                </tr>
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
        root.render(<TargetsApp />);
    </script>
    <script>
        // Check for success message in URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('success') === 'company_target_saved') {
            const moduleParam = urlParams.get('module') ? '?module=' + urlParams.get('module') : '';
            window.history.replaceState({}, '', window.location.pathname + moduleParam);
            
            // Show notification if needed (window.APP_DATA handles standard success messages)
        }
    </script>
</body>
</html>

