<?php
require_once 'includes/functions.php';
requireLogin();

$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$prefix = ''; // Root page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Excel - Ultimate General Trading</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        .export-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 60vh;
            text-align: center;
            padding: 20px;
        }
        .export-card {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            max-width: 500px;
            width: 100%;
        }
        .export-icon-box {
            width: 80px;
            height: 80px;
            background: #ecfeff;
            color: #0891b2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
        }
        .btn-start-download {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            font-size: 18px;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .btn-start-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        .btn-start-download:active {
            transform: translateY(0);
        }

        /* Animation State */
        .download-status {
            display: none;
            margin-top: 30px;
        }
        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: #f1f5f9;
            border-radius: 4px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-bar-fill {
            width: 0%;
            height: 100%;
            background: #10b981;
            transition: width 4s linear;
        }
        .download-animation {
            font-size: 48px;
            color: #10b981;
            animation: bounce 1s infinite;
            margin-bottom: 10px;
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .status-text {
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }

        .fade-out {
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
    </style>
</head>
<body class="dashboard">
    <?php 
    if ($is_admin) {
        require_once 'includes/header_admin.php';
    } else {
        require_once 'includes/header_employee.php';
    }
    ?>

    <main class="main-content">
        <div class="export-container">
            <div class="export-card">
                <div id="initial-ui">
                    <div class="export-icon-box">
                        <i class="bi bi-file-earmark-spreadsheet"></i>
                    </div>
                    <h2>Ready to Export?</h2>
                    <p style="color: #64748b; margin-bottom: 30px;">
                        This will generate an Excel (CSV) file containing all your payment vouchers.
                    </p>
                    <button id="startBtn" class="btn-start-download">
                        <i class="bi bi-cloud-arrow-down-fill"></i>
                        Confirm & Download
                    </button>
                    <div style="margin-top: 20px;">
                        <a href="javascript:history.back()" style="color: #94a3b8; text-decoration: none; font-size: 14px;">Cancel</a>
                    </div>
                </div>

                <div id="status-ui" class="download-status">
                    <div class="download-animation">
                        <i class="bi bi-cloud-download"></i>
                    </div>
                    <h3 id="status-heading">Preparing your data...</h3>
                    <div class="progress-bar-container">
                        <div id="progressBar" class="progress-bar-fill"></div>
                    </div>
                    <p class="status-text">Ensuring accuracy and formatting. Please wait.</p>
                </div>
            </div>
        </div>
    </main>

    <script src="assets/js/voucher-v5.js"></script>
    <script>
        document.getElementById('startBtn').addEventListener('click', function() {
            // Hide initial UI
            document.getElementById('initial-ui').classList.add('fade-out');
            
            setTimeout(() => {
                document.getElementById('initial-ui').style.display = 'none';
                
                // Show status UI
                const statusUi = document.getElementById('status-ui');
                statusUi.style.display = 'block';
                
                // Start progress bar animation
                setTimeout(() => {
                    document.getElementById('progressBar').style.width = '100%';
                }, 50);

                // Wait 4 seconds then redirect
                setTimeout(() => {
                    document.getElementById('status-heading').innerText = 'Download Started...';
                    document.querySelector('.status-text').innerText = 'Your browser is now receiving the file.';
                    window.location.href = 'export_vouchers_list.php';
                    
                    // Show a "Go to Dashboard" button after a short delay
                    setTimeout(() => {
                        document.getElementById('status-heading').innerText = 'Download Completed!';
                        document.querySelector('.status-text').innerText = 'Your vouchers have been successfully exported.';
                        
                        if (typeof showToast === 'function') {
                            showToast('success', 'Excel Export Completed Successfully!');
                        }

                        const dashboardBtn = document.createElement('a');
                        dashboardBtn.href = is_admin ? 'admin/dashboard.php' : 'employee/dashboard.php';
                        dashboardBtn.className = 'btn-start-download';
                        dashboardBtn.style.marginTop = '20px';
                        dashboardBtn.style.background = 'linear-gradient(135deg, #6366f1 0%, #4f46e5 100%)';
                        dashboardBtn.innerHTML = '<i class="bi bi-house-door"></i> Return to Dashboard';
                        document.getElementById('status-ui').appendChild(dashboardBtn);
                        
                        document.querySelector('.download-animation').innerHTML = '<i class="bi bi-check-circle-fill"></i>';
                    }, 2500); 
                }, 4000);
            }, 300);
        });
    </script>
</body>
</html>
