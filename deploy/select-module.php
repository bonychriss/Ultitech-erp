<?php
require_once 'includes/functions.php';
requireLogin();

$userName = $_SESSION['full_name'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'employee';
$isAdmin = ($userRole === 'admin');
$initial = strtoupper(substr($userName, 0, 1));

// Reset active module when visiting the selection page
if (isset($_SESSION['active_module'])) {
    unset($_SESSION['active_module']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Module - <?= COMPANY_NAME ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: #ffffff;
            /* Pure white background */
            min-height: 100vh;
            font-family: 'Poppins', sans-serif;
            font-weight: 300;
            display: flex;
            flex-direction: column;
        }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            color: #000;
            background: #fff;
            border-bottom: 1px solid #ddd;
        }

        .company-name {
            font-weight: 700;
            font-size: 18px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #000;
        }

        /* App Grid - Modern Clean Grid */
        .app-container {
            flex: 1;
            display: flex;
            align-items: flex-start;
            /* Changed from center to allow scrolling if needed */
            justify-content: center;
            padding: 40px 20px;
        }

        .app-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            /* slightly wider for modern look */
            gap: 24px;
            /* Comfortable gap */
            max-width: 1000px;
            width: 100%;
        }

        .app-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            background: #fff;
            padding: 30px 20px;
            border-radius: 0;
            /* Sharp corners */
            box-shadow: none;
            /* Removed subtle shadow for cleaner look */
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            color: #111;
            height: 120px;
            border: 1px solid #000;
            /* Black visible border */
        }

        /* Clean Hover Effect */
        .app-item:hover {
            border-color: #000;
            background: #000;
            /* Card turns black */
            color: #fff;
            /* Text turns white (inherited) */
        }

        .app-item:hover .app-label {
            color: #fff;
            /* Ensure label turns white */
        }

        /* Icon Box and Icon */
        .app-icon-box,
        .app-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }

        .app-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #f3f4f6;
        }

        .app-icon {
            width: 24px;
            height: 24px;
            color: #374151;
            margin-bottom: 0;
        }

        .app-label {
            color: #1f2937;
            /* Dark Gray 800 */
            font-size: 16px;
            font-weight: 600;
            letter-spacing: -0.01em;
            /* Modern tight spacing */
            text-align: center;
            margin: 0;
            line-height: 1.4;
        }

        .app-desc {
            font-size: 12px;
            color: #6b7280;
            font-weight: 400;
            margin-top: 4px;
            text-align: center;
            transition: color 0.2s;
        }

        .app-item:hover .app-desc {
            color: rgba(255, 255, 255, 0.7);
        }



        /* Responsive */
        @media (max-width: 768px) {
            .app-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .app-item {
                height: auto;
                min-height: 120px;
                padding: 20px;
                flex-direction: column;
                justify-content: center;
                gap: 4px;
                text-align: center;
            }

            .app-label {
                font-size: 18px;
                text-align: center;
            }

            .app-desc {
                text-align: center;
                margin-top: 4px;
            }
        }
    </style>
</head>

<body>

    <!-- Top Bar with System Gold Border -->
    <div class="top-bar"
        style="height: 70px; background: white; width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08);">
        <!-- Left: Logo + Hamburger -->
        <div style="display: flex; align-items: center; gap: 16px;">
            <a href="#" style="display: flex; align-items: center; text-decoration: none;">
                <img src="assets/images/Untitled.jpg" alt="Logo" style="height: 40px; width: auto;" />
            </a>

        </div>

        <div>
            <a href="logout.php" style="color: #ef4444; font-weight: 500; text-decoration: none; font-size: 14px;">Log
                Out</a>
        </div>
    </div>

    <!-- App Launcher Grid -->
    <div class="app-container">
        <div class="app-grid">



            <!-- Payment Voucher -->
            <a href="<?= $isAdmin ? 'admin/dashboard.php' : 'employee/dashboard.php' ?>?module=voucher"
                class="app-item">
                <div class="app-icon-box bg-teal">
                    <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6H4v18v2h2h12h2v-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="12" y1="18" x2="12" y2="12"></line>
                        <line x1="9" y1="15" x2="15" y2="15"></line>
                    </svg>
                </div>
                <span class="app-label">Payment Voucher</span>
                <span class="app-desc">Manage expense requests</span>
            </a>

            <!-- Attendance -->
            <a href="<?= $isAdmin ? 'admin/view-attendance.php' : 'employee/sign-attendance.php' ?>?module=attendance"
                class="app-item">
                <div class="app-icon-box bg-orange">
                    <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21v-2H5v2"></path>
                        <rect x="5" y="3" width="14" height="14"></rect>
                        <polyline points="17 11 19 13 23 9"></polyline>
                    </svg>
                </div>
                <span class="app-label">Attendance</span>
                <span class="app-desc">Track sign-ins & outs</span>
            </a>

            <!-- ERP -->
            <a href="erp/index.php?module=erp" class="app-item">
                <div class="app-icon-box bg-blue">
                    <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                        <polyline points="2 17 12 22 22 17"></polyline>
                        <polyline points="2 12 12 17 22 12"></polyline>
                    </svg>
                </div>
                <span class="app-label">ERP System</span>
                <span class="app-desc">Stock & sales overview</span>
            </a>

            <!-- Tasks -->
            <a href="<?= $isAdmin ? 'admin/manage_tasks.php' : 'employee/tasks.php' ?>?module=tasks" class="app-item">
                <div class="app-icon-box bg-pink">
                    <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <span class="app-label">Task Management</span>
                <span class="app-desc">Daily work logging</span>
            </a>

            <!-- Meeting Room -->
            <a href="<?= $isAdmin ? 'admin/meetings.php' : 'employee/meetings.php' ?>?module=meetings" class="app-item">
                <div class="app-icon-box bg-indigo">
                    <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                </div>
                <span class="app-label">Meetings</span>
                <span class="app-desc">Schedule & Join calls</span>
            </a>


            <!-- Order Tracking -->
            <a href="order-tracking/index.php?module=tracking" class="app-item">
                <div class="app-icon-box bg-green">
                    <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="3" width="15" height="13"></rect>
                        <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                        <rect x="3" y="16" width="5" height="5"></rect>
                        <rect x="16" y="16" width="5" height="5"></rect>
                    </svg>
                </div>
                <span class="app-label">Order Tracking</span>
                <span class="app-desc">Monitor order status</span>
            </a>

            <!-- Stocks / Inventory -->
            <a href="stocks/index.php?module=stocks" class="app-item">
                <div class="app-icon-box bg-indigo">
                    <svg class="app-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                        <line x1="12" y1="22.08" x2="12" y2="12"></line>
                    </svg>
                </div>
                <span class="app-label">Stock Management</span>
                <span class="app-desc">Items, Suppliers, POs</span>
            </a>

            <!-- Deliveries / Logistics -->
            <a href="deliveries/index.php?module=deliveries" class="app-item">
                <div class="app-icon-box bg-blue">
                    <svg class="app-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="3" width="15" height="13"></rect>
                        <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                        <circle cx="5.5" cy="18.5" r="2.5"></circle>
                        <circle cx="18.5" cy="18.5" r="2.5"></circle>
                    </svg>
                </div>
                <span class="app-label">Delivery Logistics</span>
                <span class="app-desc">Trips, Manifests, POD</span>
            </a>

            <!-- Outstanding (Invoices) -->
            <a href="erp/outstanding-invoices/index.php?module=outstanding" class="app-item">
                <div class="app-icon-box bg-orange">
                    <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6H4v18v2h2h12h2v-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="12" y1="18" x2="12" y2="12"></line>
                        <line x1="9" y1="15" x2="15" y2="15"></line>
                    </svg>
                </div>
                <span class="app-label">Outstanding Invoices</span>
                <span class="app-desc">View income & expenses</span>
            </a>

            <!-- Petty Cash -->
            <a href="erp/petty-cash/index.php?module=petty_cash" class="app-item">
                <div class="app-icon-box bg-teal">
                    <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="5" width="20" height="14"></rect>
                        <line x1="12" y1="2" x2="12" y2="5"></line>
                        <line x1="12" y1="19" x2="12" y2="22"></line>
                    </svg>
                </div>
                <span class="app-label">Petty Cash</span>
                <span class="app-desc">Small expense tracking</span>
            </a>

            <!-- Settings (Admin Only or Profile for Employee) -->
            <?php if ($isAdmin): ?>
                <a href="admin/settings.php?module=settings" class="app-item">
                    <div class="app-icon-box bg-gray">
                        <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"></circle>
                            <path
                                d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1.29 1.52 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z">
                            </path>
                        </svg>
                    </div>
                    <span class="app-label">Settings</span>
                    <span class="app-desc">System configuration</span>
                </a>
            <?php else: ?>
                <a href="employee/account.php?module=account" class="app-item">
                    <div class="app-icon-box bg-gray">
                        <svg class="app-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <rect x="8" y="3" width="8" height="8"></rect>
                        </svg>
                    </div>
                    <span class="app-label">My Profile</span>
                    <span class="app-desc">Account Details</span>
                </a>
            <?php endif; ?>

        </div>
    </div>



    <!-- Toast Notification for Login -->
    <?php if (isset($_GET['login_success'])): ?>
        <div id="welcomeToast" style="
        position: fixed; 
        top: 20px; 
        right: 20px; 
        background-color: #2b2f42; 
        color: white; 
        padding: 16px 24px; 
        border-radius: 8px; 
        box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
        z-index: 9999; 
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 12px;
        transform: translateY(-100px);
        opacity: 0;
        transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    ">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#5cffa1"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span>Welcome back! <strong><?= htmlspecialchars($userName) ?></strong></span>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const toast = document.getElementById('welcomeToast');
                // Show
                setTimeout(() => {
                    toast.style.transform = 'translateY(0)';
                    toast.style.opacity = '1';
                }, 100);

                // Hide after 5 seconds
                setTimeout(() => {
                    toast.style.transform = 'translateY(-100px)';
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 500);
                }, 5000);

                // Clean URL (remove query param)
                const url = new URL(window.location);
                url.searchParams.delete('login_success');
                window.history.replaceState({}, document.title, url);
            });
        </script>
    <?php endif; ?>

</body>

</html>