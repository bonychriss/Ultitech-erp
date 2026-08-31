<?php
/**
 * Fleet & Parts: Spare parts and vehicles in stock.
 * Shows two sections: Spare Parts | Vehicles (trucks/cars).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
requireLogin();

$active_module = 'stocks';
$page_title = 'Fleet & Parts';
include __DIR__ . '/../../includes/header.php';

// Detect if item_type column exists
$hasItemType = false;
try {
    $pdo->query("SELECT item_type FROM products LIMIT 1");
    $hasItemType = true;
} catch (PDOException $e) {
    // column doesn't exist, use category-based filter
}

// Spare parts in stock
if ($hasItemType) {
    $sqlParts = "SELECT p.id, p.product_code, p.name, p.brand, p.compatibility, p.part_condition, p.unit_price, p.buying_price, p.currency, p.reorder_level,
                 c.name AS category_name, s.quantity, s.location
                 FROM products p
                 LEFT JOIN categories c ON p.category_id = c.id
                 LEFT JOIN stock s ON p.id = s.product_id
                 WHERE p.item_type = 'spare_part'
                 ORDER BY p.name ASC";
} else {
    $sqlParts = "SELECT p.id, p.product_code, p.name, p.unit_price, p.buying_price, p.currency, p.reorder_level,
                 c.name AS category_name, s.quantity, s.location
                 FROM products p
                 LEFT JOIN categories c ON p.category_id = c.id
                 LEFT JOIN stock s ON p.id = s.product_id
                 WHERE c.name IS NOT NULL AND (LOWER(c.name) LIKE '%spare%' OR LOWER(c.name) LIKE '%part%')
                 ORDER BY p.name ASC";
}
try {
    $spareParts = $pdo->query($sqlParts)->fetchAll();
} catch (PDOException $e) {
    $spareParts = [];
}

// Vehicles (trucks/cars) in stock
if ($hasItemType) {
    $sqlVehicles = "SELECT p.id, p.product_code, p.name, p.vin, p.chassis_number, p.engine_number, p.model_year, p.mileage, p.color, p.brand,
                    p.unit_price, p.buying_price, p.currency,
                    c.name AS category_name, s.quantity, s.location
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN stock s ON p.id = s.product_id
                    WHERE p.item_type = 'vehicle'
                    ORDER BY p.name ASC";
} else {
    $sqlVehicles = "SELECT p.id, p.product_code, p.name, p.vin, p.chassis_number, p.engine_number, p.model_year, p.mileage, p.color, p.brand,
                    p.unit_price, p.buying_price, p.currency,
                    c.name AS category_name, s.quantity, s.location
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN stock s ON p.id = s.product_id
                    WHERE c.name IS NOT NULL AND (LOWER(c.name) LIKE '%truck%' OR LOWER(c.name) LIKE '%vehicle%' OR LOWER(c.name) LIKE '%car%')
                    ORDER BY p.name ASC";
}
try {
    $vehicles = $pdo->query($sqlVehicles)->fetchAll();
} catch (PDOException $e) {
    $vehicles = [];
}

$lowStockCount = 0;
foreach (array_merge($spareParts, $vehicles) as $row) {
    if (!empty($row['reorder_level']) && (int)$row['quantity'] <= (int)$row['reorder_level']) $lowStockCount++;
}
?>

<main class="main-content">
    <div class="stock-container container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <div>
                <h2 class="h4 fw-bold mb-1">Fleet & Parts</h2>
                <p class="text-muted small mb-0">Spare parts and vehicles (trucks/cars) in stock.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="../products/add.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-plus me-1"></i>Add Product</a>
                <a href="../reports/stock.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-alt me-1"></i>Stock Report</a>
            </div>
        </div>

        <?php if (!$hasItemType): ?>
        <div class="alert alert-info small mb-3">
            <i class="fas fa-info-circle me-2"></i>
            Lists are filtered by category name. Run <a href="../../migrate_fleet_parts_schema.php">migrate_fleet_parts_schema.php</a> to add <strong>item_type</strong> (spare_part / vehicle) for clearer filtering.
        </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3" id="fleetPartsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="parts-tab" data-bs-toggle="tab" data-bs-target="#parts" type="button" role="tab">
                    <i class="fas fa-tools me-1"></i>Spare Parts <span class="badge bg-secondary"><?php echo count($spareParts); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="vehicles-tab" data-bs-toggle="tab" data-bs-target="#vehicles" type="button" role="tab">
                    <i class="fas fa-truck me-1"></i>Vehicles (Trucks / Cars) <span class="badge bg-secondary"><?php echo count($vehicles); ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="fleetPartsTabContent">
            <!-- Spare Parts -->
            <div class="tab-pane fade show active" id="parts" role="tabpanel">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <?php if (empty($spareParts)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-tools fa-3x mb-3 opacity-50"></i>
                            <p class="mb-0">No spare parts in stock. Add products and assign them to a "Spare Parts" (or similar) category.</p>
                            <a href="../products/add.php" class="btn btn-primary btn-sm mt-3">Add product</a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Code</th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <?php if ($hasItemType): ?><th>Brand / Compatibility</th><?php endif; ?>
                                        <th>Location</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Price</th>
                                        <th class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($spareParts as $r):
                                        $low = isset($r['reorder_level']) && (int)$r['quantity'] <= (int)$r['reorder_level'];
                                    ?>
                                    <tr class="<?php echo $low ? 'table-warning' : ''; ?>">
                                        <td class="ps-3"><?php echo htmlspecialchars($r['product_code']); ?></td>
                                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                                        <td><?php echo htmlspecialchars($r['category_name'] ?? '–'); ?></td>
                                        <?php if ($hasItemType): ?>
                                        <td><?php echo htmlspecialchars(trim(($r['brand'] ?? '') . ' ' . ($r['compatibility'] ?? '')) ?: '–'); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($r['location'] ?? '–'); ?></td>
                                        <td class="text-center"><?php echo (int)($r['quantity'] ?? 0); ?></td>
                                        <td class="text-end"><?php $sym = (($r['currency'] ?? 'USD') == 'TZS') ? 'TSh ' : '$'; echo $sym . number_format($r['unit_price'] ?? 0, 2); ?></td>
                                        <td class="text-end pe-3">
                                            <a href="../products/view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                            <a href="../products/edit.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Vehicles -->
            <div class="tab-pane fade" id="vehicles" role="tabpanel">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                        <?php if (empty($vehicles)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-truck fa-3x mb-3 opacity-50"></i>
                            <p class="mb-0">No vehicles in stock. Add products and assign them to "Trucks" or "Cars" category.</p>
                            <a href="../products/add.php" class="btn btn-primary btn-sm mt-3">Add vehicle</a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-3">Code / Name</th>
                                        <th>VIN / Chassis</th>
                                        <th>Year</th>
                                        <th>Mileage</th>
                                        <th>Location</th>
                                        <th class="text-end">Price</th>
                                        <th class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicles as $r): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <strong><?php echo htmlspecialchars($r['product_code']); ?></strong><br>
                                            <span class="text-muted small"><?php echo htmlspecialchars($r['name']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($r['vin'] ?? $r['chassis_number'] ?? '–'); ?></td>
                                        <td><?php echo $r['model_year'] ? (int)$r['model_year'] : '–'; ?></td>
                                        <td><?php echo $r['mileage'] !== null ? number_format((float)$r['mileage'], 0) . ' km' : '–'; ?></td>
                                        <td><?php echo htmlspecialchars($r['location'] ?? '–'); ?></td>
                                        <td class="text-end"><?php $sym = (($r['currency'] ?? 'USD') == 'TZS') ? 'TSh ' : '$'; echo $sym . number_format($r['unit_price'] ?? 0, 2); ?></td>
                                        <td class="text-end pe-3">
                                            <a href="../products/view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                            <a href="../products/edit.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
