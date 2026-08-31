<?php
require_once '../includes/functions.php';
requireLogin();

$userRole = $_SESSION['role'] ?? 'employee';
$userDept = $_SESSION['department'] ?? '';
if ($userRole !== 'procurement' && $userRole !== 'admin' && strtolower($userDept) !== 'procurement') {
    die("Access Denied");
}

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM order_tracking WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    die("Record not found");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Shipment - <?= COMPANY_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .form-container {
            max-width: 800px;
            margin: 40px auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .btn-submit {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-cancel {
            background-color: #6c757d;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
        }

        .row {
            display: flex;
            gap: 15px;
        }

        .col {
            flex: 1;
        }
    </style>
</head>

<body>
    <div class="form-container">
        <h2>Edit Shipment</h2>
        <form action="save.php" method="POST">
            <input type="hidden" name="id" value="<?= $row['id'] ?>">

            <div class="row">
                <div class="col form-group">
                    <label>Supplier Name *</label>
                    <input type="text" name="client" value="<?= htmlspecialchars($row['client']) ?>" required>
                </div>
                <div class="col form-group">
                    <label>Contact Number</label>
                    <input type="text" name="mobile" value="<?= htmlspecialchars($row['mobile']) ?>">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Invoice Number *</label>
                    <input type="text" name="shipment_no" value="<?= htmlspecialchars($row['shipment_no']) ?>" required>
                </div>
                <div class="col form-group">
                    <label>Shipment Status</label>
                    <select name="shipment_status">
                        <option value="Pending" <?= $row['shipment_status'] == 'Pending' ? 'selected' : '' ?>>Pending
                        </option>
                        <option value="LOADED" <?= $row['shipment_status'] == 'LOADED' ? 'selected' : '' ?>>LOADED</option>
                        <option value="SHIPPED" <?= $row['shipment_status'] == 'SHIPPED' ? 'selected' : '' ?>>SHIPPED
                        </option>
                        <option value="ARRIVED" <?= $row['shipment_status'] == 'ARRIVED' ? 'selected' : '' ?>>ARRIVED
                        </option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Tracking Status</label>
                    <input type="text" name="tracking_status" value="<?= htmlspecialchars($row['tracking_status']) ?>">
                </div>
                <div class="col form-group">
                    <label>Packages</label>
                    <input type="number" name="packages" value="<?= htmlspecialchars($row['packages']) ?>">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>CBM</label>
                    <input type="number" step="0.001" name="cbm" value="<?= htmlspecialchars($row['cbm']) ?>">
                </div>
                <div class="col form-group">
                    <label>Total Value</label>
                    <input type="number" step="0.01" name="total_value"
                        value="<?= htmlspecialchars($row['total_value']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3"><?= htmlspecialchars($row['description']) ?></textarea>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>Shipment Date</label>
                    <input type="date" name="shipment_date" value="<?= $row['shipment_date'] ?>">
                </div>
                <div class="col form-group">
                    <label>Shipper</label>
                    <input type="text" name="shipper" value="<?= htmlspecialchars($row['shipper'] ?? '') ?>">
                </div>
                <div class="col form-group">
                    <label>ECC</label>
                    <input type="number" step="0.01" name="ecc" value="<?= htmlspecialchars($row['ecc'] ?? 0.00) ?>">
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>ETD</label>
                    <input type="date" name="etd" value="<?= $row['etd'] ?>">
                </div>
                <div class="col form-group">
                    <label>ETA</label>
                    <input type="date" name="eta" value="<?= $row['eta'] ?>">
                </div>
            </div>

            <!-- Smart Pricing / Deal Simulator Integration -->
            <div style="margin-top:20px; padding-bottom:10px; border-bottom:1px solid #eee; font-weight:600; color:#7e22ce;">
                Smart Pricing - Deal Simulator
            </div>
            
            <div style="background: #fdfaff; border: 1px solid #e9d5ff; padding: 20px; border-radius: 8px; margin-top:15px; margin-bottom: 25px;">
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
                
                <div id="simulatorWidget" style="<?= $row['landed_cost'] > 0 ? '' : 'display:none;' ?>">
                    <div class="row">
                        <div class="col form-group">
                            <label>Product Landed Cost</label>
                            <input type="number" id="landedCostView" value="<?= $row['landed_cost'] ?>" readonly style="background:#f3f4f6;">
                        </div>
                        <div class="col form-group">
                            <label>Suggested Floor (Min Sell)</label>
                            <input type="number" id="minSellView" value="<?= $row['min_selling_price'] ?>" readonly style="background:#f3f4f6;">
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
            <input type="hidden" name="buying_price" id="h_buying_price" value="<?= $row['buying_price'] ?>">
            <input type="hidden" name="landed_cost" id="h_landed_cost" value="<?= $row['landed_cost'] ?>">
            <input type="hidden" name="min_selling_price" id="h_min_selling_price" value="<?= $row['min_selling_price'] ?>">
            <input type="hidden" name="margin_percent" id="h_margin_percent" value="<?= $row['margin_percent'] ?>">

            <div style="margin-top: 20px;">
                <a href="index.php" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-submit" id="btnSave">Update Shipment</button>
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

        let currentProduct = <?= $row['landed_cost'] > 0 ? json_encode($row) : 'null' ?>;

        if (currentProduct) {
            updateSimulator();
        }

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
            if (marginPercent < 10 || (unitPrice > 0 && unitPrice < minSelling)) {
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
