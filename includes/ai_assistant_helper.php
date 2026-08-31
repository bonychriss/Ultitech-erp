<?php
/**
 * AI Assistant Helper - Core Intelligence Hub for Ultimate ERP.
 * Combines role-based privacy filters, error/anomaly scanners, and growth forecasting.
 */

require_once __DIR__ . '/ai_helpers.php';
require_once __DIR__ . '/ai_assistant_reports.php';

class AIAssistantContextBuilder
{
    private $pdo;
    private $userId;
    private $companyId;
    private $role;

    public function __construct(PDO $pdo, int $userId, int $companyId, string $role)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->companyId = $companyId;
        $this->role = strtolower(trim($role));
    }

    /**
     * Compile role-based summaries across all active modules.
     */
    public function buildAllModulesContext(?string $activeModule = null): string
    {
        $ctx = [];
        $ctx[] = "=== User Identity & Scope ===";
        $ctx[] = "Role: " . strtoupper($this->role);
        $ctx[] = "Company ID: " . $this->companyId;
        $ctx[] = "User ID: " . $this->userId;
        if ($activeModule) {
            $ctx[] = "Active Focus Module: " . strtoupper($activeModule);
        }
        $ctx[] = "";

        $ctx[] = "=== Voucher Module Summary ===";
        $ctx[] = $this->buildVouchersContext();
        $ctx[] = "";

        $ctx[] = "=== Attendance Module Summary ===";
        $ctx[] = $this->buildAttendanceContext();
        $ctx[] = "";

        $ctx[] = "=== Performance Module Summary ===";
        $ctx[] = $this->buildPerformanceContext();
        $ctx[] = "";

        if ($this->isAdminOrManager()) {
            $ctx[] = "=== Sales & Revenue Summary ===";
            $ctx[] = $this->buildSalesAndRevenueContext();
            $ctx[] = "";
        }

        // Module-specific detailed context
        if ($activeModule) {
            $moduleLower = strtolower($activeModule);
            if ($moduleLower === 'sales') {
                $ctx[] = "=== Detailed Sales & Customer Context ===";
                $ctx[] = $this->buildDetailedSalesContext();
                $ctx[] = "";
            } elseif (in_array($moduleLower, ['stocks', 'stock', 'procurement', 'purchases'], true)) {
                $ctx[] = "=== Detailed Inventory & Stock Context ===";
                $ctx[] = $this->buildStocksContext();
                $ctx[] = "";
            } elseif (in_array($moduleLower, ['finance', 'balances', 'expenses', 'revenue'], true)) {
                $ctx[] = "=== Detailed Financial & Expenses Context ===";
                $ctx[] = $this->buildDetailedFinanceContext();
                $ctx[] = "";
            } elseif ($moduleLower === 'payroll') {
                $ctx[] = "=== Detailed Payroll Context ===";
                $ctx[] = $this->buildDetailedPayrollContext();
                $ctx[] = "";
            } elseif (in_array($moduleLower, ['deliveries', 'dispatch'], true)) {
                $ctx[] = "=== Detailed Deliveries & Dispatch Context ===";
                $ctx[] = $this->buildDetailedDeliveriesContext();
                $ctx[] = "";
            } elseif (in_array($moduleLower, ['todo', 'tasks'], true)) {
                $ctx[] = "=== Detailed Tasks & Todo Context ===";
                $ctx[] = $this->buildDetailedTodoContext();
                $ctx[] = "";
            } elseif (in_array($moduleLower, ['full_report', 'full_system_report'], true)) {
                $ctx[] = "=== Detailed Full System Audit Context ===";
                $ctx[] = $this->buildFullSystemReportContextText();
                $ctx[] = "";
            }
        }

        return implode("\n", $ctx);
    }

    private function isAdminOrManager(): bool
    {
        return in_array($this->role, ['admin', 'administrator', 'superadmin', 'manager', 'department_manager', 'company_admin', 'owner'], true);
    }

    /**
     * Vouchers context: compiled aggregates.
     */
    public function buildVouchersContext(): string
    {
        try {
            if (!tableExists('payment_vouchers', $this->pdo)) {
                return "Voucher table not available.";
            }

            $sql = "SELECT 
                        status, 
                        IFNULL(is_paid, 0) as is_paid, 
                        IFNULL(is_posted, 0) as is_posted,
                        COUNT(*) as count, 
                        SUM(total_amount) as total_amt, 
                        currency
                    FROM payment_vouchers ";

            $params = [];
            if (!$this->isAdminOrManager()) {
                // Strictly limit employees to their own created vouchers to preserve privacy
                $sql .= "WHERE created_by = ? ";
                $params[] = $this->userId;
            }

            $sql .= "GROUP BY status, is_paid, is_posted, currency";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return "No payment vouchers found in the system.";
            }

            $summary = [];
            foreach ($rows as $r) {
                $statusStr = $r['status'];
                if ($r['is_posted']) {
                    $statusStr = 'Posted';
                } elseif ($r['is_paid']) {
                    $statusStr = 'Paid';
                }
                $summary[] = sprintf("- %s (%s): %d vouchers, Total: %s %s", 
                    ucfirst($statusStr), 
                    $r['currency'], 
                    $r['count'], 
                    $r['currency'], 
                    number_format($r['total_amt'], 2)
                );
            }

            return implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling vouchers context: " . $e->getMessage();
        }
    }

    /**
     * Attendance context: Lateness, hours worked.
     */
    public function buildAttendanceContext(): string
    {
        try {
            if (!tableExists('attendance', $this->pdo)) {
                return "Attendance table not available.";
            }

            $params = [];
            $sql = "SELECT 
                        COUNT(*) as total_shifts,
                        SUM(CASE WHEN LOWER(status) = 'late' THEN 1 ELSE 0 END) as late_shifts,
                        AVG(NULLIF(total_hours, 0)) as avg_hours,
                        SUM(overtime_hours) as total_overtime
                    FROM attendance ";

            if (!$this->isAdminOrManager()) {
                // Strictly personal
                $sql .= "WHERE user_id = ? ";
                $params[] = $this->userId;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || $row['total_shifts'] == 0) {
                return "No attendance records found for this period.";
            }

            $latePct = $row['total_shifts'] > 0 ? round(($row['late_shifts'] / $row['total_shifts']) * 100) : 0;
            return sprintf(
                "- Total shifts: %d\n- Late shifts: %d (%d%%)\n- Average shift duration: %s hours\n- Total Overtime: %s hours",
                $row['total_shifts'],
                $row['late_shifts'],
                $latePct,
                number_format((float)$row['avg_hours'], 1),
                number_format((float)$row['total_overtime'], 1)
            );
        } catch (Throwable $e) {
            return "Error compiling attendance context: " . $e->getMessage();
        }
    }

    /**
     * Performance context: missions / plans stats.
     */
    public function buildPerformanceContext(): string
    {
        try {
            $summary = [];
            
            // Check for weekly missions
            if (tableExists('weekly_missions', $this->pdo)) {
                $params = [];
                $sql = "SELECT 
                            status,
                            COUNT(*) as cnt,
                            SUM(points) as total_pts
                        FROM weekly_missions ";
                
                if (!$this->isAdminOrManager()) {
                    $sql .= "WHERE user_id = ? ";
                    $params[] = $this->userId;
                }
                
                $sql .= "GROUP BY status";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $summary[] = "Weekly Missions State:";
                    foreach ($rows as $r) {
                        $summary[] = sprintf("  - %s: %d missions (Total Points: %d)", $r['status'], $r['cnt'], $r['total_pts']);
                    }
                }
            }

            // Check for performance streaks / leaderboard
            if (tableExists('performance_points', $this->pdo)) {
                $params = [];
                $sql = "SELECT 
                            AVG(completion_rate) as avg_rate,
                            MAX(streak_count) as max_streak
                        FROM performance_points ";
                
                if (!$this->isAdminOrManager()) {
                    $sql .= "WHERE user_id = ? ";
                    $params[] = $this->userId;
                }
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($row && $row['avg_rate'] !== null) {
                    $summary[] = sprintf("Average Completion Rate: %d%%", round($row['avg_rate']));
                    $summary[] = sprintf("Longest Completion Streak: %d weeks", $row['max_streak']);
                }
            }

            return empty($summary) ? "No active performance metrics recorded yet." : implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling performance context: " . $e->getMessage();
        }
    }

    /**
     * Sales and Revenue context: Monthly targets vs collections.
     */
    public function buildSalesAndRevenueContext(): string
    {
        try {
            $summary = [];
            
            // 1. Sales Targets
            if (tableExists('sales_targets', $this->pdo)) {
                $stmt = $this->pdo->query("SELECT SUM(target_amount) as total_target FROM sales_targets");
                $target = $stmt->fetchColumn();
                if ($target) {
                    $summary[] = sprintf("- Total Company Sales Target: TZS %s", number_format($target, 2));
                }
            }

            // 2. Revenue Entries (If table exists)
            if (tableExists('revenue_entries', $this->pdo)) {
                $stmt = $this->pdo->query("SELECT SUM(amount_total) as total_rev FROM revenue_entries WHERE approval_status = 'Approved'");
                $rev = $stmt->fetchColumn();
                if ($rev) {
                    $summary[] = sprintf("- Total Approved Revenue collected: TZS %s", number_format($rev, 2));
                }
            }

            return empty($summary) ? "No company financial information available." : implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling sales context: " . $e->getMessage();
        }
    }

    private function companySql(string $alias = ''): string
    {
        if (defined('IS_TENANT_DB') && IS_TENANT_DB) {
            return "";
        }
        return " AND " . ($alias ? "$alias." : "") . "company_id = " . (int)$this->companyId;
    }

    public function buildDetailedSalesContext(): string
    {
        try {
            $summary = [];
            if (tableExists('sales_orders', $this->pdo)) {
                $sql = "SELECT COUNT(*) as count, SUM(total_amount) as total FROM sales_orders WHERE 1=1" . $this->companySql();
                $params = [];
                if (!$this->isAdminOrManager()) {
                    $sql .= " AND created_by = ?";
                    $params[] = $this->userId;
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($row && $row['count'] > 0) {
                    $summary[] = sprintf("- Total Sales Orders: %d (Value: TZS %s)", $row['count'], number_format($row['total'], 2));
                }
            }
            
            if (tableExists('customers', $this->pdo)) {
                $st = $this->pdo->query("SELECT COUNT(*) FROM customers WHERE 1=1" . $this->companySql());
                $custCount = $st ? $st->fetchColumn() : 0;
                $summary[] = "- Total Customers: " . $custCount;
            }

            if ($this->isAdminOrManager() && tableExists('sales_targets', $this->pdo)) {
                $st = $this->pdo->query("SELECT SUM(target_amount) FROM sales_targets WHERE 1=1" . $this->companySql());
                $target = $st ? $st->fetchColumn() : 0;
                if ($target) {
                    $summary[] = sprintf("- Company Sales Target: TZS %s", number_format($target, 2));
                }
            }
            
            return empty($summary) ? "No sales orders or customers found." : implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling detailed sales context: " . $e->getMessage();
        }
    }

    public function buildStocksContext(): string
    {
        try {
            $summary = [];
            if (tableExists('products', $this->pdo)) {
                $st = $this->pdo->query("SELECT COUNT(*) FROM products WHERE 1=1" . $this->companySql());
                $count = $st ? $st->fetchColumn() : 0;
                $summary[] = "- Total catalog products: " . $count;
            }
            if (tableExists('suppliers', $this->pdo)) {
                $st = $this->pdo->query("SELECT COUNT(*) FROM suppliers WHERE 1=1" . $this->companySql());
                $count = $st ? $st->fetchColumn() : 0;
                $summary[] = "- Total suppliers: " . $count;
            }
            if (tableExists('stock', $this->pdo) && tableExists('products', $this->pdo)) {
                $st = $this->pdo->query("SELECT p.name, s.quantity, s.minimum_level 
                                         FROM products p 
                                         JOIN stock s ON p.id = s.product_id 
                                         WHERE s.quantity <= s.minimum_level" . $this->companySql('p') . " LIMIT 5");
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    $summary[] = "- Low Stock Alerts:";
                    foreach ($rows as $r) {
                        $summary[] = sprintf("  * %s (Qty: %d, Min: %d)", $r['name'], $r['quantity'], $r['minimum_level']);
                    }
                }
            }
            return empty($summary) ? "No inventory/stock data found." : implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling stock context: " . $e->getMessage();
        }
    }

    public function buildDetailedFinanceContext(): string
    {
        try {
            $summary = [];
            $paymentMethodsTable = tableExists('finance_payment_methods', $this->pdo) ? 'finance_payment_methods' : (tableExists('payment_methods', $this->pdo) ? 'payment_methods' : null);
            if ($paymentMethodsTable) {
                $hasBalance = columnExists($paymentMethodsTable, 'balance', $this->pdo);
                $sql = "SELECT name, type, account_number" . ($hasBalance ? ", IFNULL(balance, 0) as balance" : "") . " FROM $paymentMethodsTable WHERE is_active = 1" . $this->companySql();
                $st = $this->pdo->query($sql);
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    $summary[] = "Active Payment Accounts:";
                    foreach ($rows as $r) {
                        $balStr = $hasBalance ? (" - Balance: TZS " . number_format($r['balance'], 2)) : "";
                        $summary[] = sprintf("  - %s (%s)%s", $r['name'], $r['type'], $balStr);
                    }
                }
            }
            
            $expenseTable = tableExists('erp_expenses', $this->pdo) ? 'erp_expenses' : (tableExists('expenses', $this->pdo) ? 'expenses' : null);
            if ($expenseTable) {
                $sql = "SELECT COUNT(*) as count, SUM(amount) as total FROM $expenseTable WHERE 1=1" . $this->companySql();
                $params = [];
                if (!$this->isAdminOrManager()) {
                    $userCol = columnExists($expenseTable, 'created_by', $this->pdo) ? 'created_by' : (columnExists($expenseTable, 'user_id', $this->pdo) ? 'user_id' : null);
                    if ($userCol) {
                        $sql .= " AND $userCol = ?";
                        $params[] = $this->userId;
                    }
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['count'] > 0) {
                    $summary[] = sprintf("- Expenses Logged: %d (Total: TZS %s)", $row['count'], number_format($row['total'], 2));
                }
            }
            
            return empty($summary) ? "No accounts or expense data found." : implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling finance context: " . $e->getMessage();
        }
    }

    public function buildDetailedPayrollContext(): string
    {
        try {
            $summary = [];
            $salariesTable = tableExists('salaries', $this->pdo) ? 'salaries' : (tableExists('erp_employees', $this->pdo) ? 'erp_employees' : null);
            
            if ($this->isAdminOrManager()) {
                if (tableExists('users', $this->pdo)) {
                    $st = $this->pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1" . $this->companySql());
                    $count = $st ? $st->fetchColumn() : 0;
                    $summary[] = "- Total Active Employees: " . $count;
                }
                if ($salariesTable) {
                    $salCol = columnExists($salariesTable, 'basic_salary', $this->pdo) ? 'basic_salary' : (columnExists($salariesTable, 'salary', $this->pdo) ? 'salary' : null);
                    if ($salCol) {
                        $st = $this->pdo->query("SELECT SUM($salCol) FROM $salariesTable WHERE 1=1" . $this->companySql());
                        $totalSal = $st ? $st->fetchColumn() : 0;
                        if ($totalSal) {
                            $summary[] = sprintf("- Monthly Basic Salary Commitment: TZS %s", number_format($totalSal, 2));
                        }
                    }
                }
            } else {
                if ($salariesTable) {
                    $salCol = columnExists($salariesTable, 'basic_salary', $this->pdo) ? 'basic_salary' : (columnExists($salariesTable, 'salary', $this->pdo) ? 'salary' : null);
                    if ($salCol) {
                        $stmt = $this->pdo->prepare("SELECT $salCol FROM $salariesTable WHERE user_id = ?" . $this->companySql());
                        $stmt->execute([$this->userId]);
                        $salVal = $stmt->fetchColumn();
                        if ($salVal) {
                            $summary[] = sprintf("- Your Basic Salary Details: TZS %s", number_format($salVal, 2));
                        }
                    }
                }
            }
            return empty($summary) ? "No payroll details available." : implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling payroll context: " . $e->getMessage();
        }
    }

    public function buildDetailedDeliveriesContext(): string
    {
        try {
            $summary = [];
            $delNotesTable = tableExists('delivery_notes', $this->pdo) ? 'delivery_notes' : (tableExists('erp_delivery_notes', $this->pdo) ? 'erp_delivery_notes' : null);
            if ($delNotesTable) {
                $sql = "SELECT COUNT(*) as count, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending FROM $delNotesTable WHERE 1=1" . $this->companySql();
                $st = $this->pdo->query($sql);
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
                if ($row) {
                    $summary[] = sprintf("- Total Delivery Notes: %d (Pending: %d)", $row['count'], $row['pending']);
                }
            }
            if (tableExists('trips', $this->pdo)) {
                $sql = "SELECT COUNT(*) as count, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active FROM trips WHERE 1=1" . $this->companySql();
                $st = $this->pdo->query($sql);
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
                if ($row) {
                    $summary[] = sprintf("- Total Trips Logged: %d (Active: %d)", $row['count'], $row['active']);
                }
            }
            return empty($summary) ? "No deliveries or dispatch notes found." : implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling deliveries context: " . $e->getMessage();
        }
    }

    public function buildDetailedTodoContext(): string
    {
        try {
            $summary = [];
            if (tableExists('tasks', $this->pdo)) {
                $sql = "SELECT COUNT(*) as count, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed FROM tasks WHERE 1=1" . $this->companySql();
                $params = [];
                if (!$this->isAdminOrManager()) {
                    $sql .= " AND user_id = ?";
                    $params[] = $this->userId;
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $summary[] = sprintf("- Active Tasks: %d (Completed: %d)", $row['count'], $row['completed']);
                }
            }
            return empty($summary) ? "No active tasks found." : implode("\n", $summary);
        } catch (Throwable $e) {
            return "Error compiling todo context: " . $e->getMessage();
        }
    }

    public function buildFullSystemReportContextText(): string
    {
        $report = [];
        $report[] = "=============================================";
        $report[] = "         FULL SYSTEM AUDIT REPORT           ";
        $report[] = "=============================================";
        $report[] = "Role Scope: " . strtoupper($this->role);
        $report[] = "Company ID: " . $this->companyId;
        $report[] = "Timestamp: " . date('Y-m-d H:i:s');
        $report[] = "";

        // 1. Process & Approve Expenses
        $report[] = "[1/22] Process & Approve Expenses (Vouchers):";
        $report[] = $this->buildVouchersContext();
        $report[] = "";

        // 2. Attendance
        $report[] = "[2/22] Attendance (Time Logs):";
        $report[] = $this->buildAttendanceContext();
        $report[] = "";

        // 3. Delivery Logistics
        $report[] = "[3/22] Delivery Logistics (Trips):";
        $report[] = $this->buildDetailedDeliveriesContext();
        $report[] = "";

        // 4. Outstanding Invoices
        $report[] = "[4/22] Outstanding Invoices:";
        try {
            if (tableExists('erp_outstanding_invoices', $this->pdo)) {
                $sql = "SELECT COUNT(*) as cnt, SUM(amount) as amt FROM erp_outstanding_invoices WHERE status = 'outstanding'";
                $stmt = $this->pdo->query($sql);
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($row && $row['cnt'] > 0) {
                    $report[] = sprintf("- Unpaid Invoices: %d (Total Outstanding: TZS %s)", $row['cnt'], number_format($row['amt'], 2));
                } else {
                    $report[] = "- No outstanding invoices found.";
                }
            } else {
                $report[] = "- Outstanding invoices data not available.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying outstanding invoices: " . $e->getMessage();
        }
        $report[] = "";

        // 5. Customer Email (Communications)
        $report[] = "[5/22] Customer Email & Communications:";
        try {
            if (tableExists('messages', $this->pdo)) {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM messages");
                $cnt = $stmt ? $stmt->fetchColumn() : 0;
                $report[] = "- Total System Messages: " . $cnt;
            } else {
                $report[] = "- Messaging logs not available.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying messages: " . $e->getMessage();
        }
        $report[] = "";

        // 6. Cash Accounts
        $report[] = "[6/22] Cash Accounts / Funds:";
        try {
            $paymentMethodsTable = tableExists('finance_payment_methods', $this->pdo) ? 'finance_payment_methods' : (tableExists('payment_methods', $this->pdo) ? 'payment_methods' : null);
            if ($paymentMethodsTable) {
                $hasBalance = columnExists($paymentMethodsTable, 'balance', $this->pdo);
                if ($hasBalance) {
                    $sql = "SELECT name, balance FROM $paymentMethodsTable WHERE (LOWER(name) LIKE '%cash%' OR LOWER(type) = 'cash') AND is_active = 1" . $this->companySql();
                    $stmt = $this->pdo->query($sql);
                    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    if (!empty($rows)) {
                        foreach ($rows as $r) {
                            $report[] = sprintf("  - %s Balance: TZS %s", $r['name'], number_format($r['balance'], 2));
                        }
                    } else {
                        $report[] = "- No active petty cash accounts found.";
                    }
                } else {
                    $report[] = "- Petty cash balance fields not active.";
                }
            } else {
                $report[] = "- Petty cash tables not available.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying petty cash: " . $e->getMessage();
        }
        $report[] = "";

        // 7. Expenses
        $report[] = "[7/22] Expenses Records:";
        $report[] = $this->buildDetailedFinanceContext();
        $report[] = "";

        // 8. Payroll
        $report[] = "[8/22] Payroll & Salaries:";
        $report[] = $this->buildDetailedPayrollContext();
        $report[] = "";

        // 9. Revenue & Debt
        $report[] = "[9/22] Revenue & Debt:";
        $report[] = $this->buildSalesAndRevenueContext();
        $report[] = "";

        // 10. Accounting
        $report[] = "[10/22] Accounting (Journal Entries):";
        try {
            if (tableExists('erp_journal_entries', $this->pdo)) {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM erp_journal_entries");
                $cnt = $stmt ? $stmt->fetchColumn() : 0;
                $report[] = "- Total Journal Entries: " . $cnt;
            } else {
                $report[] = "- Accounting ledger entries not available.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying journals: " . $e->getMessage();
        }
        $report[] = "";

        // 11. Balances
        $report[] = "[11/22] Balances & Accounts:";
        try {
            if (tableExists('erp_bank_accounts', $this->pdo)) {
                $sql = "SELECT account_name, bank_name, balance FROM erp_bank_accounts WHERE 1=1" . $this->companySql();
                $stmt = $this->pdo->query($sql);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $report[] = sprintf("  - %s (%s): TZS %s", $r['account_name'], $r['bank_name'], number_format($r['balance'], 2));
                    }
                } else {
                    $report[] = "- No active bank accounts found.";
                }
            } else {
                $report[] = "- Bank balance ledger not available.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying balances: " . $e->getMessage();
        }
        $report[] = "";

        // 12. Stock Management
        $report[] = "[12/22] Stock Management:";
        $report[] = $this->buildStocksContext();
        $report[] = "";

        // 13. Sales
        $report[] = "[13/22] Sales & Quotes:";
        $report[] = $this->buildDetailedSalesContext();
        $report[] = "";

        // 14. Statement (Receivables)
        $report[] = "[14/22] Customer Statements & Balances:";
        try {
            if (tableExists('erp_customers', $this->pdo)) {
                $sql = "SELECT COUNT(*) as total, SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as total_debt FROM erp_customers WHERE 1=1" . $this->companySql();
                $stmt = $this->pdo->query($sql);
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($row && $row['total'] > 0) {
                    $report[] = sprintf("- Active Customers: %d (Total Receivables Outstanding: TZS %s)", $row['total'], number_format($row['total_debt'], 2));
                } else {
                    $report[] = "- No customer balances recorded.";
                }
            } else {
                $report[] = "- Customer registers not available.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying statements: " . $e->getMessage();
        }
        $report[] = "";

        // 15. Dispatch
        $report[] = "[15/22] Dispatch Notes:";
        try {
            $delNotesTable = tableExists('delivery_notes', $this->pdo) ? 'delivery_notes' : (tableExists('erp_delivery_notes', $this->pdo) ? 'erp_delivery_notes' : null);
            if ($delNotesTable) {
                $sql = "SELECT status, COUNT(*) as cnt FROM $delNotesTable GROUP BY status";
                $stmt = $this->pdo->query($sql);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $report[] = sprintf("  - %s Dispatch Notes: %d", ucfirst($r['status']), $r['cnt']);
                    }
                } else {
                    $report[] = "- No dispatch notes logged.";
                }
            } else {
                $report[] = "- Dispatch registry not available.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying dispatch: " . $e->getMessage();
        }
        $report[] = "";

        // 16. To-Do List
        $report[] = "[16/22] To-Do Tasks:";
        $report[] = $this->buildDetailedTodoContext();
        $report[] = "";

        // 17. Performance
        $report[] = "[17/22] Performance & Leaderboard:";
        $report[] = $this->buildPerformanceContext();
        $report[] = "";

        // 18. Settings
        $report[] = "[18/22] Settings & Configuration:";
        try {
            if (tableExists('erp_settings', $this->pdo)) {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM erp_settings");
                $cnt = $stmt ? $stmt->fetchColumn() : 0;
                $report[] = "- Configured setting variables: " . $cnt;
            } else {
                $report[] = "- Configuration register not available.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying settings: " . $e->getMessage();
        }
        $report[] = "";

        // 19. Suggestions
        $report[] = "[19/22] Suggestions Box:";
        try {
            if (tableExists('developer_suggestions', $this->pdo)) {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM developer_suggestions");
                $cnt = $stmt ? $stmt->fetchColumn() : 0;
                $report[] = "- Total System Suggestions submitted: " . $cnt;
            } else {
                $report[] = "- Suggestions system is inactive.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying suggestions: " . $e->getMessage();
        }
        $report[] = "";

        // 20. Data Analysis & Reports
        $report[] = "[20/22] Data Analysis Logs (AI Usage):";
        try {
            if (tableExists('ai_usage_logs', $this->pdo)) {
                $stmt = $this->pdo->query("SELECT COUNT(*), SUM(total_tokens) FROM ai_usage_logs");
                $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : null;
                if ($row) {
                    $report[] = sprintf("- System AI requests: %d (Total tokens consumed: %d)", $row[0], $row[1]);
                }
            } else {
                $report[] = "- Data analysis logs empty.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying analysis: " . $e->getMessage();
        }
        $report[] = "";

        // 21. Write Letter
        $report[] = "[21/22] Write Letter (Official Requests):";
        try {
            if (tableExists('official_letters', $this->pdo)) {
                $sql = "SELECT status, COUNT(*) as cnt FROM official_letters GROUP BY status";
                $stmt = $this->pdo->query($sql);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $report[] = sprintf("  - Status '%s': %d letters", $r['status'], $r['cnt']);
                    }
                } else {
                    $report[] = "- No official requests found.";
                }
            } else {
                $report[] = "- Letters database inactive.";
            }
        } catch (Throwable $e) {
            $report[] = "- Error querying letters: " . $e->getMessage();
        }
        $report[] = "";

        // 22. Layout
        $report[] = "[22/22] Custom Themes & Layout:";
        $report[] = "- Dark Glass theme, Indigo theme, Emerald theme available.";
        $report[] = "=============================================";

        return implode("\n", $report);
    }

    /**
     * Build detailed system update and integrity report context.
     */
    public function buildSystemUpdateContext(): string
    {
        $lines = [];
        $lines[] = "=== Database Migrations (Last 10) ===";
        try {
            if (tableExists('migrations', $this->pdo)) {
                $st = $this->pdo->query("SELECT migration, batch FROM migrations ORDER BY id DESC LIMIT 10");
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $lines[] = sprintf("- Batch %d: %s", $r['batch'], $r['migration']);
                    }
                } else {
                    $lines[] = "No migrations found.";
                }
            } else {
                $lines[] = "Migrations table not present.";
            }
        } catch (Throwable $e) {
            $lines[] = "Error query migrations: " . $e->getMessage();
        }
        $lines[] = "";

        $lines[] = "=== Developer Suggestions (Last 5) ===";
        try {
            if (tableExists('developer_suggestions', $this->pdo)) {
                $st = $this->pdo->query("SELECT suggestion, status, created_at FROM developer_suggestions ORDER BY id DESC LIMIT 5");
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $lines[] = sprintf("- [%s] %s (Created: %s)", $r['status'], $r['suggestion'], $r['created_at']);
                    }
                } else {
                    $lines[] = "No suggestions recorded.";
                }
            } else {
                $lines[] = "Developer suggestions table not present.";
            }
        } catch (Throwable $e) {
            $lines[] = "Error query suggestions: " . $e->getMessage();
        }
        $lines[] = "";

        $lines[] = "=== System Activities & Logs ===";
        try {
            if (tableExists('erp_activities', $this->pdo)) {
                $st = $this->pdo->query("SELECT entity_type, action, description, created_at FROM erp_activities ORDER BY id DESC LIMIT 10");
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $lines[] = sprintf("- [%s] %s: %s (Time: %s)", $r['entity_type'], $r['action'], $r['description'], $r['created_at']);
                    }
                } else {
                    $lines[] = "No recent ERP activities logged.";
                }
            } else {
                $lines[] = "Activities table not active.";
            }
        } catch (Throwable $e) {
            $lines[] = "Error query activities: " . $e->getMessage();
        }
        $lines[] = "";

        return implode("\n", $lines);
    }

    /**
     * Build highly detailed cross-module business intelligence report context.
     */
    public function buildSmartReportContext(): string
    {
        $lines = [];
        $lines[] = "=== DETAILED CROSS-MODULE SMART REPORT CONTEXT ===";
        $lines[] = "Generated at: " . date('Y-m-d H:i:s');
        $lines[] = "";

        // 1. Detailed Sales
        $lines[] = "--- Sales & Customer Invoices (Last 10) ---";
        try {
            if (tableExists('invoices', $this->pdo)) {
                $sql = "SELECT i.invoice_number, i.invoice_date, c.company_name, i.total_amount, i.balance_due, i.status 
                        FROM invoices i 
                        LEFT JOIN customers c ON i.customer_id = c.id 
                        ORDER BY i.id DESC LIMIT 10";
                $st = $this->pdo->query($sql);
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $lines[] = sprintf("- Inv #%s (%s) | Customer: %s | Total: %s | Due: %s | Status: %s",
                            $r['invoice_number'], $r['invoice_date'], $r['company_name'] ?? 'Walk-in',
                            number_format((float)$r['total_amount']), number_format((float)$r['balance_due']), $r['status']
                        );
                    }
                } else {
                    $lines[] = "No invoices found in the system.";
                }
            } else {
                $lines[] = "Invoices table not present.";
            }
        } catch (Throwable $e) {
            $lines[] = "Error query invoices: " . $e->getMessage();
        }
        $lines[] = "";

        // 2. Detailed Finance & Expenses
        $lines[] = "--- Recent Expenses (Last 10) ---";
        try {
            if (tableExists('erp_expenses', $this->pdo)) {
                $sql = "SELECT er.amount, er.description, er.status, er.date, u.full_name 
                        FROM erp_expenses er 
                        LEFT JOIN users u ON er.created_by = u.id 
                        ORDER BY er.id DESC LIMIT 10";
                $st = $this->pdo->query($sql);
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $lines[] = sprintf("- Date: %s | Claimant: %s | Amount: %s | Status: %s | Desc: %s",
                            $r['date'], $r['full_name'] ?? 'System', number_format((float)$r['amount']), $r['status'], $r['description']
                        );
                    }
                } else {
                    $lines[] = "No recent expenses logged.";
                }
            } else {
                $lines[] = "Expenses table not present.";
            }
        } catch (Throwable $e) {
            $lines[] = "Error query expenses: " . $e->getMessage();
        }
        $lines[] = "";

        // 3. Detailed Stock & Inventory
        $lines[] = "--- Low Stock Products (Reorder Needed) ---";
        try {
            if (tableExists('products', $this->pdo) && tableExists('stock', $this->pdo)) {
                $sql = "SELECT p.name, p.product_code, s.quantity, p.reorder_level 
                        FROM products p 
                        JOIN stock s ON p.id = s.product_id 
                        WHERE s.quantity <= p.reorder_level 
                        ORDER BY s.quantity ASC LIMIT 10";
                $st = $this->pdo->query($sql);
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $lines[] = sprintf("- Product: %s [%s] | Current Stock: %d | Reorder Level: %d (ALERT: Low Stock)",
                            $r['name'], $r['product_code'] ?? 'N/A', (int)$r['quantity'], (int)$r['reorder_level']
                        );
                    }
                } else {
                    $lines[] = "No low stock items. Inventory healthy.";
                }
            } else {
                $lines[] = "Products or stock tables not active.";
            }
        } catch (Throwable $e) {
            $lines[] = "Error query stock: " . $e->getMessage();
        }
        $lines[] = "";

        // 4. Detailed Attendance
        $lines[] = "--- Recent Lateness & Attendance Logs (Last 10) ---";
        try {
            $tbl = tableExists('attendance', $this->pdo) ? 'attendance' : (tableExists('attendance_records', $this->pdo) ? 'attendance_records' : '');
            if ($tbl !== '') {
                $sql = "SELECT u.full_name, a.date, a.time_in, a.status 
                        FROM {$tbl} a 
                        JOIN users u ON a.user_id = u.id 
                        ORDER BY a.id DESC LIMIT 10";
                $st = $this->pdo->query($sql);
                $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
                if (!empty($rows)) {
                    foreach ($rows as $r) {
                        $lines[] = sprintf("- Employee: %s | Date: %s | Time-in: %s | Status: %s",
                            $r['full_name'], $r['date'], $r['time_in'], $r['status'] ?? 'Checked-in'
                        );
                    }
                } else {
                    $lines[] = "No attendance logs found.";
                }
            } else {
                $lines[] = "Attendance table not active.";
            }
        } catch (Throwable $e) {
            $lines[] = "Error query attendance: " . $e->getMessage();
        }
        $lines[] = "";

        // 5. System Update Context
        $lines[] = "--- System Update & Migration Status ---";
        $lines[] = $this->buildSystemUpdateContext();

        return implode("\n", $lines);
    }
}

class AIAssistantAnomaliesScanner
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Run all scans and output error / warning logs.
     */
    public function runAllScans(): array
    {
        return [
            'paid_unapproved' => $this->scanPaidBeforeApproval(),
            'duplicates' => $this->scanDuplicateVouchers(),
            'zero_attachments' => $this->scanVouchersZeroAttachments(),
            'attendance_geofence' => $this->scanAttendanceGeofenceViolations()
        ];
    }

    /**
     * Scan 1: Vouchers marked as PAID but lacking Admin approval.
     */
    public function scanPaidBeforeApproval(): array
    {
        try {
            if (!tableExists('payment_vouchers', $this->pdo)) return [];
            
            $stmt = $this->pdo->query("
                SELECT id, voucher_no, payee_name, total_amount, currency, status, date_created 
                FROM payment_vouchers 
                WHERE is_paid = 1 AND LOWER(status) != 'approved'
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Scan 2: Duplicate vouchers (same payee, amount, created within 24h).
     */
    public function scanDuplicateVouchers(): array
    {
        try {
            if (!tableExists('payment_vouchers', $this->pdo)) return [];
            
            $stmt = $this->pdo->query("
                SELECT pv1.id as voucher_a_id, pv1.voucher_no as voucher_a_no,
                       pv2.id as voucher_b_id, pv2.voucher_no as voucher_b_no,
                       pv1.payee_name, pv1.total_amount, pv1.currency, pv1.date_created
                FROM payment_vouchers pv1
                JOIN payment_vouchers pv2 ON pv1.payee_name = pv2.payee_name 
                     AND pv1.total_amount = pv2.total_amount 
                     AND pv1.currency = pv2.currency
                     AND pv1.id < pv2.id
                     AND ABS(TIMESTAMPDIFF(HOUR, pv1.date_created, pv2.date_created)) <= 24
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Scan 3: Large amount vouchers (> 100,000 TZS) submitted with 0 attachments.
     */
    public function scanVouchersZeroAttachments(): array
    {
        try {
            if (!tableExists('payment_vouchers', $this->pdo)) return [];

            $stmt = $this->pdo->query("
                SELECT pv.id, pv.voucher_no, pv.payee_name, pv.total_amount, pv.currency, pv.date_created
                FROM payment_vouchers pv
                WHERE pv.total_amount >= 100000 
                  AND pv.currency = 'TZS'
                  AND pv.status = 'pending'
                  AND NOT EXISTS (
                      SELECT 1 FROM voucher_attachments va WHERE va.voucher_id = pv.id
                  )
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Scan 4: Attendance sign-ins flagged as 'failed_geofence' or marked 'late' with abnormal hours.
     */
    public function scanAttendanceGeofenceViolations(): array
    {
        try {
            if (!tableExists('attendance', $this->pdo)) return [];

            $stmt = $this->pdo->query("
                SELECT a.id, a.user_id, u.full_name, a.date, a.time_in, a.status, a.ip_address 
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                WHERE LOWER(a.status) = 'failed_geofence' OR (LOWER(a.status) = 'late' AND HOUR(a.time_in) >= 12)
                ORDER BY a.date DESC LIMIT 10
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

class AIAssistantGrowthForecaster
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Run linear regression on last 6 months of revenue or voucher expense aggregates to forecast.
     */
    public function forecastNextMonthSales(): array
    {
        try {
            // Check if revenue table exists, fallback to payment_vouchers expenses
            $source = 'expenses';
            $query = "
                SELECT 
                    DATE_FORMAT(date_created, '%Y-%m') as month_period,
                    SUM(total_amount) as total_amount
                FROM payment_vouchers 
                WHERE status = 'approved' OR is_paid = 1
                GROUP BY DATE_FORMAT(date_created, '%Y-%m')
                ORDER BY month_period DESC
                LIMIT 6
            ";

            if (tableExists('revenue_entries', $this->pdo)) {
                $source = 'revenue';
                $query = "
                    SELECT 
                        DATE_FORMAT(entry_date, '%Y-%m') as month_period,
                        SUM(amount_total) as total_amount
                    FROM revenue_entries
                    WHERE approval_status = 'Approved'
                    GROUP BY DATE_FORMAT(entry_date, '%Y-%m')
                    ORDER BY month_period DESC
                    LIMIT 6
                ";
            }

            $stmt = $this->pdo->query($query);
            $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

            $n = count($history);
            if ($n < 2) {
                return [
                    'success' => false,
                    'error' => 'Insufficient data points (minimum 2 months required to calculate trend).',
                    'history' => $history,
                    'forecast_value' => 0,
                    'growth_rate' => 0
                ];
            }

            // Simple linear regression (y = mx + c)
            $sumX = 0;
            $sumY = 0;
            $sumXY = 0;
            $sumXX = 0;

            for ($i = 0; $i < $n; $i++) {
                $x = $i; // Months indices
                $y = (float) $history[$i]['total_amount'];
                $sumX += $x;
                $sumY += $y;
                $sumXY += ($x * $y);
                $sumXX += ($x * $x);
            }

            $m = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
            $c = ($sumY - $m * $sumX) / $n;

            // Forecast next month (index $n)
            $forecastValue = max(0, $m * $n + $c);

            // Calculate growth rate compared to the last month
            $lastMonthValue = (float) $history[$n - 1]['total_amount'];
            $growthRate = $lastMonthValue > 0 ? (($forecastValue - $lastMonthValue) / $lastMonthValue) * 100 : 0;

            return [
                'success' => true,
                'source' => $source,
                'history' => $history,
                'forecast_value' => $forecastValue,
                'growth_rate' => $growthRate,
                'slope' => $m
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Regression calculations failed: ' . $e->getMessage()
            ];
        }
    }
}

/**
 * Ensure ai_logs exists on the given PDO (per-database; avoids ensureAiSchema static flag).
 */
function ai_assistant_ensure_logs_table(PDO $pdo): bool
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            user_id INT NOT NULL,
            module_name VARCHAR(100) NULL,
            question TEXT NOT NULL,
            response TEXT NOT NULL,
            thread_json MEDIUMTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ai_logs_company (company_id, created_at),
            KEY idx_ai_logs_user (user_id, company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        try {
            $pdo->exec('ALTER TABLE ai_logs ADD COLUMN thread_json MEDIUMTEXT NULL AFTER response');
        } catch (Throwable $e) {
            // column already exists
        }
        return true;
    } catch (Throwable $e) {
        error_log('ai_assistant_ensure_logs_table: ' . $e->getMessage());
        return false;
    }
}

/**
 * @return array<int, array{role:string,content:string,feedback?:string}>
 */
function ai_assistant_normalize_thread(array $thread): array
{
    $normalized = [];
    foreach ($thread as $item) {
        if (!is_array($item)) {
            continue;
        }
        $role = (string) ($item['role'] ?? '');
        $content = trim((string) ($item['content'] ?? ''));
        if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
            continue;
        }
        $entry = ['role' => $role, 'content' => $content];
        if ($role === 'assistant') {
            $feedback = (string) ($item['feedback'] ?? '');
            if (in_array($feedback, ['up', 'down'], true)) {
                $entry['feedback'] = $feedback;
            }
            if (!empty($item['preference_prompt'])) {
                $entry['preference_prompt'] = true;
            }
            if (!empty($item['rich']) && is_array($item['rich'])) {
                $entry['rich'] = $item['rich'];
            }
        }
        $normalized[] = $entry;
    }
    return $normalized;
}

/**
 * @return array{id:int,question:string,response:string,created_at:string,thread:array<int,array{role:string,content:string}>}
 */
function ai_assistant_format_chat_row(array $row): array
{
    $question = (string) ($row['question'] ?? '');
    $response = (string) ($row['response'] ?? '');
    $thread = [];
    $rawThread = (string) ($row['thread_json'] ?? '');
    if ($rawThread !== '') {
        $decoded = json_decode($rawThread, true);
        if (is_array($decoded)) {
            $thread = ai_assistant_normalize_thread($decoded);
        }
    }
    if ($thread === [] && $question !== '') {
        $thread[] = ['role' => 'user', 'content' => $question];
        if ($response !== '') {
            $thread[] = ['role' => 'assistant', 'content' => $response];
        }
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'question' => $question,
        'response' => $response,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'thread' => $thread,
    ];
}

/**
 * @return array<int, array{id:int,question:string,response:string,created_at:string,thread:array}>
 */
function ai_assistant_fetch_recent_chats(PDO $pdo, int $userId, int $companyId, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    $sources = [$pdo];

    if (function_exists('ai_pdo')) {
        $control = ai_pdo();
        if ($control instanceof PDO && $control !== $pdo) {
            $sources[] = $control;
        }
    }

    $merged = [];
    $seen = [];

    foreach ($sources as $source) {
        if (!ai_assistant_ensure_logs_table($source)) {
            continue;
        }
        try {
            $stmt = $source->prepare(
                'SELECT id, question, response, thread_json, created_at
                 FROM ai_logs
                 WHERE user_id = ? AND company_id = ?
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute([$userId, $companyId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $formatted = ai_assistant_format_chat_row($row);
                $dedupeKey = md5($formatted['question'] . '|' . $formatted['response'] . '|' . $formatted['created_at']);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $merged[] = $formatted;
            }
        } catch (Throwable $e) {
            error_log('ai_assistant_fetch_recent_chats: ' . $e->getMessage());
        }
    }

    usort($merged, static function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    return array_slice($merged, 0, $limit);
}

function ai_assistant_insert_chat_log(PDO $pdo, int $companyId, int $userId, string $module, array $thread): ?array
{
    if (!ai_assistant_ensure_logs_table($pdo)) {
        return null;
    }

    $thread = ai_assistant_normalize_thread($thread);
    if ($thread === []) {
        return null;
    }

    $firstUser = null;
    $lastAssistant = '';
    foreach ($thread as $item) {
        if ($item['role'] === 'user' && $firstUser === null) {
            $firstUser = $item['content'];
        }
        if ($item['role'] === 'assistant') {
            $lastAssistant = $item['content'];
        }
    }
    if ($firstUser === null || $lastAssistant === '') {
        return null;
    }

    $module = trim($module) !== '' ? trim($module) : 'general';
    $threadJson = json_encode($thread, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        'INSERT INTO ai_logs (company_id, user_id, module_name, question, response, thread_json) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$companyId, $userId, $module, $firstUser, $lastAssistant, $threadJson]);
    $id = (int) $pdo->lastInsertId();
    if ($id <= 0) {
        return null;
    }

    $createdAt = date('Y-m-d H:i:s');
    $ts = $pdo->prepare('SELECT created_at FROM ai_logs WHERE id = ? AND company_id = ? AND user_id = ? LIMIT 1');
    $ts->execute([$id, $companyId, $userId]);
    $row = $ts->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['created_at'])) {
        $createdAt = (string) $row['created_at'];
    }

    return ai_assistant_format_chat_row([
        'id' => $id,
        'question' => $firstUser,
        'response' => $lastAssistant,
        'thread_json' => $threadJson,
        'created_at' => $createdAt,
    ]);
}

function ai_assistant_update_chat_log(PDO $pdo, int $companyId, int $userId, int $chatId, array $thread): ?array
{
    if (!ai_assistant_ensure_logs_table($pdo) || $chatId <= 0) {
        return null;
    }

    $thread = ai_assistant_normalize_thread($thread);
    if ($thread === []) {
        return null;
    }

    $firstUser = null;
    $lastAssistant = '';
    foreach ($thread as $item) {
        if ($item['role'] === 'user' && $firstUser === null) {
            $firstUser = $item['content'];
        }
        if ($item['role'] === 'assistant') {
            $lastAssistant = $item['content'];
        }
    }
    if ($firstUser === null || $lastAssistant === '') {
        return null;
    }

    $threadJson = json_encode($thread, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        'UPDATE ai_logs
         SET question = ?, response = ?, thread_json = ?
         WHERE id = ? AND company_id = ? AND user_id = ?'
    );
    $stmt->execute([$firstUser, $lastAssistant, $threadJson, $chatId, $companyId, $userId]);
    if ($stmt->rowCount() < 1) {
        return null;
    }

    $ts = $pdo->prepare('SELECT id, question, response, thread_json, created_at FROM ai_logs WHERE id = ? AND company_id = ? AND user_id = ? LIMIT 1');
    $ts->execute([$chatId, $companyId, $userId]);
    $row = $ts->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return ai_assistant_format_chat_row($row);
}

/**
 * Save or update a full conversation thread.
 *
 * @param array<int, array{role:string,content:string}> $thread
 * @return array{id:int,question:string,response:string,created_at:string,thread:array}|null
 */
function ai_assistant_save_chat_session(PDO $pdo, int $companyId, int $userId, string $module, array $thread, ?int $chatId = null): ?array
{
    try {
        if ($chatId !== null && $chatId > 0) {
            $saved = ai_assistant_update_chat_log($pdo, $companyId, $userId, $chatId, $thread);
        } else {
            $saved = ai_assistant_insert_chat_log($pdo, $companyId, $userId, $module, $thread);
        }
        return $saved;
    } catch (Throwable $e) {
        error_log('ai_assistant_save_chat_session: ' . $e->getMessage());
        return null;
    }
}

/**
 * @deprecated Use ai_assistant_save_chat_session()
 */
function ai_assistant_save_chat(PDO $pdo, int $companyId, int $userId, string $module, string $question, string $response): ?array
{
    return ai_assistant_save_chat_session($pdo, $companyId, $userId, $module, [
        ['role' => 'user', 'content' => trim($question)],
        ['role' => 'assistant', 'content' => trim($response)],
    ], null);
}

/**
 * Delete a saved chat for the current user.
 */
function ai_assistant_delete_chat(PDO $pdo, int $companyId, int $userId, int $chatId): bool
{
    if ($chatId <= 0) {
        return false;
    }

    $deleted = false;
    $sources = [$pdo];
    if (function_exists('ai_pdo')) {
        $control = ai_pdo();
        if ($control instanceof PDO && $control !== $pdo) {
            $sources[] = $control;
        }
    }

    foreach ($sources as $source) {
        if (!ai_assistant_ensure_logs_table($source)) {
            continue;
        }
        try {
            $stmt = $source->prepare(
                'DELETE FROM ai_logs WHERE id = ? AND company_id = ? AND user_id = ?'
            );
            $stmt->execute([$chatId, $companyId, $userId]);
            if ($stmt->rowCount() > 0) {
                $deleted = true;
            }
        } catch (Throwable $e) {
            error_log('ai_assistant_delete_chat: ' . $e->getMessage());
        }
    }

    return $deleted;
}

function ai_assistant_ensure_feedback_table(PDO $pdo): bool
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_response_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            user_id INT NOT NULL,
            chat_id INT NOT NULL,
            message_index INT NOT NULL,
            rating TINYINT NOT NULL,
            module_name VARCHAR(100) NULL,
            user_question TEXT NULL,
            assistant_response TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ai_feedback (chat_id, message_index, user_id),
            KEY idx_ai_feedback_company (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        return true;
    } catch (Throwable $e) {
        error_log('ai_assistant_ensure_feedback_table: ' . $e->getMessage());
        return false;
    }
}

/**
 * Load a single chat row for the current user from company or control DB.
 *
 * @return array<string, mixed>|null
 */
function ai_assistant_fetch_chat_row(PDO $pdo, int $companyId, int $userId, int $chatId): ?array
{
    if ($chatId <= 0) {
        return null;
    }

    $sources = [$pdo];
    if (function_exists('ai_pdo')) {
        $control = ai_pdo();
        if ($control instanceof PDO && $control !== $pdo) {
            $sources[] = $control;
        }
    }

    foreach ($sources as $source) {
        if (!ai_assistant_ensure_logs_table($source)) {
            continue;
        }
        try {
            $stmt = $source->prepare(
                'SELECT id, question, response, thread_json, created_at, module_name
                 FROM ai_logs
                 WHERE id = ? AND company_id = ? AND user_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$chatId, $companyId, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['_pdo'] = $source;
                return $row;
            }
        } catch (Throwable $e) {
            error_log('ai_assistant_fetch_chat_row: ' . $e->getMessage());
        }
    }

    return null;
}

/**
 * Save thumbs up/down feedback on an assistant message.
 */
function ai_assistant_save_message_feedback(
    PDO $pdo,
    int $companyId,
    int $userId,
    int $chatId,
    int $messageIndex,
    string $rating
): array {
    if ($chatId <= 0 || $messageIndex < 0 || !in_array($rating, ['up', 'down'], true)) {
        return ['success' => false, 'error' => 'Invalid feedback.'];
    }

    $row = ai_assistant_fetch_chat_row($pdo, $companyId, $userId, $chatId);
    if ($row === null) {
        return ['success' => false, 'error' => 'Chat not found.'];
    }

    $thread = [];
    $rawThread = (string) ($row['thread_json'] ?? '');
    if ($rawThread !== '') {
        $decoded = json_decode($rawThread, true);
        if (is_array($decoded)) {
            $thread = ai_assistant_normalize_thread($decoded);
        }
    }
    if ($thread === []) {
        $formatted = ai_assistant_format_chat_row($row);
        $thread = $formatted['thread'];
    }

    if (!isset($thread[$messageIndex]) || ($thread[$messageIndex]['role'] ?? '') !== 'assistant') {
        return ['success' => false, 'error' => 'Message not found.'];
    }

    $thread[$messageIndex]['feedback'] = $rating;
    $threadJson = json_encode($thread, JSON_UNESCAPED_UNICODE);

    /** @var PDO $sourcePdo */
    $sourcePdo = $row['_pdo'];
    try {
        $stmt = $sourcePdo->prepare(
            'UPDATE ai_logs SET thread_json = ? WHERE id = ? AND company_id = ? AND user_id = ?'
        );
        $stmt->execute([$threadJson, $chatId, $companyId, $userId]);
        if ($stmt->rowCount() < 1) {
            return ['success' => false, 'error' => 'Could not save feedback.'];
        }
    } catch (Throwable $e) {
        error_log('ai_assistant_save_message_feedback update: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Could not save feedback.'];
    }

    $userQuestion = '';
    for ($i = $messageIndex - 1; $i >= 0; $i--) {
        if (($thread[$i]['role'] ?? '') === 'user') {
            $userQuestion = (string) ($thread[$i]['content'] ?? '');
            break;
        }
    }
    $assistantResponse = (string) ($thread[$messageIndex]['content'] ?? '');
    $moduleName = (string) ($row['module_name'] ?? 'general');
    $ratingValue = $rating === 'up' ? 1 : -1;

    foreach ([$pdo, $sourcePdo] as $feedbackPdo) {
        if (!$feedbackPdo instanceof PDO || !ai_assistant_ensure_feedback_table($feedbackPdo)) {
            continue;
        }
        try {
            $stmt = $feedbackPdo->prepare(
                'INSERT INTO ai_response_feedback
                    (company_id, user_id, chat_id, message_index, rating, module_name, user_question, assistant_response)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    rating = VALUES(rating),
                    module_name = VALUES(module_name),
                    user_question = VALUES(user_question),
                    assistant_response = VALUES(assistant_response),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                $companyId,
                $userId,
                $chatId,
                $messageIndex,
                $ratingValue,
                $moduleName,
                $userQuestion,
                $assistantResponse,
            ]);
        } catch (Throwable $e) {
            error_log('ai_assistant_save_message_feedback log: ' . $e->getMessage());
        }
    }

    ai_assistant_append_preference_note_from_feedback($pdo, $companyId, $userId, $rating, $userQuestion);

    $followUp = null;
    if ($rating === 'down') {
        $followUp = ai_assistant_preference_follow_up_message('down', $userQuestion);
    } elseif ($rating === 'up') {
        $prefs = ai_assistant_get_user_preferences($pdo, $companyId, $userId);
        if (!ai_assistant_has_defined_preferences($prefs) && ((int) ($prefs['assist_count'] ?? 0)) >= 3) {
            $followUp = ai_assistant_preference_follow_up_message('up', $userQuestion);
        }
    }

    if ($followUp !== null && !ai_assistant_thread_has_open_preference_prompt($thread)) {
        $thread[] = [
            'role' => 'assistant',
            'content' => $followUp,
            'preference_prompt' => true,
        ];
        $threadJson = json_encode($thread, JSON_UNESCAPED_UNICODE);
        try {
            $stmt = $sourcePdo->prepare(
                'UPDATE ai_logs SET thread_json = ? WHERE id = ? AND company_id = ? AND user_id = ?'
            );
            $stmt->execute([$threadJson, $chatId, $companyId, $userId]);
        } catch (Throwable $e) {
            error_log('ai_assistant_save_message_feedback follow-up: ' . $e->getMessage());
        }

        $prefs = ai_assistant_get_user_preferences($pdo, $companyId, $userId);
        $prefs['last_preference_prompt_at'] = date('Y-m-d H:i:s');
        ai_assistant_save_user_preferences($pdo, $companyId, $userId, $prefs);
    }

    return [
        'success' => true,
        'chat_id' => $chatId,
        'message_index' => $messageIndex,
        'feedback' => $rating,
        'thread' => $thread,
        'followUpMessage' => $followUp,
    ];
}

function ai_assistant_ensure_preferences_table(PDO $pdo): bool
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_user_answer_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            user_id INT NOT NULL,
            answer_length VARCHAR(20) NULL,
            answer_style VARCHAR(30) NULL,
            custom_instructions TEXT NULL,
            learned_notes TEXT NULL,
            assist_count INT NOT NULL DEFAULT 0,
            last_preference_prompt_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ai_user_prefs (company_id, user_id),
            KEY idx_ai_user_prefs_user (user_id, company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        return true;
    } catch (Throwable $e) {
        error_log('ai_assistant_ensure_preferences_table: ' . $e->getMessage());
        return false;
    }
}

/**
 * @return array<string, mixed>
 */
function ai_assistant_default_preferences(): array
{
    return [
        'answer_length' => null,
        'answer_style' => null,
        'custom_instructions' => '',
        'learned_notes' => '',
        'assist_count' => 0,
        'last_preference_prompt_at' => null,
    ];
}

/**
 * @return array<string, mixed>
 */
function ai_assistant_get_user_preferences(PDO $pdo, int $companyId, int $userId): array
{
    $defaults = ai_assistant_default_preferences();
    if ($companyId <= 0 || $userId <= 0) {
        return $defaults;
    }

    $sources = [$pdo];
    if (function_exists('ai_pdo')) {
        $control = ai_pdo();
        if ($control instanceof PDO && $control !== $pdo) {
            $sources[] = $control;
        }
    }

    foreach ($sources as $source) {
        if (!ai_assistant_ensure_preferences_table($source)) {
            continue;
        }
        try {
            $stmt = $source->prepare(
                'SELECT answer_length, answer_style, custom_instructions, learned_notes,
                        assist_count, last_preference_prompt_at
                 FROM ai_user_answer_preferences
                 WHERE company_id = ? AND user_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$companyId, $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return array_merge($defaults, $row);
            }
        } catch (Throwable $e) {
            error_log('ai_assistant_get_user_preferences: ' . $e->getMessage());
        }
    }

    return $defaults;
}

/**
 * @param array<string, mixed> $prefs
 * @return array<string, mixed>
 */
function ai_assistant_save_user_preferences(PDO $pdo, int $companyId, int $userId, array $prefs): array
{
    if ($companyId <= 0 || $userId <= 0 || !ai_assistant_ensure_preferences_table($pdo)) {
        return ai_assistant_default_preferences();
    }

    $current = ai_assistant_get_user_preferences($pdo, $companyId, $userId);
    $answerLength = (string) ($prefs['answer_length'] ?? $current['answer_length'] ?? '');
    $answerStyle = (string) ($prefs['answer_style'] ?? $current['answer_style'] ?? '');
    $custom = trim((string) ($prefs['custom_instructions'] ?? $current['custom_instructions'] ?? ''));
    $learned = trim((string) ($prefs['learned_notes'] ?? $current['learned_notes'] ?? ''));
    $assistCount = (int) ($prefs['assist_count'] ?? $current['assist_count'] ?? 0);
    $lastPrompt = $prefs['last_preference_prompt_at'] ?? $current['last_preference_prompt_at'] ?? null;

    if (!in_array($answerLength, ['brief', 'balanced', 'detailed', ''], true)) {
        $answerLength = '';
    }
    if (!in_array($answerStyle, ['simple', 'professional', 'step_by_step', ''], true)) {
        $answerStyle = '';
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ai_user_answer_preferences
                (company_id, user_id, answer_length, answer_style, custom_instructions, learned_notes, assist_count, last_preference_prompt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                answer_length = VALUES(answer_length),
                answer_style = VALUES(answer_style),
                custom_instructions = VALUES(custom_instructions),
                learned_notes = VALUES(learned_notes),
                assist_count = VALUES(assist_count),
                last_preference_prompt_at = VALUES(last_preference_prompt_at),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $companyId,
            $userId,
            $answerLength !== '' ? $answerLength : null,
            $answerStyle !== '' ? $answerStyle : null,
            $custom !== '' ? $custom : null,
            $learned !== '' ? $learned : null,
            max(0, $assistCount),
            $lastPrompt,
        ]);
    } catch (Throwable $e) {
        error_log('ai_assistant_save_user_preferences: ' . $e->getMessage());
    }

    return ai_assistant_get_user_preferences($pdo, $companyId, $userId);
}

function ai_assistant_increment_assist_count(PDO $pdo, int $companyId, int $userId): void
{
    $prefs = ai_assistant_get_user_preferences($pdo, $companyId, $userId);
    $prefs['assist_count'] = (int) ($prefs['assist_count'] ?? 0) + 1;
    ai_assistant_save_user_preferences($pdo, $companyId, $userId, $prefs);
}

/**
 * @return array{liked:array<int,string>,disliked:array<int,string>}
 */
function ai_assistant_fetch_feedback_insights(PDO $pdo, int $companyId, int $userId, int $limit = 6): array
{
    $liked = [];
    $disliked = [];
    if ($companyId <= 0 || $userId <= 0) {
        return ['liked' => $liked, 'disliked' => $disliked];
    }

    $sources = [$pdo];
    if (function_exists('ai_pdo')) {
        $control = ai_pdo();
        if ($control instanceof PDO && $control !== $pdo) {
            $sources[] = $control;
        }
    }

    foreach ($sources as $source) {
        if (!ai_assistant_ensure_feedback_table($source)) {
            continue;
        }
        try {
            $stmt = $source->prepare(
                'SELECT user_question, rating
                 FROM ai_response_feedback
                 WHERE company_id = ? AND user_id = ?
                 ORDER BY updated_at DESC
                 LIMIT ' . max(1, min(20, $limit * 2))
            );
            $stmt->execute([$companyId, $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $question = trim((string) ($row['user_question'] ?? ''));
                if ($question === '') {
                    continue;
                }
                $snippet = mb_strlen($question) > 80 ? mb_substr($question, 0, 77) . '...' : $question;
                $rating = (int) ($row['rating'] ?? 0);
                if ($rating > 0 && !in_array($snippet, $liked, true)) {
                    $liked[] = $snippet;
                } elseif ($rating < 0 && !in_array($snippet, $disliked, true)) {
                    $disliked[] = $snippet;
                }
                if (count($liked) >= $limit && count($disliked) >= $limit) {
                    break 2;
                }
            }
        } catch (Throwable $e) {
            error_log('ai_assistant_fetch_feedback_insights: ' . $e->getMessage());
        }
    }

    return [
        'liked' => array_slice($liked, 0, $limit),
        'disliked' => array_slice($disliked, 0, $limit),
    ];
}

function ai_assistant_build_personalization_block(PDO $pdo, int $companyId, int $userId): string
{
    $prefs = ai_assistant_get_user_preferences($pdo, $companyId, $userId);
    $insights = ai_assistant_fetch_feedback_insights($pdo, $companyId, $userId);
    $lines = [];

    $lengthMap = [
        'brief' => 'Keep answers very short (1-2 sentences). Give simple definitions unless the user asks for more.',
        'balanced' => 'Use a balanced length (2-4 sentences). Include key figures only when relevant.',
        'detailed' => 'Provide thorough answers with relevant numbers, statuses, and context from the ERP data.',
    ];
    $styleMap = [
        'simple' => 'Use plain, simple language. Avoid jargon unless the user uses it first.',
        'professional' => 'Use professional business language suitable for an ERP workplace.',
        'step_by_step' => 'Prefer numbered steps or clear action lists when explaining processes.',
    ];

    $answerLength = (string) ($prefs['answer_length'] ?? '');
    if ($answerLength !== '' && isset($lengthMap[$answerLength])) {
        $lines[] = '- ' . $lengthMap[$answerLength];
    }
    $answerStyle = (string) ($prefs['answer_style'] ?? '');
    if ($answerStyle !== '' && isset($styleMap[$answerStyle])) {
        $lines[] = '- ' . $styleMap[$answerStyle];
    }

    $custom = trim((string) ($prefs['custom_instructions'] ?? ''));
    if ($custom !== '') {
        $lines[] = '- User custom instructions: ' . $custom;
    }
    $learned = trim((string) ($prefs['learned_notes'] ?? ''));
    if ($learned !== '') {
        $lines[] = '- Learned from feedback: ' . $learned;
    }
    if ($insights['disliked'] !== []) {
        $lines[] = '- The user disliked previous answers to questions like: ' . implode('; ', $insights['disliked']);
    }
    if ($insights['liked'] !== []) {
        $lines[] = '- The user liked previous answers to questions like: ' . implode('; ', $insights['liked']);
    }

    if ($lines === []) {
        return '';
    }

    return "\n\nUser personalization (learned from feedback — follow closely):\n" . implode("\n", $lines);
}

function ai_assistant_has_defined_preferences(array $prefs): bool
{
    return trim((string) ($prefs['answer_length'] ?? '')) !== ''
        || trim((string) ($prefs['answer_style'] ?? '')) !== ''
        || trim((string) ($prefs['custom_instructions'] ?? '')) !== '';
}

function ai_assistant_should_prompt_preferences(array $prefs): bool
{
    $assistCount = (int) ($prefs['assist_count'] ?? 0);
    if ($assistCount < 4) {
        return false;
    }
    if (ai_assistant_has_defined_preferences($prefs)) {
        return false;
    }
    if (empty($prefs['last_preference_prompt_at'])) {
        return $assistCount >= 6;
    }
    return $assistCount > 0 && $assistCount % 12 === 0;
}

function ai_assistant_preference_follow_up_message(string $rating, string $userQuestion = ''): string
{
    if ($rating === 'down') {
        $topic = trim($userQuestion) !== '' ? ' on topics like this' : '';
        return "Thanks for the feedback{$topic}. How would you like me to answer — "
            . "**brief definitions only**, **more detail with your numbers**, or **step-by-step guidance**? "
            . "You can also describe it in your own words and I'll remember it.";
    }

    return "Glad that helped! Would you like me to keep answers this way — short and simple, detailed with figures, or step-by-step? "
        . "Tell me your preference and I'll remember it for next time.";
}

function ai_assistant_thread_has_open_preference_prompt(array $thread): bool
{
    if ($thread === []) {
        return false;
    }
    $last = $thread[count($thread) - 1];
    return is_array($last)
        && ($last['role'] ?? '') === 'assistant'
        && !empty($last['preference_prompt']);
}

function ai_assistant_thread_awaiting_preference_reply(array $thread): bool
{
    $count = count($thread);
    if ($count < 2) {
        return false;
    }
    $prevAssistant = $thread[$count - 2];
    return is_array($prevAssistant)
        && ($prevAssistant['role'] ?? '') === 'assistant'
        && !empty($prevAssistant['preference_prompt']);
}

function ai_assistant_looks_like_preference_only_reply(string $reply): bool
{
    $text = trim($reply);
    if ($text === '') {
        return true;
    }
    if (str_contains($text, '?')) {
        return false;
    }
    if (preg_match('/\b(what|how|why|when|where|show|list|explain|tell me|can you)\b/i', $text)) {
        return false;
    }
    return mb_strlen($text) <= 160;
}

function ai_assistant_preference_ack_message(array $prefs): string
{
    $length = (string) ($prefs['answer_length'] ?? '');
    $style = (string) ($prefs['answer_style'] ?? '');
    $label = 'the way you asked';
    if ($length === 'brief' || $style === 'simple') {
        $label = 'short and simple';
    } elseif ($length === 'detailed') {
        $label = 'detailed with your figures';
    } elseif ($style === 'step_by_step') {
        $label = 'step-by-step';
    } elseif ($length === 'balanced') {
        $label = 'balanced';
    }

    return "Got it — I'll keep my answers {$label}. Ask me anything whenever you're ready.";
}

/**
 * @return array<string, mixed>
 */
function ai_assistant_learn_from_preference_reply(PDO $pdo, int $companyId, int $userId, string $reply): array
{
    $lower = strtolower(trim($reply));
    $updates = ai_assistant_get_user_preferences($pdo, $companyId, $userId);

    if (preg_match('/\b(brief|short|simple|concise|definition|definitions only|one sentence|keep it short)\b/', $lower)) {
        $updates['answer_length'] = 'brief';
        $updates['answer_style'] = 'simple';
    } elseif (preg_match('/\b(detail|detailed|more info|more information|numbers|figures|amounts|with data)\b/', $lower)) {
        $updates['answer_length'] = 'detailed';
    } elseif (preg_match('/\b(balanced|medium|moderate)\b/', $lower)) {
        $updates['answer_length'] = 'balanced';
    }

    if (preg_match('/\b(step|steps|step-by-step|guide|walk me through|how to)\b/', $lower)) {
        $updates['answer_style'] = 'step_by_step';
    } elseif (preg_match('/\b(professional|formal|business)\b/', $lower)) {
        $updates['answer_style'] = 'professional';
    } elseif (preg_match('/\b(plain|simple|easy)\b/', $lower)) {
        $updates['answer_style'] = 'simple';
    }

    if (strlen(trim($reply)) > 8) {
        $existing = trim((string) ($updates['custom_instructions'] ?? ''));
        $updates['custom_instructions'] = $existing !== '' ? $existing . "\n" . trim($reply) : trim($reply);
        $updates['learned_notes'] = 'User said: ' . (mb_strlen($reply) > 120 ? mb_substr(trim($reply), 0, 117) . '...' : trim($reply));
    }

    return ai_assistant_save_user_preferences($pdo, $companyId, $userId, $updates);
}

function ai_assistant_append_preference_note_from_feedback(
    PDO $pdo,
    int $companyId,
    int $userId,
    string $rating,
    string $userQuestion
): void {
    if ($rating !== 'down' || trim($userQuestion) === '') {
        return;
    }

    $prefs = ai_assistant_get_user_preferences($pdo, $companyId, $userId);
    $snippet = mb_strlen($userQuestion) > 90 ? mb_substr($userQuestion, 0, 87) . '...' : $userQuestion;
    $note = 'Disliked verbose answers for: ' . $snippet;
    $existing = trim((string) ($prefs['learned_notes'] ?? ''));
    if ($existing !== '' && str_contains($existing, $snippet)) {
        return;
    }
    $prefs['learned_notes'] = $existing !== '' ? $existing . ' | ' . $note : $note;
    if (trim((string) ($prefs['answer_length'] ?? '')) === '') {
        $prefs['answer_length'] = 'brief';
        $prefs['answer_style'] = 'simple';
    }
    ai_assistant_save_user_preferences($pdo, $companyId, $userId, $prefs);
}

/**
 * Main action router for AI requests.
 */
function ai_assistant_handle_action(PDO $pdo, int $userId, int $companyId, string $role, string $action, array $params = []): array
{
    $builder = new AIAssistantContextBuilder($pdo, $userId, $companyId, $role);
    $scanner = new AIAssistantAnomaliesScanner($pdo);
    $forecaster = new AIAssistantGrowthForecaster($pdo);

    $systemPrompt = "You are a senior, premium ERP advisor assistant named 'Ultimate Intelligence'. "
                  . "Provide clear, direct, and highly professional explanations, business advice, and statistics. "
                  . "Adapt your answer length and style to the user's learned preferences when provided below. "
                  . "If no preferences exist yet, default to concise answers (1-3 short paragraphs). "
                  . "Maintain strict privacy. Do not mention other users' confidential data to low-level employees.";

    switch ($action) {
        case 'predict_growth':
            $forecast = $forecaster->forecastNextMonthSales();
            if (!$forecast['success']) {
                return $forecast;
            }
            $context = "Growth analysis data: " . json_encode($forecast);
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Based on the following historical trend data, provide a professional summary forecasting company growth, explain what the regression slope signifies in 1-2 sentences, and offer 2-3 key strategic suggestions:\n" . $context]
            ];
            $openai = ai_openai_request($messages);
            return [
                'success' => true,
                'forecast' => $forecast,
                'analysis' => $openai['content']
            ];

        case 'scan_errors':
            $anomalies = $scanner->runAllScans();
            $context = "Found anomalies/errors: " . json_encode($anomalies);
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Explain the following system anomalies and security flags in a clear, concise checklist, explain why they present risks to accounting integrity, and outline concrete resolution actions. Keep it direct and brief:\n" . $context]
            ];
            $openai = ai_openai_request($messages);
            return [
                'success' => true,
                'anomalies' => $anomalies,
                'analysis' => $openai['content']
            ];

        case 'explain_kpi':
            $kpi = $params['kpi'] ?? '';
            $value = trim((string) ($params['value'] ?? ''));
            $activeModule = $params['active_module'] ?? ($_GET['module'] ?? null);
            $chatId = (int) ($params['chat_id'] ?? 0);
            $history = ai_assistant_normalize_thread(is_array($params['messages'] ?? null) ? $params['messages'] : []);
            $moduleContext = $builder->buildAllModulesContext($activeModule);
            $moduleKey = is_string($activeModule) && trim($activeModule) !== '' ? trim($activeModule) : 'general';
            $personalization = ai_assistant_build_personalization_block($pdo, $companyId, $userId);
            $preferenceAck = '';
            $awaitingPreferenceReply = $kpi === 'User Question'
                && $value !== ''
                && ai_assistant_thread_awaiting_preference_reply($history);
            $preferenceOnlyReply = false;
            $richReport = null;

            if ($awaitingPreferenceReply) {
                $savedPrefs = ai_assistant_learn_from_preference_reply($pdo, $companyId, $userId, $value);
                $personalization = ai_assistant_build_personalization_block($pdo, $companyId, $userId);
                $preferenceOnlyReply = ai_assistant_looks_like_preference_only_reply($value);
                if ($preferenceOnlyReply) {
                    $preferenceAck = ai_assistant_preference_ack_message($savedPrefs);
                } else {
                    $preferenceAck = "Got it — I'll tailor my answers to your preference. ";
                }
            }

            if ($kpi === 'User Question' && $value !== '' && !$awaitingPreferenceReply) {
                $reportIntent = ai_assistant_detect_report_intent($value);
                if ($reportIntent !== null) {
                    $richReport = ai_assistant_build_report_for_intent($pdo, $userId, $companyId, $role, $reportIntent);
                }
            }

            if ($kpi === 'User Question') {
                $openAiMessages = [
                    ['role' => 'system', 'content' => $systemPrompt . $personalization . "\n\nContext:\n" . $moduleContext],
                ];
                if ($history === []) {
                    $openAiMessages[] = [
                        'role' => 'user',
                        'content' => "The user has asked a direct question: '{$value}'. Answer it directly based on the context data and user preferences.",
                    ];
                } else {
                    foreach ($history as $item) {
                        $openAiMessages[] = ['role' => $item['role'], 'content' => $item['content']];
                    }
                    if ($awaitingPreferenceReply && !$preferenceOnlyReply) {
                        $openAiMessages[0]['content'] .= "\n\nThe user just shared how they want answers formatted. Apply that preference to your reply.";
                    }
                }
            } else {
                $openAiMessages = [
                    ['role' => 'system', 'content' => $systemPrompt . $personalization],
                    ['role' => 'user', 'content' => "Context:\n" . $moduleContext . "\n\nThe user wants an explanation for the KPI card: '{$kpi}' with value '{$value}'. Provide a very brief (1-2 sentences) explanation of what this means based on the context, and optionally list 2-3 short bullet points for next steps ONLY if highly relevant. Keep the overall response short and direct."],
                ];
            }

            if ($preferenceOnlyReply) {
                $analysis = $preferenceAck;
            } elseif ($richReport !== null) {
                $analysis = (string) ($richReport['intro'] ?? 'Here is your summary.');
            } else {
                $openai = ai_openai_request($openAiMessages);
                $analysis = $preferenceAck . $openai['content'];
            }

            $thread = $history;
            if ($kpi === 'User Question' && $value !== '') {
                $last = $thread !== [] ? $thread[count($thread) - 1] : null;
                if ($last === null || $last['role'] !== 'user' || $last['content'] !== $value) {
                    $thread[] = ['role' => 'user', 'content' => $value];
                }
                $assistantEntry = ['role' => 'assistant', 'content' => $analysis];
                if ($richReport !== null) {
                    $assistantEntry['rich'] = $richReport;
                }
                $thread[] = $assistantEntry;

                if (!$preferenceOnlyReply) {
                    ai_assistant_increment_assist_count($pdo, $companyId, $userId);
                }
                $prefs = ai_assistant_get_user_preferences($pdo, $companyId, $userId);
                if (!$preferenceOnlyReply
                    && ai_assistant_should_prompt_preferences($prefs)
                    && !ai_assistant_thread_has_open_preference_prompt($thread)) {
                    $promptText = "Quick question — how do you prefer my answers: **short and simple**, **detailed with your figures**, or **step-by-step**? I'll remember your choice.";
                    $thread[] = [
                        'role' => 'assistant',
                        'content' => $promptText,
                        'preference_prompt' => true,
                    ];
                    $prefs['last_preference_prompt_at'] = date('Y-m-d H:i:s');
                    ai_assistant_save_user_preferences($pdo, $companyId, $userId, $prefs);
                }
            } else {
                $questionText = sprintf('Explain KPI: %s (value: %s)', $kpi, $value);
                $thread = [
                    ['role' => 'user', 'content' => $questionText],
                    ['role' => 'assistant', 'content' => $openai['content']],
                ];
                $chatId = 0;
            }

            $savedChat = ai_assistant_save_chat_session(
                $pdo,
                $companyId,
                $userId,
                $moduleKey,
                $thread,
                $chatId > 0 ? $chatId : null
            );

            return [
                'success' => true,
                'analysis' => $analysis,
                'rich' => $richReport,
                'savedChat' => $savedChat,
            ];
            $module = $params['module'] ?? 'general';
            $moduleContext = "";
            if ($module === 'vouchers') $moduleContext = $builder->buildVouchersContext();
            elseif ($module === 'attendance') $moduleContext = $builder->buildAttendanceContext();
            elseif ($module === 'performance') $moduleContext = $builder->buildPerformanceContext();
            else $moduleContext = $builder->buildAllModulesContext($module);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Context:\n" . $moduleContext . "\n\nProvide a highly concise, direct status report, statistical summary, and 2-3 key optimization suggestions for the '{$module}' module. Keep the entire response short, readable, and structured."]
            ];
            $openai = ai_openai_request($messages);
            return [
                'success' => true,
                'analysis' => $openai['content']
            ];

        case 'smart_report':
            $moduleContext = $builder->buildSmartReportContext();
            $detailedSystemPrompt = "You are a senior, premium ERP advisor assistant named 'Ultimate Intelligence'. "
                                  . "Provide extremely detailed, comprehensive, in-depth, and metric-focused status reports, statistical audits, and operational recommendations for each module. "
                                  . "Make sure to analyze specific transactions, database table status, and detailed figures. "
                                  . "Do not summarize briefly; the user expects a highly thorough, complete business intelligence analysis with specific predictions and strategic optimization rules.";
            $messages = [
                ['role' => 'system', 'content' => $detailedSystemPrompt],
                ['role' => 'user', 'content' => "Context:\n" . $moduleContext . "\n\nAnalyze the detailed records above. Generate an in-depth and thorough Smart Report covering:\n1. 📈 Sales & Revenue (invoice details, trends)\n2. 💼 Financial & Expense Health (expenses list)\n3. 📦 Stock & Inventory (low stock list, valuation)\n4. 👥 Employee Attendance & Performance (lateness patterns, compliance)\n5. 🛠️ System Updates & Integrity Review (migration batches, suggestions, logs)\nEnsure the output is highly detailed, clear, and structured with distinct titles for each section."]
            ];
            $openai = ai_openai_request($messages);
            return [
                'success' => true,
                'analysis' => $openai['content']
            ];

        case 'full_system_report':
            $moduleContext = $builder->buildFullSystemReportContextText();
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Context:\n" . $moduleContext . "\n\nProvide a comprehensive system audit report summary, dynamic predictions, suggestions, and insights for the entire company. Keep the response extremely thorough but structured with clear sections for each domain. Suggest predictions for revenue, debt, and operations."]
            ];
            $openai = ai_openai_request($messages);
            return [
                'success' => true,
                'analysis' => $openai['content']
            ];

        case 'delete_chat':
            $chatId = (int) ($params['chat_id'] ?? 0);
            if ($chatId <= 0) {
                return ['success' => false, 'error' => 'Invalid chat.'];
            }
            $deleted = ai_assistant_delete_chat($pdo, $companyId, $userId, $chatId);
            if (!$deleted) {
                return ['success' => false, 'error' => 'Could not delete chat.'];
            }
            return ['success' => true, 'chat_id' => $chatId];

        case 'message_feedback':
            $chatId = (int) ($params['chat_id'] ?? 0);
            $messageIndex = (int) ($params['message_index'] ?? -1);
            $rating = (string) ($params['rating'] ?? '');
            return ai_assistant_save_message_feedback($pdo, $companyId, $userId, $chatId, $messageIndex, $rating);

        default:
            return ['success' => false, 'error' => 'Invalid action'];
    }
}
