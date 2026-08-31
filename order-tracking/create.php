<?php
require_once '../includes/functions.php';
requireLogin();

$userRole = $_SESSION['role'] ?? 'employee';
$userDept = $_SESSION['department'] ?? '';
if ($userRole !== 'procurement' && $userRole !== 'admin' && strtolower($userDept) !== 'procurement') {
    die("Access Denied");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Shipment - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
        }

        .main-header {
            background: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .company-logo {
            height: 40px;
            width: auto;
        }

        .page-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
            margin: 0;
            border-left: 1px solid #ddd;
            padding-left: 15px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .form-container {
            max-width: 900px;
            margin: 0 auto 40px;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid #eef0f3;
        }

        .form-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
            font-size: 0.9rem;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 0.95rem;
            transition: border-color 0.15s ease-in-out;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, .25);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.2s;
            border: none;
        }

        .btn-submit {
            background-color: #007bff;
            color: white;
        }

        .btn-submit:hover {
            background-color: #0056b3;
        }

        .btn-cancel {
            background-color: #6c757d;
            color: white;
        }

        .btn-cancel:hover {
            background-color: #5a6268;
        }

        .btn-outline {
            background-color: transparent;
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 6px 12px;
            font-size: 0.9rem;
        }

        .btn-outline:hover {
            background-color: #6c757d;
            color: white;
        }

        .row {
            display: flex;
            gap: 20px;
        }

        .col {
            flex: 1;
        }

        .form-actions {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
    </style>
</head>

<body>
    <header class="main-header">
        <div class="header-left">
            <img src="../assets/images/Untitled.jpg" alt="<?= COMPANY_NAME ?>" class="company-logo">
            <h1 class="page-title">Order Tracking System</h1>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn btn-outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Back to List
            </a>
        </div>
    </header>

    <div class="form-container">
        <div class="form-section-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                style="vertical-align: text-bottom; margin-right: 5px;">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="16"></line>
                <line x1="8" y1="12" x2="16" y2="12"></line>
            </svg>
            Add New Shipment
        </div>

        <form action="save.php" method="POST">
            <div class="row">
                <div class="col form-group">
                    <label>Supplier Name <span style="color:red">*</span></label>
                    <input type="text" name="client" required placeholder="Enter supplier name">
                </div>
                <div class="col form-group">
                    <label>Contact Number</label>
                    <input type="text" name="mobile" placeholder="Enter contact number">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Invoice Number <span style="color:red">*</span></label>
                    <input type="text" name="shipment_no" required placeholder="e.g. SHP-2025-001">
                </div>
                <div class="col form-group">
                    <label>Shipment Status</label>
                    <select name="shipment_status">
                        <option value="Pending">Pending</option>
                        <option value="LOADED">LOADED</option>
                        <option value="SHIPPED">SHIPPED</option>
                        <option value="ARRIVED">ARRIVED</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Tracking Status</label>
                    <input type="text" name="tracking_status" value="NA" placeholder="Current location or status">
                </div>
                <div class="col form-group">
                    <label>No. of Packages</label>
                    <input type="number" name="packages" value="0" min="0">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>CBM (Volume)</label>
                    <input type="number" step="0.001" name="cbm" value="0.000" min="0">
                </div>
                <div class="col form-group">
                    <label>Total Value</label>
                    <input type="number" step="0.01" name="total_value" value="0.00" min="0">
                </div>
            </div>

            <div class="form-group">
                <label>Description / Goods Details</label>
                <textarea name="description" rows="3" placeholder="Enter details about the goods..."></textarea>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Shipment Date</label>
                    <input type="date" name="shipment_date">
                </div>
                <div class="col form-group">
                    <label>Shipper</label>
                    <input type="text" name="shipper" placeholder="Enter shipper name">
                </div>
                <div class="col form-group">
                    <label>ECC (Est. Cost of Clearance)</label>
                    <input type="number" step="0.01" name="ecc" placeholder="0.00">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>ETD (Estimated Departure)</label>
                    <input type="date" name="etd">
                </div>
                <div class="col form-group">
                    <label>ETA (Estimated Arrival)</label>
                    <input type="date" name="eta">
                </div>
            </div>

            <!-- Smart Pricing / Deal Simulator Integration -->
            <div class="form-section-title" style="margin-top: 20px; color: #7e22ce;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom; margin-right: 5px;"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                Smart Pricing - Deal Simulator
            </div>
            
            <div style="background: #fdfaff; border: 1px solid #e9d5ff; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
                <div class="row">
                    <div class="col form-group">
                        <label>Link to Catalog Product (Optional)</label>
                        <input type="text" id="productLookup" list="productList" placeholder="Search product for landed cost info...">
                        <datalist id="productList">
                            <?php 
                            $products = $pdo->query("SELECT id, name, sku FROM stocks_items ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($products as $p): 
                            ?>
                                <option data-id="<?= $p['id'] ?>" value="<?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>)"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                
                <div id="simulatorWidget" style="display:none;">
                    <div class="row">
                        <div class="col form-group">
                            <label>Product Landed Cost</label>
                            <input type="number" id="landedCostView" readonly style="background:#f3f4f6;">
                        </div>
                        <div class="col form-group">
                            <label>Suggested Floor (Min Sell)</label>
                            <input type="number" id="minSellView" readonly style="background:#f3f4f6;">
                        </div>
                        <div class="col form-group">
                            <label>Margin Bar</label>
                            <div style="height: 38px; background: #eee; border-radius: 19px; overflow: hidden; position: relative; border: 1px solid #ddd;">
                                <div id="marginBar" style="height: 100%; width: 0%; transition: width 0.3s, background-color 0.3s; background: #ef4444;"></div>
                                <span id="marginLabel" style="position: absolute; top:50%; left:50%; transform:translate(-50%, -50%); font-weight: bold; font-size: 0.75rem; color: #000;">0%</span>
                            </div>
                        </div>
                    </div>
                    <div id="pricingFeedback" style="font-weight: bold; text-align: right; margin-top: -10px; margin-bottom: 10px;"></div>
                </div>
                
                <?php if ($userRole === 'admin'): ?>
                <div id="adminOverrideBox" style="display:none; padding: 10px; border: 1px dashed #ef4444; color: #ef4444; border-radius: 4px;">
                    <label style="margin:0; cursor:pointer;"><input type="checkbox" id="chkOverride" name="admin_override" style="width: auto; margin-right: 8px;"> Admin Override: Allow Low Margin</label>
                </div>
                <?php endif; ?>
            </div>

            <!-- Hidden Pricing Fields -->
            <input type="hidden" name="buying_price" id="h_buying_price" value="0.00">
            <input type="hidden" name="landed_cost" id="h_landed_cost" value="0.00">
            <input type="hidden" name="min_selling_price" id="h_min_selling_price" value="0.00">
            <input type="hidden" name="margin_percent" id="h_margin_percent" value="0.00">

            <div class="form-actions">
                <a href="index.php" class="btn btn-cancel">Cancel</a>
                <button type="submit" class="btn btn-submit" id="btnSave">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    Save Shipment
                </button>
            </div>
        </form>
    </div>

    <script>
        const productLookup = document.getElementById('productLookup');
        const productList = document.getElementById('productList');
        const totalValueInput = document.querySelector('input[name="total_value"]');
        const packagesInput = document.querySelector('input[name="packages"]');
        
        const widget = document.getElementById('simulatorWidget');
        const marginBar = document.getElementById('marginBar');
        const marginLabel = document.getElementById('marginLabel');
        const feedback = document.getElementById('pricingFeedback');
        const btnSave = document.getElementById('btnSave');
        const chkOverride = document.getElementById('chkOverride');

        let currentProduct = null;

        productLookup.addEventListener('input', function() {
            const val = this.value;
            const opts = productList.options;
            for (let i = 0; i < opts.length; i++) {
                if (opts[i].value === val) {
                    const id = opts[i].getAttribute('data-id');
                    fetchProduct(id);
                    return;
                }
            }
        });

        function fetchProduct(id) {
            fetch('../smart_pricing/api_get_product.php?id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (data.error) return;
                    currentProduct = data;
                    document.getElementById('landedCostView').value = data.landed_cost;
                    document.getElementById('minSellView').value = data.min_selling_price;
                    
                    document.getElementById('h_buying_price').value = data.buying_price;
                    document.getElementById('h_landed_cost').value = data.landed_cost;
                    document.getElementById('h_min_selling_price').value = data.min_selling_price;
                    
                    widget.style.display = 'block';
                    updateSimulator();
                });
        }

        [totalValueInput, packagesInput].forEach(el => el.addEventListener('input', updateSimulator));
        if (chkOverride) chkOverride.addEventListener('change', updateSimulator);

        function updateSimulator() {
            if (!currentProduct) return;

            const totalValue = parseFloat(totalValueInput.value) || 0;
            const pkgs = parseFloat(packagesInput.value) || 1;
            const unitPrice = totalValue / (pkgs > 0 ? pkgs : 1);
            const landedCost = parseFloat(currentProduct.landed_cost) || 0;
            const minSelling = parseFloat(currentProduct.min_selling_price) || landedCost;

            let marginPercent = 0;
            if (unitPrice > 0) {
                marginPercent = ((unitPrice - landedCost) / unitPrice) * 100;
            }

            document.getElementById('h_margin_percent').value = marginPercent.toFixed(2);
            marginLabel.textContent = marginPercent.toFixed(1) + '%';
            
            let barWidth = marginPercent * 2;
            if (barWidth < 0) barWidth = 0;
            if (barWidth > 100) barWidth = 100;
            marginBar.style.width = barWidth + '%';

            let isBlocked = false;
            if (marginPercent < 10 || unitPrice < minSelling) {
                marginBar.style.backgroundColor = '#ef4444';
                feedback.textContent = "STOP! Low Margin";
                feedback.style.color = '#ef4444';
                isBlocked = true;
                if (document.getElementById('adminOverrideBox')) document.getElementById('adminOverrideBox').style.display = 'block';
            } else if (marginPercent < 25) {
                marginBar.style.backgroundColor = '#f59e0b';
                feedback.textContent = "Warning: Low Profit";
                feedback.style.color = '#f59e0b';
                if (document.getElementById('adminOverrideBox')) document.getElementById('adminOverrideBox').style.display = 'none';
            } else {
                marginBar.style.backgroundColor = '#10b981';
                feedback.textContent = "Great Deal!";
                feedback.style.color = '#10b981';
                if (document.getElementById('adminOverrideBox')) document.getElementById('adminOverrideBox').style.display = 'none';
            }

            if (isBlocked && chkOverride && chkOverride.checked) {
                isBlocked = false;
                feedback.textContent += " (Overridden)";
            }

            btnSave.disabled = isBlocked;
            btnSave.style.opacity = isBlocked ? '0.5' : '1';
            btnSave.style.cursor = isBlocked ? 'not-allowed' : 'pointer';
        }
    </script>
</body>

</html>
