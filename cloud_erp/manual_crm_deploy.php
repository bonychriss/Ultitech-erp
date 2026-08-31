<?php
// manual_crm_deploy.php - Aggressive Fix
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>CRM Manual Deployment</h1>";

// 1. Try to Fix Permissions of current folder
echo "Attempting to chmod current directory to 777... ";
if (@chmod(__DIR__, 0777)) {
    echo "<span style='color:green'>Success</span><br>";
} else {
    echo "<span style='color:orange'>Failed (Server might restrict this)</span><br>";
}

// 2. Create 'modules' folder
$modulesDir = __DIR__ . '/modules';
if (!file_exists($modulesDir)) {
    echo "Creating 'modules' folder... ";
    if (mkdir($modulesDir, 0777, true)) {
        echo "<span style='color:green'>Success</span><br>";
        @chmod($modulesDir, 0777);
    } else {
        echo "<span style='color:red'>FAILED!</span><br>";
        // ... (Error message remains)
        die("Stopping execution.");
    }
} else {
    echo "Folder 'modules' exists. Attempting to chmod to 777... ";
    if (@chmod($modulesDir, 0777)) {
        echo "<span style='color:green'>Success</span><br>";
    } else {
        echo "<span style='color:orange'>Failed (Server might restrict this). If next step fails, you MUST manually set 'modules' to 777.</span><br>";
    }
}

// 3. Create 'modules/CRM' folder
$crmDir = __DIR__ . '/modules/CRM';
if (!file_exists($crmDir)) {
    echo "Creating 'modules/CRM' folder... ";
    if (mkdir($crmDir, 0777, true)) {
        echo "<span style='color:green'>Success</span><br>";
        @chmod($crmDir, 0777);
    } else {
         echo "<span style='color:red'>FAILED!</span><br>";
         echo "Please manually create folder: <code>cloud_erp/modules/CRM</code>";
         die();
    }
}

// 4. Write Files
$files = [
    'manifest.json' => '{ "name": "CRM", "version": "1.0", "description": "Customer Relationship Management", "enabled": true }',
    
    'install.php' => '<?php
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Database;
echo "<h1>CRM Installer</h1>";
try {
    $pdo = Database::getInstance();
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_customers (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(100), phone VARCHAR(50), address TEXT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE) ENGINE=InnoDB;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_leads (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, customer_id INT NULL, title VARCHAR(255) NOT NULL, contact_name VARCHAR(255), email VARCHAR(100), phone VARCHAR(50), status ENUM(\'new\', \'contacted\', \'qualified\', \'proposal\', \'won\', \'lost\') DEFAULT \'new\', source VARCHAR(100), assigned_to INT, estimated_value DECIMAL(15,2) DEFAULT 0, notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE, FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL) ENGINE=InnoDB;");
    echo "<li>Tables Created.</li>";
    echo "<h3><a href=\'../../index.php\'>Success! Go to Dashboard</a></h3>";
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }',

'index.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
$pdo = Database::getInstance();
$stmt = $pdo->prepare("SELECT * FROM crm_leads WHERE company_id = ? ORDER BY created_at DESC");
$stmt->execute([$user[\'erp_company_id\']]);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>CRM</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container p-4"><h4>Leads <a href="create.php" class="btn btn-primary btn-sm float-end">+ New</a></h4><div class="card"><table class="table mb-0"><thead><tr><th>Title</th><th>Contact</th><th>Status</th><th>Value</th></tr></thead><tbody><?php foreach($leads as $l): ?><tr><td><?= htmlspecialchars($l[\'title\']) ?></td><td><?= htmlspecialchars($l[\'contact_name\']) ?></td><td><?= $l[\'status\'] ?></td><td><?= $l[\'estimated_value\'] ?></td></tr><?php endforeach; ?></tbody></table></div></div></body></html>',

'create.php' => '<?php
require_once __DIR__ . \'/../../core/Auth.php\';
require_once __DIR__ . \'/../../core/Database.php\';
use Core\Auth; use Core\Database;
Auth::check(); $user = Auth::user();
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\') {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("INSERT INTO crm_leads (company_id, title, contact_name, email, estimated_value, assigned_to) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user[\'erp_company_id\'], $_POST[\'title\'], $_POST[\'contact_name\'], $_POST[\'email\'], $_POST[\'estimated_value\'], $user[\'erp_user_id\']]);
    header("Location: index.php"); exit;
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>New Lead</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><div class="container mt-5" style="max-width:600px"><div class="card p-4"><h5>New Lead</h5><form method="POST"><input class="form-control mb-2" name="title" placeholder="Title" required><input class="form-control mb-2" name="contact_name" placeholder="Contact Name"><input class="form-control mb-2" name="email" placeholder="Email"><input class="form-control mb-2" name="estimated_value" placeholder="Value"><button class="btn btn-primary">Save</button></form></div></div></body></html>'
];

foreach ($files as $name => $content) {
    if (file_put_contents("$crmDir/$name", $content)) {
        echo "Created file: modules/CRM/$name<br>";
    } else {
        echo "<span style='color:red'>Failed to create modules/CRM/$name</span><br>";
    }
}

echo "<h3>Setup Complete! <a href='modules/CRM/install.php'>Click to Install Database</a></h3>";
?>
