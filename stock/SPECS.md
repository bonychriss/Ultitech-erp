# Stock Management System - Project Specifications

## 📁 COMPLETE FILE STRUCTURE
procura_stock/
├── index.php (redirect to login)
├── login.php
├── logout.php
├── dashboard.php
├── config/
│   ├── database.php
│   ├── constants.php
│   └── functions.php
├── assets/
│   ├── css/
│   │   ├── style.css
│   │   ├── dashboard.css
│   │   └── custom.css
│   ├── js/
│   │   ├── main.js
│   │   ├── dashboard.js
│   │   └── shipments.js
│   └── images/
│       └── logos/
├── includes/
│   ├── header.php
│   ├── footer.php
│   ├── sidebar.php
│   ├── navbar.php
│   └── auth.php
├── uploads/
│   ├── products/
│   │   ├── {year}/{month}/{product_id}/
│   │   │   ├── original/
│   │   │   ├── thumbnail/ (150x150)
│   │   │   ├── medium/ (400x400)
│   │   │   └── large/ (800x800)
│   ├── suppliers/
│   └── documents/
├── modules/
│   ├── products/
│   │   ├── index.php (list all products)
│   │   ├── add.php (add new product)
│   │   ├── edit.php (edit product)
│   │   ├── view.php (view details)
│   │   ├── import.php (bulk import)
│   │   └── export.php (export data)
│   ├── purchases/
│   │   ├── index.php (list POs)
│   │   ├── create.php (create PO)
│   │   ├── view.php (view PO)
│   │   ├── approve.php (approve PO)
│   │   └── print.php (print PO PDF)
│   ├── shipments/
│   │   ├── index.php (shipments list)
│   │   ├── create.php (create shipment)
│   │   ├── view.php (view shipment)
│   │   ├── receive.php (receive goods)
│   │   ├── costs.php (enter landed costs)
│   │   ├── track.php (update status)
│   │   └── documents.php (upload docs)
│   ├── suppliers/
│   │   ├── index.php (list suppliers)
│   │   ├── add.php (add supplier)
│   │   └── view.php (view supplier)
│   ├── stock/
│   │   ├── index.php (current stock)
│   │   ├── movements.php (stock movements)
│   │   ├── adjust.php (stock adjustment)
│   │   └── batches.php (batch tracking)
│   ├── catalogue/
│   │   ├── index.php (public catalogue)
│   │   ├── category.php (by category)
│   │   ├── product.php (product detail)
│   │   └── search.php (search products)
│   ├── reports/
│   │   ├── stock.php (stock reports)
│   │   ├── purchases.php (purchase reports)
│   │   ├── shipments.php (shipment reports)
│   │   ├── financial.php (financial reports)
│   │   └── suppliers.php (supplier reports)
│   └── admin/
│       ├── users.php (manage users)
│       ├── settings.php (system settings)
│       └── backup.php (database backup)
└── api/
    ├── get-products.php
    ├── update-status.php
    └── calculate-costs.php

## 🗄️ COMPLETE DATABASE SCHEMA

### Table 1: users
```sql
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('admin', 'procurement', 'warehouse', 'sales', 'viewer') DEFAULT 'viewer',
    department VARCHAR(100),
    phone VARCHAR(20),
    avatar VARCHAR(255),
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Table 2: suppliers
```sql
CREATE TABLE suppliers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    supplier_code VARCHAR(50) UNIQUE NOT NULL,
    company_name VARCHAR(200) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    country VARCHAR(50),
    website VARCHAR(200),
    payment_terms VARCHAR(100),
    rating DECIMAL(3,2) DEFAULT 5.00,
    total_orders INT DEFAULT 0,
    total_value DECIMAL(15,2) DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Table 3: categories
```sql
CREATE TABLE categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE,
    description TEXT,
    parent_id INT DEFAULT NULL,
    image VARCHAR(255),
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
```

### Table 4: products (CRITICAL - with image support)
```sql
CREATE TABLE products (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    short_description TEXT,
    category_id INT,
    supplier_id INT,
    unit_price DECIMAL(15,2) NOT NULL, -- Buying price (auto-updated from landed costs)
    selling_price DECIMAL(15,2), -- For catalogue/sales team
    reorder_level INT DEFAULT 10,
    minimum_order INT DEFAULT 1,
    weight_kg DECIMAL(10,3),
    dimensions VARCHAR(50),
    hs_code VARCHAR(12),
    duty_percentage DECIMAL(5,2),
    main_image VARCHAR(255),
    is_featured BOOLEAN DEFAULT FALSE,
    catalogue_visibility ENUM('visible', 'hidden') DEFAULT 'visible',
    specifications JSON, -- For product specs
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

### Table 5: product_images (multiple images per product)
```sql
CREATE TABLE product_images (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    thumbnail_path VARCHAR(255),
    is_primary BOOLEAN DEFAULT FALSE,
    display_order INT DEFAULT 0,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);
```

### Table 6: purchases (Purchase Orders)
```sql
CREATE TABLE purchases (
    id INT PRIMARY KEY AUTO_INCREMENT,
    purchase_no VARCHAR(50) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    order_date DATE NOT NULL,
    expected_date DATE,
    status ENUM('draft', 'pending', 'approved', 'ordered', 'shipped', 'received', 'partial', 'cancelled') DEFAULT 'draft',
    approved_by INT,
    approved_at TIMESTAMP NULL,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

### Table 7: shipments (Shipment Tracking - YOUR FORMAT)
```sql
CREATE TABLE shipments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shipment_number VARCHAR(50) UNIQUE NOT NULL,
    purchase_id INT,
    supplier_id INT NOT NULL,
    contact_person VARCHAR(100),
    contact_number VARCHAR(50),
    invoice_number VARCHAR(100),
    tracking_number VARCHAR(100) DEFAULT 'NA',
    packages_count INT DEFAULT 1,
    cbm DECIMAL(10,3) DEFAULT 0.000,
    total_value DECIMAL(15,2) DEFAULT 0.00,
    description TEXT,
    shipment_date DATE,
    shipper VARCHAR(100),
    ecc_number VARCHAR(100),
    etd DATE, -- Estimated Time of Departure
    eta DATE, -- Estimated Time of Arrival
    actual_arrival_date DATE,
    status ENUM('pending', 'confirmed', 'in_transit', 'arrived_at_port', 'in_customs', 'delivered', 'delayed', 'cancelled') DEFAULT 'pending',
    
    -- LANDED COST FIELDS (CRITICAL)
    shipping_cost DECIMAL(15,2) DEFAULT 0.00,
    insurance_cost DECIMAL(15,2) DEFAULT 0.00,
    customs_duty DECIMAL(15,2) DEFAULT 0.00,
    customs_brokerage DECIMAL(15,2) DEFAULT 0.00,
    port_charges DECIMAL(15,2) DEFAULT 0.00,
    local_transport DECIMAL(15,2) DEFAULT 0.00,
    other_costs DECIMAL(15,2) DEFAULT 0.00,
    total_additional_costs DECIMAL(15,2) DEFAULT 0.00,
    total_landed_cost DECIMAL(15,2) DEFAULT 0.00,
    cost_calculated_at TIMESTAMP NULL,
    
    -- Your exact column names from data
    hs_code VARCHAR(12),
    duty_percentage DECIMAL(5,2) DEFAULT 0.00,
    shipping_method ENUM('air', 'sea', 'road', 'courier') DEFAULT 'sea',
    currency VARCHAR(3) DEFAULT 'USD',
    exchange_rate DECIMAL(10,4) DEFAULT 1.0000,
    
    notes TEXT,
    received_by INT,
    received_at TIMESTAMP NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (received_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

### Table 8: shipment_items (multiple products per shipment)
```sql
CREATE TABLE shipment_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shipment_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(15,2),
    received_quantity INT DEFAULT 0,
    quality_status ENUM('pending', 'passed', 'failed', 'partial') DEFAULT 'pending',
    notes TEXT,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

### Table 9: shipment_costs (detailed cost breakdown)
```sql
CREATE TABLE shipment_costs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shipment_id INT NOT NULL,
    cost_type ENUM('shipping', 'insurance', 'fuel', 'duty', 'brokerage', 'port', 'transport', 'storage', 'bank', 'other'),
    description VARCHAR(255),
    amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    amount_local DECIMAL(15,2),
    invoice_number VARCHAR(100),
    paid BOOLEAN DEFAULT FALSE,
    paid_date DATE,
    entered_by INT,
    entered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE,
    FOREIGN KEY (entered_by) REFERENCES users(id)
);
```

### Table 10: stock (current stock levels)
```sql
CREATE TABLE stock (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT UNIQUE NOT NULL,
    quantity INT DEFAULT 0,
    reserved_quantity INT DEFAULT 0,
    available_quantity INT GENERATED ALWAYS AS (quantity - reserved_quantity) STORED,
    location VARCHAR(100),
    stock_value DECIMAL(15,2) DEFAULT 0.00,
    last_movement TIMESTAMP NULL,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id)
);
```

### Table 11: product_batches (FIFO tracking)
```sql
CREATE TABLE product_batches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    shipment_id INT,
    batch_number VARCHAR(100) UNIQUE NOT NULL,
    quantity INT NOT NULL,
    current_stock INT NOT NULL,
    unit_cost DECIMAL(15,2) NOT NULL,
    manufacturing_date DATE,
    expiry_date DATE,
    received_date DATE NOT NULL,
    location VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (shipment_id) REFERENCES shipments(id)
);
```

### Table 12: stock_movements (audit trail)
```sql
CREATE TABLE stock_movements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    movement_type ENUM('in', 'out', 'adjustment', 'return') NOT NULL,
    quantity INT NOT NULL,
    reference_type ENUM('purchase', 'shipment', 'sale', 'adjustment') NOT NULL,
    reference_id INT,
    previous_quantity INT,
    new_quantity INT,
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

### Table 13: product_landed_costs (cost allocation)
```sql
CREATE TABLE product_landed_costs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    shipment_id INT NOT NULL,
    quantity INT NOT NULL,
    product_unit_cost DECIMAL(15,2),
    shipping_cost_per_unit DECIMAL(15,2),
    duty_cost_per_unit DECIMAL(15,2),
    clearance_cost_per_unit DECIMAL(15,2),
    transport_cost_per_unit DECIMAL(15,2),
    other_cost_per_unit DECIMAL(15,2),
    total_landed_cost DECIMAL(15,2),
    landed_cost_per_unit DECIMAL(15,2),
    product_cost_updated BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (shipment_id) REFERENCES shipments(id)
);
```

### Table 14: hs_codes (duty calculation)
```sql
CREATE TABLE hs_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    hs_code VARCHAR(12) UNIQUE NOT NULL,
    description TEXT,
    duty_percentage DECIMAL(5,2),
    vat_percentage DECIMAL(5,2),
    special_notes TEXT,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Table 15: alerts (system notifications)
```sql
CREATE TABLE alerts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    alert_type ENUM('low_stock', 'shipment_delayed', 'goods_arrived', 'po_approval', 'cost_variance', 'expiry'),
    title VARCHAR(200),
    message TEXT,
    reference_id INT,
    reference_type VARCHAR(50),
    is_read BOOLEAN DEFAULT FALSE,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## 🎨 COMPLETE USER INTERFACE REQUIREMENTS

### 1. LOGIN PAGE (login.php)
REQUIRED ELEMENTS:
- Company logo
- Username field
- Password field
- Remember me checkbox
- Login button
- "Forgot password" link
- Demo credentials note
- Responsive design

### 2. DASHBOARD (dashboard.php) - MAIN PAGE
TOP ROW - SUMMARY CARDS (6 cards):
1. Total Products (with icon, count, link)
2. Low Stock Items (red badge if >0, clickable)
3. Pending Purchases (yellow badge, clickable)
4. Active Suppliers (with icon)
5. In Transit Shipments (with ETA countdown)
6. Total Stock Value (formatted currency)

SECOND ROW - CHARTS:
1. Monthly Purchase Trends (Line chart)
2. Stock Distribution by Category (Pie chart)

THIRD ROW - TABLES:
1. Recent Purchases (last 10, with status badges)
2. Low Stock Alerts (top 5 urgent items)

FOURTH ROW - WIDGETS:
1. Upcoming Shipments (next 7 days)
2. Quick Actions (buttons: New PO, New Shipment, etc.)

### 3. PRODUCTS MODULE (COMPLETE)
PAGE: modules/products/index.php
- Search bar (by name, code, category)
- Filter by: Category, Stock Status, Supplier
- Add New Product button (top right)
- Table with columns: Code, Name, Category, Stock, Cost, Status, Actions
- Action buttons: View, Edit, Delete, Add Images
- Pagination (50 per page)
- Export to Excel/PDF buttons

MODAL: Add/Edit Product
- Tabs: Basic Info, Images, Specifications, Pricing
- Image upload: Drag & drop, multiple files, preview
- Auto-generate product code: PROD-YYYY-MM-001
- Reorder level field with default
- Supplier dropdown with search
- Category tree selector
- Save & Add Another button

### 4. PURCHASE ORDERS MODULE (COMPLETE)
PAGE: modules/purchases/index.php
- Filter by: Status, Supplier, Date Range
- Status badges with colors
- Create New PO button
- Table: PO#, Supplier, Product, Qty, Amount, Status, Date, Actions
- Action buttons: View, Edit, Approve, Create Shipment, Print

PAGE: modules/purchases/create.php
- Step 1: Select Supplier (with last order info)
- Step 2: Add Products (search by name/code)
- Step 3: Review & Confirm
- Auto-calculate total
- Expected delivery date picker
- Notes field
- Save as Draft / Submit for Approval buttons

MODAL: Approve Purchase Order
- Shows PO details
- Comparison with last purchase price
- Budget check
- Approve/Reject buttons with comments

### 5. SHIPMENTS MODULE (COMPLETE - YOUR EXACT FORMAT)
PAGE: modules/shipments/index.php
TABLE COLUMNS (EXACTLY AS YOU SPECIFIED):
1. Supplier (with link)
2. Contact (click to call)
3. Invoice Number (searchable)
4. Track (tracking number with carrier link)
5. Pkgs (packages count)
6. CBM (cubic meters)
7. Value (formatted)
8. Desc (description with product link)
9. Shipment Date
10. Shipper
11. ECC
12. ETD
13. ETA (with countdown/overdue indicator)
14. Status (color-coded badges)
15. Actions (View, Update, Receive, Costs)

STATUS BADGE COLORS:
- Pending: Gray
- Confirmed: Blue
- In Transit: Yellow
- Arrived at Port: Light Green
- In Customs: Orange
- Delivered: Green
- Delayed: Red
- Cancelled: Dark Gray

### 6. RECEIVE GOODS PAGE (modules/shipments/receive.php?id=X)
REQUIRED ELEMENTS:
1. Shipment header: Number, Supplier, Invoice
2. Expected items table
3. Quality check options per item
4. Actual received quantity fields
5. Damage/shortage notes
6. Storage location selector
7. "Confirm Receipt and Update Stock" BUTTON (BIG, GREEN)

WHAT HAPPENS WHEN BUTTON CLICKED (ALL OF THESE):
1. Update shipment status to "received"
2. Update stock quantities for each product
3. Create batch records with batch numbers
4. Record stock movements
5. Update purchase order status
6. Clear low stock alerts
7. Generate Goods Received Note (GRN)
8. Send email notifications
9. Redirect to success page with summary

### 7. LANDED COSTS PAGE (modules/shipments/costs.php?id=X)
REQUIRED ELEMENTS:
1. Cost breakdown sections:
   - Product Costs (auto from invoice)
   - Shipping Costs (freight, insurance, fuel)
   - Customs Costs (duty auto-calculated, brokerage)
   - Local Costs (transport, handling)
   - Other Costs

2. Auto-calculation features:
   - Duty auto-calc from HS code
   - Shipping estimate from CBM
   - Currency conversion

3. Allocation method selector:
   - By Product Value (default)
   - By Weight
   - By Volume
   - Manual

4. Summary section showing:
   - Total landed cost
   - Cost increase percentage
   - Per unit cost
   - Comparison with old cost

5. CRITICAL BUTTON: "Save Landed Costs and Update Product Prices"
   - Updates product.unit_price in database
   - Updates stock.stock_value
   - Updates future PO price suggestions

### 8. PRODUCT CATALOGUE (modules/catalogue/)
PUBLIC PAGES:
1. Catalogue Home (grid/list view toggle)
2. Category Pages (with breadcrumbs)
3. Product Detail Page (image gallery, specs, stock status)
4. Search Results

PRICE DISPLAY RULES:
- Admin: See cost + selling price + margin
- Procurement: See cost price only
- Sales: See selling price only
- Warehouse: See no prices
- Public: See selling price only

IMAGE GALLERY:
- Multiple images per product
- Lightbox viewer
- Thumbnail navigation
- Zoom capability

### 9. REPORTS MODULE (modules/reports/)
REQUIRED REPORTS:
1. Stock Reports:
   - Current Stock Levels
   - Stock Valuation
   - Stock Movement History
   - Low Stock Report
   - Expiry Report

2. Procurement Reports:
   - Purchase Order Status
   - Supplier Performance (on-time delivery, quality)
   - Lead Time Analysis
   - Procurement Cost Analysis

3. Financial Reports:
   - Landed Cost Analysis
   - Stock Value Report
   - Cost Component Breakdown
   - Profit Margin Analysis

4. Export Options for ALL reports:
   - PDF
   - Excel
   - CSV
   - Print

## ⚙️ COMPLETE AUTOMATION RULES

### 1. Auto Status Updates (Daily Cron Job)
```php
// Run at 8:00 AM daily
1. Check shipments where ETA < today AND status != 'delivered'
   → Update status to 'delayed'
   → Send email alert

2. Check shipments where ETD < today AND status = 'confirmed'
   → Update status to 'in_transit'

3. Check stock levels vs reorder levels
   → Create low stock alerts

4. Check batch expiry dates (30 days warning)
   → Create expiry alerts
```

### 2. Auto Cost Calculations
```php
// When saving landed costs:
1. Calculate total additional costs
2. Allocate to products based on selected method
3. Calculate per-unit landed cost
4. Update product.unit_price if checkbox checked
5. Recalculate stock.stock_value
6. Log cost update in audit trail
```

### 3. Auto Stock Updates
```php
// When clicking "Confirm Receipt":
1. Update stock.quantity = stock.quantity + received_qty
2. Create product_batches record
3. Create stock_movements record
4. Update purchase.status = 'received'
5. Update shipment.status = 'received'
6. Clear related low_stock alerts
7. Generate GRN document
8. Send notifications
```

### 4. Auto Price Suggestions
```php
// When creating new PO for existing product:
1. Show last landed cost per unit
2. Show average of last 3 purchases
3. Show supplier's last price
4. Allow user to override
```

## 🔐 COMPLETE SECURITY FEATURES
1. Authentication & Sessions
   - Password hashing with bcrypt
   - Session timeout (30 minutes)
   - Concurrent session prevention
   - Login attempt limiting
   - Remember me tokens

2. SQL Injection Protection
   - PDO prepared statements for ALL queries
   - Input validation and sanitization
   - Parameterized queries only

3. File Upload Security
   - File type validation (MIME check)
   - File size limits (5MB max)
   - Virus scanning (if available)
   - Secure file naming (no original names)
   - Storage outside web root

4. XSS Protection
   - Output escaping for all dynamic content
   - Content Security Policy headers
   - Input sanitization

5. CSRF Protection
   - Tokens on all forms
   - Same-origin policy
   - Referrer checking

6. Access Control
   - Role-based permissions
   - Page-level authorization
   - Function-level restrictions
   - Audit logging of all sensitive actions

## 📱 MOBILE RESPONSIVENESS REQUIREMENTS
Breakpoints:
- Desktop: ≥992px (full features)
- Tablet: 768px-991px (condensed views)
- Mobile: <768px (card-based, single column)

Mobile-Specific Features:
- Touch-friendly buttons (min 44px)
- Swipe gestures for image galleries
- Mobile-optimized tables (horizontal scroll)
- Simplified forms on small screens
- Quick action buttons for warehouse staff

## 🔔 COMPLETE NOTIFICATION SYSTEM
Email Notifications:
- New PO requires approval
- Shipment delayed
- Goods arrived at warehouse
- Low stock alerts
- Cost variance alerts (>50% increase)
- Monthly reports

Dashboard Notifications:
- Bell icon with count
- Color-coded by priority
- Click to mark as read
- Filter by type

SMS Notifications (Optional):
- Critical alerts only
- Shipment delays
- Stock emergencies

## 📄 COMPLETE DOCUMENT GENERATION
1. Purchase Order PDF
   - Company letterhead
   - PO number and date
   - Supplier details
   - Item table with quantities/prices
   - Terms and conditions
   - Signature lines

2. Goods Received Note (GRN)
   - GRN number
   - Shipment reference
   - Received items
   - Quality status
   - Received by signature

3. Stock Reports
   - Current stock listing
   - Valuation summary
   - Movement history

4. Financial Reports
   - Landed cost breakdown
   - Profit margin analysis
   - Supplier cost comparison

## 🚀 DEPLOYMENT REQUIREMENTS
Server Requirements:
- PHP 8.1+ with extensions: PDO, GD, MBString, OpenSSL
- MySQL 8.0+ or MariaDB 10.3+
- Apache 2.4+ with mod_rewrite
- 100MB disk space minimum
- SSL certificate (HTTPS required)

Installation Process:
- Upload files to server
- Create database
- Run installer (install.php)
- Configure settings
- Create admin user
- Import sample data (optional)

Sample Data Included:
- 5 sample users (admin, procurement, warehouse, sales, viewer)
- 10 product categories
- 15 suppliers
- 50 sample products with images
- 30 purchase orders with various statuses
- 20 shipments with your exact data format

## 🎯 KEY FEATURES THAT MUST NOT BE MISSED
CRITICAL FEATURE 1: Landed Cost Calculation
- Must update product.unit_price automatically
- Must show cost breakdown visually
- Must affect future price suggestions
- Must recalculate stock values

CRITICAL FEATURE 2: One-Click Receiving
- Single button that updates everything
- Stock updates immediately
- Batch creation automatic
- All linked records updated

CRITICAL FEATURE 3: Shipment Tracking (Your Format)
- Exact table columns as you specified
- Color-coded status badges
- ETD/ETA tracking with alerts
- Delay detection automatic

CRITICAL FEATURE 4: Product Images
- Multiple images per product
- Drag & drop upload
- Thumbnail generation
- Gallery view

CRITICAL FEATURE 5: Mobile Responsive
- Works on tablets in warehouse
- Touch-friendly interface
- Barcode scanning ready

CRITICAL FEATURE 6: Role-Based Views
- Different prices visible to different roles
- Different actions available per role
- Dashboard customized per role

## 📋 FINAL DELIVERABLES
1. Complete Source Code
   - All PHP files with comments
   - All database SQL files
   - All asset files (CSS, JS, images)

2. Documentation
   - Installation guide
   - User manual
   - Admin guide
   - API documentation (if applicable)

3. Sample Data
   - Your exact shipment data pre-loaded
   - Sample products with images
   - Test users with different roles

4. Testing Suite
   - Unit tests for critical functions
   - Integration test scenarios
   - User acceptance test checklist

5. Support Files
   - Backup scripts
   - Import/export templates
   - Report templates
   - Email templates

## 🎯 SUCCESS CRITERIA
System must:
- Handle your exact shipment data format
- Calculate landed costs accurately
- Update stock with one click
- Show different prices to different roles
- Work on mobile devices in warehouse
- Generate all required reports
- Send appropriate notifications
- Maintain data integrity and security
- Perform well with 10,000+ products
- Be easy for non-technical users
