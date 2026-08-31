<?php
require_once '../includes/functions.php';
require_once '../includes/mailer.php';
requireAdmin();

$error = '';
$success = '';
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

$allowedInviteDepartments = ['Procurement', 'IT', 'Finance', 'Sales', 'Driver', 'Management', 'General'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    if ($full_name === '' || $email === '' || $department === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!in_array($department, $allowedInviteDepartments, true)) {
        $error = 'Please select a valid department.';
    } elseif (($emailErr = validateNewUserEmailForIndex($email)) !== null) {
        $error = $emailErr;
    } else {
        try {
            // Case-insensitive email check in active DB
            $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = 'This email is already registered. Please use another email.';
            } else {
                // Generate username from full name (up to 50 chars as per schema)
                $username = mb_substr($full_name, 0, 50);
                
                // Case-insensitive username check
                $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = ?');
                $stmt->execute([strtolower($username)]);
                if ($stmt->fetch()) {
                    $error = 'A user with this name (username) already exists.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    
                    // Note: password is set to a random placeholder because it is NOT NULL in schema
                    $placeholder_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    
                    $companyId = (int)($_SESSION['company_id'] ?? 0);
                    $createdBy = (int)($_SESSION['user_id'] ?? 0);
                    
                    $stmt = $pdo->prepare('INSERT INTO users (username, password, full_name, email, role, department, company_id, created_by, created_at, is_active, registration_token, token_expiry) VALUES (?, ?, ?, ?, "employee", ?, ?, ?, NOW(), 0, ?, ?)');
                    
                    if ($stmt->execute([$username, $placeholder_pass, $full_name, $email, $department, $companyId, $createdBy, $token, $expiry])) {
                        $newUserId = (int) $pdo->lastInsertId();
                        if ($newUserId > 0 && $companyId > 0) {
                            syncUserCompanyIndex($companyId, $newUserId);
                        }
                        // Improved environment detection for secure links
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? "https" : "http";
                        $baseUrl = $protocol . "://$_SERVER[HTTP_HOST]" . APP_BASE_PATH;
                        $finishUrl = rtrim($baseUrl, '/') . "/finish-registration.php?token=$token";
                        
                        $subject = "Complete Your Registration - " . COMPANY_NAME;
                        $body = "
                            <h2>Welcome to " . COMPANY_NAME . "</h2>
                            <p>Hello $full_name,</p>
                            <p>An account has been created for you in the Payment Voucher System. To complete your registration and set your password, please click the link below:</p>
                            <p><a href='$finishUrl' style='display:inline-block; padding:10px 20px; background:#2b2f42; color:white; text-decoration:none; border-radius:5px;'>Complete Registration</a></p>
                            <p>Or copy and paste this link into your browser:</p>
                            <p>$finishUrl</p>
                            <p>This link will expire in 24 hours.</p>
                            <p>Best regards,<br>System Administrator</p>
                        ";
                        
                        if (sendEmail($email, $subject, $body)) {
                            $success = "Employee invited successfully! An email has been sent to $email.";
                        } else {
                            $success = "Employee created, but invitation email failed to send. Please check mail settings. Link: $finishUrl";
                        }
                    } else {
                        $error = 'Registration failed. Please try again.';
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Registration Error: " . $e->getMessage());
            $error = 'Database error: ' . $e->getMessage();
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => empty($error),
            'message' => $error ?: $success
        ]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New Employee - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .register-container {
            max-width: 500px;
            margin: 40px auto;
            padding: 20px;
        }
        .register-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .register-header h2 {
            margin: 0;
            color: #1a202c;
            font-size: 24px;
        }
        .register-header p {
            color: #718096;
            margin-top: 8px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
            font-size: 14px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            border-color: #4299e1;
            outline: none;
        }
        .btn-register {
            width: 100%;
            padding: 14px;
            background: #2b2f42;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-register:hover {
            background: #1a1e2e;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #718096;
            text-decoration: none;
            font-size: 14px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body class="dashboard">
    <?php require_once __DIR__ . '/../includes/header_admin.php'; ?>

    <main class="main-content">
        <div class="register-container">
            <div class="register-card">
                <div class="register-header">
                    <h2>Invite New Employee</h2>
                    <p>Send an invitation to a new team member</p>
                </div>

                <form method="POST" id="registerFormStandalone">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="e.g. John Doe" value="<?= htmlspecialchars($full_name ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required placeholder="john@example.com" value="<?= htmlspecialchars($email ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="department">Department</label>
                        <select id="department" name="department" required>
                            <option value="" disabled <?= empty($department) ? 'selected' : '' ?>>Select department</option>
                            <option value="Procurement" <?= ($department ?? '') === 'Procurement' ? 'selected' : '' ?>>Procurement</option>
                            <option value="IT" <?= ($department ?? '') === 'IT' ? 'selected' : '' ?>>IT</option>
                            <option value="Finance" <?= ($department ?? '') === 'Finance' ? 'selected' : '' ?>>Finance</option>
                            <option value="Sales" <?= ($department ?? '') === 'Sales' ? 'selected' : '' ?>>Sales</option>
                            <option value="Driver" <?= ($department ?? '') === 'Driver' ? 'selected' : '' ?>>Driver</option>
                            <option value="Management" <?= ($department ?? '') === 'Management' ? 'selected' : '' ?>>Management</option>
                            <option value="General" <?= ($department ?? '') === 'General' ? 'selected' : '' ?>>General</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-register">Send Invitation</button>
                </form>

                <a href="manage-users.php" class="back-link">&larr; Back to User Management</a>
            </div>
        </div>
    </main>

    <script>
        console.log('[register_employee.php] Script initializing');

        // Handle AJAX submission
        const regForm = document.getElementById('registerFormStandalone');
        if (regForm) {
            regForm.addEventListener('submit', function(e) {
                e.preventDefault();
                console.log('[register_employee.php] Form submission started');
                
                const form = this;
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalBtnText = submitBtn.innerText;
                
                submitBtn.disabled = true;
                submitBtn.innerText = 'Sending Invitation...';

                const formData = new FormData(form);
                
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                .then(response => {
                    console.log('[register_employee.php] Response received', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('[register_employee.php] JSON data', data);
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Invitation Sent',
                            text: data.message,
                            confirmButtonColor: '#2b2f42'
                        }).then(() => {
                            window.location.href = 'manage-users.php';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Failed to Send',
                            text: data.message,
                            confirmButtonColor: '#2b2f42'
                        });
                    }
                })
                .catch(error => {
                    console.error('[register_employee.php] Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: 'An unexpected error occurred: ' + error.message,
                        confirmButtonColor: '#2b2f42'
                    });
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerText = originalBtnText;
                });
            });
        }

        // Fallback for non-AJAX submissions (just in case)
        <?php if ($success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Invitation Sent',
                text: '<?= addslashes($success) ?>',
                confirmButtonColor: '#2b2f42'
            }).then(() => {
                window.location.href = 'manage-users.php';
            });
        <?php endif; ?>

        <?php if ($error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Registration Error',
                text: '<?= addslashes($error) ?>',
                confirmButtonColor: '#2b2f42'
            });
        <?php endif; ?>
    </script>
</body>
</html>
