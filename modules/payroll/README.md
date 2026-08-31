# Payroll Module - Staff ERP

A comprehensive payroll management system built for the Staff ERP, allowing for salary configuration, monthly payroll generation, and payslip management.

## 📂 Layout & Structure

The module is located in `modules/payroll/` and follows a self-contained structure:

- `index.php`: Module dashboard showing quick stats and recent payroll runs.
- `salaries.php`: Management interface for employee basic salaries and allowances.
- `run_payroll.php`: Wizard for generating monthly payroll drafts.
- `view_run.php`: Detailed view of a specific payroll run with employee breakdown.
- `settings.php`: Global configuration for tax rates, NSSF, and pay dates.
- `payslip.php`: Individual payslip view with print and PDF download capabilities.
- `payslip_batch.php`: Consolidated view for printing all payslips from a run.
- `export_run.php`: CSV export utility for payroll data.

## 🚀 Key Functionality

### 1. Salary Management
- Set **Basic Salary**, **House Allowance**, and **Transport Allowance** per employee.
- Store banking details (Bank Name, Account Number).
- Track statutory IDs (TIN and NSSF numbers).

### 2. Automated Payroll Generation
- Generate payroll for any month/year.
- Auto-calculates **Gross Salary**, **Net Salary**, and statutory deductions.
- Prevents duplicate runs for the same period.

### 3. Reporting & Exports
- **CSV Export**: Download full payroll spreadsheets for accounting/bank uploads.
- **PDF Payslips**: High-quality, client-side PDF generation for individual employees using `html2pdf.js`.
- **Batch Printing**: Print-friendly layouts for handling the entire staff list at once.

### 4. Anonymous Access (Demo Mode)
- The module is configured to allow access without system login (`ALLOW_ANONYMOUS_PAYROLL` constant).
- Automatically initializes a "System Admin (Demo)" session when accessed anonymously to maintain sidebar and header functionality.

## 🗄️ Database Schema

The module relies on the following tables:
- `payroll_settings`: Stores global config keys and values.
- `employee_salary`: Maps users to their fixed salary structures.
- `payroll_runs`: Logs each monthly generation event and total payouts.
- `payslips`: Stores the snapshot of an employee's pay at the time of the run.

## 🛠️ Integration
- **Sidebar**: Integrated via `sidebar.php` with auto-highlighting for the `/modules/payroll/` path.
- **App Launcher**: Shortcut added to `select-module.php` for direct access.
- **Path Resolution**: Uses `app_url()` in `functions.php` for robust redirection regardless of directory depth.
