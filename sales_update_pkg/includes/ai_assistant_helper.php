<?php
/**
 * AI Assistant Helper - Core Intelligence Hub for Ultimate ERP.
 * Combines role-based privacy filters, error/anomaly scanners, and growth forecasting.
 */

require_once __DIR__ . '/ai_helpers.php';

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
            } elseif (in_array($moduleLower, ['finance', 'balances', 'expenses', 'petty_cash', 'revenue'], true)) {
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

        // 6. Petty Cash
        $report[] = "[6/22] Petty Cash Funds:";
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
        $lines[] = "--- Recent Expenses & Petty Cash (Last 10) ---";
        try {
            if (tableExists('expenses_requests', $this->pdo)) {
                $sql = "SELECT er.amount, er.description, er.status, er.date, u.full_name 
                        FROM expenses_requests er 
                        LEFT JOIN users u ON er.user_id = u.id 
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
 * Main action router for AI requests.
 */
function ai_assistant_handle_action(PDO $pdo, int $userId, int $companyId, string $role, string $action, array $params = []): array
{
    $builder = new AIAssistantContextBuilder($pdo, $userId, $companyId, $role);
    $scanner = new AIAssistantAnomaliesScanner($pdo);
    $forecaster = new AIAssistantGrowthForecaster($pdo);

    $systemPrompt = "You are a senior, premium ERP advisor assistant named 'Ultimate Intelligence'. "
                  . "Provide clear, concise, direct, and highly professional explanations, business advice, and statistics. "
                  . "Keep all responses extremely short, concise, and direct (max 2-3 short paragraphs or 100-150 words total). Avoid generic next steps, lists of obvious business advice, or boilerplate paragraphs unless specifically asked. "
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
            $value = $params['value'] ?? '';
            $activeModule = $params['active_module'] ?? ($_GET['module'] ?? null);
            $moduleContext = $builder->buildAllModulesContext($activeModule);
            
            if ($kpi === 'User Question') {
                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Context:\n" . $moduleContext . "\n\nThe user has asked a direct question: '{$value}'. Answer it directly and concisely based on the context data. Do not output generic advice unless specifically asked. Keep it brief (1-2 short paragraphs max)."]
                ];
            } else {
                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Context:\n" . $moduleContext . "\n\nThe user wants an explanation for the KPI card: '{$kpi}' with value '{$value}'. Provide a very brief (1-2 sentences) explanation of what this means based on the context, and optionally list 2-3 short bullet points for next steps ONLY if highly relevant. Keep the overall response short and direct."]
                ];
            }
            $openai = ai_openai_request($messages);
            if ($kpi === 'User Question') {
                if (function_exists('ai_log_chat')) {
                    ai_log_chat($companyId, $userId, 'general', $value, $openai['content']);
                }
            }
            return [
                'success' => true,
                'analysis' => $openai['content']
            ];

        case 'module_report':
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
                ['role' => 'user', 'content' => "Context:\n" . $moduleContext . "\n\nAnalyze the detailed records above. Generate an in-depth and thorough Smart Report covering:\n1. 📈 Sales & Revenue (invoice details, trends)\n2. 💼 Financial & Expense Health (expenses list, petty cash)\n3. 📦 Stock & Inventory (low stock list, valuation)\n4. 👥 Employee Attendance & Performance (lateness patterns, compliance)\n5. 🛠️ System Updates & Integrity Review (migration batches, suggestions, logs)\nEnsure the output is highly detailed, clear, and structured with distinct titles for each section."]
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

        default:
            return ['success' => false, 'error' => 'Invalid action'];
    }
}
