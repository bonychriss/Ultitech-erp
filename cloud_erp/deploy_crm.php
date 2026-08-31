<?php
// deploy_crm.php
// Run this file to create the CRM module files and directories on the server
// Bypasses zip extraction permission/backslash issues.

$dirsToCheck = [
    __DIR__, 
    __DIR__ . '/modules'
];

$hasError = false;
foreach ($dirsToCheck as $dir) {
    if (file_exists($dir) && !is_writable($dir)) {
        echo "<div style='color:red; border:1px solid red; padding:10px; margin:10px;'>";
        echo "<strong>PERMISSION ERROR:</strong> The server cannot write to: <code>$dir</code><br>";
        echo "Please use your <strong>File Manager</strong> or <strong>FTP</strong> to set permissions of this folder to <strong>777</strong> (Read/Write/Execute for Everyone).";
        echo "</div>";
        $hasError = true;
    }
}

if ($hasError) {
    die("<h3>Script Halted due to permissions. Fix above errors and refresh.</h3>");
}

// Ensure modules dir exists
if (!file_exists(__DIR__ . '/modules')) {
    if (!mkdir(__DIR__ . '/modules', 0777, true)) {
        die("Failed to create 'modules' directory. Parent folder is not writable. Set 'cloud_erp' to 777.");
    }
}

$baseDir = __DIR__ . '/modules/CRM';

if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0777, true)) {
        die("Failed to create directory: $baseDir. Check permissions.");
    }
    echo "Created directory: $baseDir<br>";
}

$files = [
    'manifest.json' => '{
    "name": "CRM",
    "version": "1.0",
    "description": "Customer Relationship Management & Leads",
    "enabled": true
}',

    'install.php' => '<?php
require_once __DIR__ . \'/../../core/Database.php\';

use Core\Database;

echo "<h1>CRM Module Installation</h1>";

try {
    $pdo = Database::getInstance();
    
    // 1. Customers Table
    $sql = "CREATE TABLE IF NOT EXISTS crm_customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        phone VARCHAR(50),
        address TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;";
    $pdo->exec($sql);
    echo "<li>Created \'crm_customers\' table.</li>";

    // 2. Leads Table
    $sql = "CREATE TABLE IF NOT EXISTS crm_leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        customer_id INT NULL, -- If converted from customer or linked
        title VARCHAR(255) NOT NULL,
        contact_name VARCHAR(255),
        email VARCHAR(100),
        phone VARCHAR(50),
        status ENUM(\'new\', \'contacted\', \'qualified\', \'proposal\', \'won\', \'lost\') DEFAULT \'new\',
        source VARCHAR(100),
        assigned_to INT,
        estimated_value DECIMAL(15,2) DEFAULT 0,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB;";
    $pdo->exec($sql);
    echo "<li>Created \'crm_leads\' table.</li>";

    echo "<h3>CRM Installation Complete!</h3>";
    echo "<p><a href=\'../../index.php\'>Go to Dashboard</a></p>";

} catch (Exception $e) {
    echo "<h3 style=\'color:red\'>Error: " . $e->getMessage() . "</h3>";
}
',

    'index.php' => '<?php
// modules/CRM/index.php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';

use Core\Auth;
use Core\Database;

Auth::check();
$user = Auth::user();

// Fetch Leads
$pdo = Database::getInstance();
$stmt = $pdo->prepare("SELECT * FROM crm_leads WHERE company_id = ? ORDER BY created_at DESC");
$stmt->execute([$user[\'erp_company_id\']]);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CRM - Leads</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>body { font-family: \'Inter\', sans-serif; background: #f8f9fa; }</style>
</head>
<body>
    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Leads</h4>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb small text-muted">
                        <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">CRM</li>
                    </ol>
                </nav>
            </div>
            <a href="create.php" class="btn btn-primary">+ New Lead</a>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Title</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Value</th>
                                <th>Assignments</th>
                                <th>Date</th>
                                <th class="text-end pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($leads)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No leads found.</td></tr>
                            <?php else: ?>
                            <?php foreach($leads as $lead): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($lead[\'title\']) ?></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span><?= htmlspecialchars($lead[\'contact_name\']) ?></span>
                                        <small class="text-muted"><?= htmlspecialchars($lead[\'email\'] ?? $lead[\'phone\']) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $badge = match($lead[\'status\']) {
                                        \'new\' => \'bg-secondary\',
                                        \'qualified\' => \'bg-info\',
                                        \'won\' => \'bg-success\',
                                        \'lost\' => \'bg-danger\',
                                        default => \'bg-primary\'
                                    };
                                    ?>
                                    <span class="badge rounded-pill <?= $badge ?>"><?= ucfirst($lead[\'status\']) ?></span>
                                </td>
                                <td><?= number_format($lead[\'estimated_value\'], 2) ?></td>
                                <td><?= \'User #\' . $lead[\'assigned_to\'] ?></td>
                                <td class="text-muted small"><?= date(\'M d\', strtotime($lead[\'created_at\'])) ?></td>
                                <td class="text-end pe-4">
                                    <a href="#" class="btn btn-sm btn-light border">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>',

    'create.php' => '<?php
// modules/CRM/create.php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';

use Core\Auth;
use Core\Database;

Auth::check();
$user = Auth::user();

if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\') {
    $title = $_POST[\'title\'];
    $contact = $_POST[\'contact_name\'];
    $email = $_POST[\'email\'];
    $value = $_POST[\'estimated_value\'] ?? 0;
    
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("INSERT INTO crm_leads (company_id, title, contact_name, email, estimated_value, assigned_to) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user[\'erp_company_id\'], $title, $contact, $email, $value, $user[\'erp_user_id\']]);
    
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Lead - CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5" style="max-width: 600px;">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0 fw-bold">Create New Lead</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Lead Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Website Inquiry" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Name</label>
                            <input type="text" name="contact_name" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Estimated Value</label>
                        <input type="number" name="estimated_value" class="form-control" value="0.00" step="0.01">
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="index.php" class="btn btn-light">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Lead</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>'
];

foreach ($files as $name => $content) {
    if (file_put_contents("$baseDir/$name", $content) !== false) {
        echo "Created file: $name<br>";
    } else {
        echo "<strong style='color:red'>Failed to create file: $name</strong><br>";
    }
}
echo "<h3>CRM Deployment Complete.</h3>";
echo "<a href='modules/CRM/install.php'>Check Installation</a>";
?>
