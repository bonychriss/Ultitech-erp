<?php
// includes/shipment-functions.php

function getShipmentStatusBadge($status) {
    $colors = [
        'pending' => 'secondary',
        'confirmed' => 'info',
        'shipped' => 'primary',
        'in_transit' => 'warning',
        'arrived_at_port' => 'success',
        'in_customs' => 'orange', // Custom class needed or use warning/danger
        'delivered' => 'teal', // Custom
        'delayed' => 'danger',
        'cancelled' => 'dark'
    ];
    
    // Map custom bootstrap-like classes if they don't exist
    $bsClass = $colors[$status] ?? 'secondary';
    
    // Custom styles for non-standard BS5 colors
    $style = '';
    if ($status == 'in_customs') {
        $bsClass = 'warning'; 
        $style = 'background-color: #fd7e14; color: white;';
    } elseif ($status == 'delivered') {
        $bsClass = 'success';
        $style = 'background-color: #20c997; color: white;';
    } elseif ($status == 'in_transit') {
        $style = 'background-color: #ffc107; color: black;';
    } elseif ($status == 'ready_for_pickup') {
        $bsClass = 'info';
        $style = 'background-color: #0dcaf0; color: black;';
    } elseif ($status == 'out_for_delivery') {
        $bsClass = 'primary';
        $style = 'background-color: #0d6efd; color: white;';
    }
    
    $label = ucwords(str_replace('_', ' ', $status));
    
    return "<span class='badge bg-$bsClass' style='$style'>$label</span>";
}

function autoLinkDescriptionToProducts($pdo, $description) {
    $product_map = [
        'MASK' => 'Face Mask',
        'FACEMASK' => 'Face Mask',
        'GLOVES' => 'Protective Gloves',
        'EYEGLASS' => 'Safety Glasses',
        'GARMENTS' => 'Protective Clothing',
        'CLOTHES' => 'Protective Clothing'
    ];
    
    $keywords = explode(' ', strtoupper($description));
    
    foreach ($keywords as $keyword) {
        if (isset($product_map[$keyword])) {
            // Find product by name (LIKE)
            $term = "%" . $product_map[$keyword] . "%";
            $stmt = $pdo->prepare("SELECT id FROM products WHERE name LIKE ? LIMIT 1");
            $stmt->execute([$term]);
            if ($id = $stmt->fetchColumn()) {
                return $id;
            }
        }
    }
    
    return null; // No auto-link found
}

function updateShipmentStatusesAutomatically($pdo) {
    try {
        $today = date('Y-m-d');
        
        // 1. Mark as DELAYED if ETA passed and not arrived/delivered
        $sql1 = "UPDATE shipments 
                 SET status = 'delayed' 
                 WHERE status IN ('shipped', 'in_transit') 
                 AND eta < ? 
                 AND actual_arrival_date IS NULL";
        $pdo->prepare($sql1)->execute([$today]);
        
        // 2. Mark as IN_TRANSIT if ETD passed and status was just 'shipped'
        $sql2 = "UPDATE shipments 
                 SET status = 'in_transit' 
                 WHERE status = 'shipped' 
                 AND etd < ?";
        $pdo->prepare($sql2)->execute([$today]);
    } catch (PDOException $e) {
        // Quietly fail or log - table might be missing during migration
        error_log("Shipment auto-update failed: " . $e->getMessage());
    }
}

/**
 * Add columns expected by create/edit/import and landed-cost logic (older DBs may lack these).
 */
function ensure_shipments_schema_columns(PDO $pdo): void {
    static $colsEnsured = false;
    if ($colsEnsured) {
        return;
    }
    $colsEnsured = true;
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn()) {
            return;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM shipments')->fetchAll(PDO::FETCH_COLUMN);
        $alters = [
            'estimated_clearance_cost' => 'ALTER TABLE shipments ADD COLUMN estimated_clearance_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00',
            'received_by' => 'ALTER TABLE shipments ADD COLUMN received_by INT NULL DEFAULT NULL',
            'total_additional_costs' => 'ALTER TABLE shipments ADD COLUMN total_additional_costs DECIMAL(15,2) NULL DEFAULT NULL',
            'total_landed_cost' => 'ALTER TABLE shipments ADD COLUMN total_landed_cost DECIMAL(15,2) NULL DEFAULT NULL',
            'cost_calculated_at' => 'ALTER TABLE shipments ADD COLUMN cost_calculated_at DATETIME NULL DEFAULT NULL',
            'total_value_currency' => "ALTER TABLE shipments ADD COLUMN total_value_currency VARCHAR(3) NOT NULL DEFAULT 'USD' AFTER total_value",
        ];
        foreach ($alters as $name => $sql) {
            if (!in_array($name, $cols, true)) {
                try {
                    $pdo->exec($sql);
                } catch (Throwable $e) {
                    error_log('ensure_shipments_schema_columns ' . $name . ': ' . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        error_log('ensure_shipments_schema_columns: ' . $e->getMessage());
    }
}

/**
 * Add shipments.stocks_po_id and shipment_items.stocks_item_id; relax product_id null; backfill PO link from lines.
 */
function ensure_shipment_po_linking_schema(PDO $pdo): void {
    ensure_shipments_schema_columns($pdo);

    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $hasShipments = (bool) $pdo->query("SHOW TABLES LIKE 'shipments'")->fetchColumn();
        if (!$hasShipments) {
            return;
        }
        $cols = $pdo->query("SHOW COLUMNS FROM shipments")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('stocks_po_id', $cols, true)) {
            $pdo->exec("ALTER TABLE shipments ADD COLUMN stocks_po_id INT NULL DEFAULT NULL COMMENT 'stocks_purchase_orders.id' AFTER supplier_id");
            try {
                $pdo->exec("ALTER TABLE shipments ADD INDEX idx_shipments_stocks_po_id (stocks_po_id)");
            } catch (Throwable $e) {
                // duplicate index
            }
            try {
                $pdo->exec("ALTER TABLE shipments ADD CONSTRAINT fk_shipments_stocks_po FOREIGN KEY (stocks_po_id) REFERENCES stocks_purchase_orders(id) ON DELETE SET NULL");
            } catch (Throwable $e) {
                // FK not supported or already exists
            }
        }

        $hasItems = (bool) $pdo->query("SHOW TABLES LIKE 'shipment_items'")->fetchColumn();
        if ($hasItems) {
            $colsSi = $pdo->query("SHOW COLUMNS FROM shipment_items")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('stocks_item_id', $colsSi, true)) {
                $pdo->exec("ALTER TABLE shipment_items ADD COLUMN stocks_item_id INT NULL DEFAULT NULL COMMENT 'stocks_items.id' AFTER product_id");
                try {
                    $pdo->exec("ALTER TABLE shipment_items ADD INDEX idx_shipment_items_stocks_item_id (stocks_item_id)");
                } catch (Throwable $e) {
                }
                try {
                    $pdo->exec("ALTER TABLE shipment_items ADD CONSTRAINT fk_shipment_items_stocks_item FOREIGN KEY (stocks_item_id) REFERENCES stocks_items(id) ON DELETE SET NULL");
                } catch (Throwable $e) {
                }
            }
            try {
                $pdo->exec('ALTER TABLE shipment_items MODIFY COLUMN product_id INT NULL');
            } catch (Throwable $e) {
            }
        }

        try {
            $pdo->exec("
                UPDATE shipments sh
                INNER JOIN (
                    SELECT shipment_id, MIN(purchase_id) AS pid
                    FROM shipment_items
                    WHERE purchase_id IS NOT NULL
                    GROUP BY shipment_id
                    HAVING COUNT(DISTINCT purchase_id) = 1
                ) x ON x.shipment_id = sh.id AND (sh.stocks_po_id IS NULL OR sh.stocks_po_id = 0)
                SET sh.stocks_po_id = x.pid
            ");
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
        error_log('ensure_shipment_po_linking_schema: ' . $e->getMessage());
    }
}

function stocks_po_has_linked_shipment(PDO $pdo, int $poId): bool {
    ensure_shipment_po_linking_schema($pdo);
    $st = $pdo->prepare('SELECT 1 FROM shipments WHERE stocks_po_id = ? LIMIT 1');
    $st->execute([$poId]);
    if ($st->fetchColumn()) {
        return true;
    }
    $st2 = $pdo->prepare('SELECT 1 FROM shipment_items WHERE purchase_id = ? LIMIT 1');
    $st2->execute([$poId]);

    return (bool) $st2->fetchColumn();
}

/**
 * Currencies allowed for shipment invoice total value (stored as ISO 4217 code).
 *
 * @return array<string, string> code => label for select options
 */
function shipment_total_value_currency_options(): array {
    return [
        'USD' => 'USD ($)',
        'EUR' => 'EUR (€)',
        'GBP' => 'GBP (£)',
        'CNY' => 'CNY (¥)',
        'JPY' => 'JPY (¥)',
        'TZS' => 'TZS',
        'AUD' => 'AUD (A$)',
        'CAD' => 'CAD (C$)',
        'INR' => 'INR (₹)',
        'AED' => 'AED',
        'HKD' => 'HKD (HK$)',
    ];
}

function normalize_shipment_total_value_currency(string $code): string {
    $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $code));
    if (strlen($code) !== 3) {
        return 'USD';
    }
    $opts = shipment_total_value_currency_options();

    return isset($opts[$code]) ? $code : 'USD';
}

/** Prefix/symbol for display before a formatted amount (e.g. "$", "€"). */
function shipment_currency_display_prefix(string $code): string {
    $c = normalize_shipment_total_value_currency($code);
    switch ($c) {
        case 'USD':
            return '$';
        case 'EUR':
            return '€';
        case 'GBP':
            return '£';
        case 'CNY':
        case 'JPY':
            return '¥';
        case 'TZS':
            return 'TSh ';
        case 'AUD':
            return 'A$';
        case 'CAD':
            return 'C$';
        case 'INR':
            return '₹';
        case 'HKD':
            return 'HK$';
        default:
            return $c . ' ';
    }
}

function stocks_po_linked_shipment_id(PDO $pdo, int $poId): ?int {
    ensure_shipment_po_linking_schema($pdo);
    $st = $pdo->prepare('SELECT id FROM shipments WHERE stocks_po_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$poId]);
    $id = $st->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $st2 = $pdo->prepare('SELECT MIN(shipment_id) FROM shipment_items WHERE purchase_id = ?');
    $st2->execute([$poId]);
    $sid = $st2->fetchColumn();

    return $sid ? (int) $sid : null;
}
?>
