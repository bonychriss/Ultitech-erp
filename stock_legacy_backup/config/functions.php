<?php
// config/functions.php

if (!function_exists('clean_input')) {
    function clean_input($data) {
        if ($data === null) return '';
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit();
    }
}

// Auth functions helper
if (!function_exists('hasRole')) {
    function hasRole($roles) {
        if (!isset($_SESSION['role'])) return false;
        if (is_array($roles)) {
            return in_array($_SESSION['role'], $roles);
        }
        return $_SESSION['role'] === $roles;
    }
}

if (!function_exists('requireRole')) {
    function requireRole($roles) {
        requireLogin();
        if (!hasRole($roles)) {
            header('Location: /select-module.php?error=access_denied');
            exit();
        }
    }
}

if (!function_exists('flash')) {
    function flash($name, $text = '', $type = 'success') {
        if ($text != '') {
            $_SESSION[$name] = $text;
            $_SESSION[$name.'_type'] = $type;
        } else {
            if (isset($_SESSION[$name])) {
                $type = isset($_SESSION[$name.'_type']) ? $_SESSION[$name.'_type'] : 'success';
                $class = ($type == 'error') ? 'danger' : $type;
                echo '<div class="alert alert-' . $class . ' alert-dismissible fade show" role="alert">
                        ' . $_SESSION[$name] . '
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                      </div>';
                unset($_SESSION[$name]);
                unset($_SESSION[$name.'_type']);
            }
        }
    }
}

// Settings Helper
if (!function_exists('getCompanySettings')) {
    function getCompanySettings($pdo) {
        if (isset($GLOBALS['company_settings_cache'])) {
            return $GLOBALS['company_settings_cache'];
        }

        $defaults = [
            'currency' => 'USD',
            'company_name' => 'My Company',
            'exchange_rate' => 1,
        ];

        $settings = $defaults;
        try {
            $stmt = $pdo->query('SELECT * FROM company_settings LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($row) && $row !== []) {
                $settings = array_merge($defaults, $row);
            }
        } catch (Throwable $e) {
            // Missing table, permission error, etc. â€” keep defaults
        }

        if (!isset($settings['exchange_rate']) || (float) $settings['exchange_rate'] <= 0) {
            $settings['exchange_rate'] = 1;
        }

        $GLOBALS['company_settings_cache'] = $settings;
        return $settings;
    }
}

/**
 * Create company_settings if missing (stock module / shared DB without main app migration).
 */
if (!function_exists('ensureStockCompanySettingsTable')) {
    function ensureStockCompanySettingsTable(PDO $pdo): void {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'company_settings'")->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($exists) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE company_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_name VARCHAR(255) NOT NULL DEFAULT '',
                phone VARCHAR(120) DEFAULT NULL,
                email VARCHAR(255) DEFAULT NULL,
                address TEXT,
                city VARCHAR(120) DEFAULT NULL,
                country VARCHAR(120) DEFAULT NULL,
                bank_details TEXT,
                terms_and_conditions TEXT,
                currency VARCHAR(10) NOT NULL DEFAULT 'USD',
                default_payment_terms VARCHAR(120) DEFAULT 'Net 30',
                exchange_rate DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("INSERT INTO company_settings (id, company_name, currency, exchange_rate) VALUES (1, 'My Company', 'USD', 1)");
        } catch (Throwable $e) {
            // Race or permission â€” page may still error on use
        }
    }
}

/**
 * Create stock_movements if missing (audit trail for receipts, adjustments, shipments).
 * Uses VARCHAR for reference_type so values like 'shipment' and 'purchase' are allowed.
 */
if (!function_exists('ensureStockMovementsTable')) {
    function ensureStockMovementsTable(PDO $pdo): void {
        try {
            $exists = (bool) $pdo->query("SHOW TABLES LIKE 'stock_movements'")->fetchColumn();
        } catch (Throwable $e) {
            return;
        }
        if ($exists) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE stock_movements (
                id INT(11) NOT NULL AUTO_INCREMENT,
                product_id INT(11) NOT NULL,
                movement_type VARCHAR(20) NOT NULL DEFAULT 'adjustment',
                quantity INT(11) NOT NULL,
                reference_type VARCHAR(50) DEFAULT NULL,
                reference_id VARCHAR(50) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY product_id (product_id),
                CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            try {
                $pdo->exec("CREATE TABLE stock_movements (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    product_id INT(11) NOT NULL,
                    movement_type VARCHAR(20) NOT NULL DEFAULT 'adjustment',
                    quantity INT(11) NOT NULL,
                    reference_type VARCHAR(50) DEFAULT NULL,
                    reference_id VARCHAR(50) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY product_id (product_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Throwable $e2) {
                // Permission / engine â€” caller may surface error
            }
        }
    }
}

// Helper to convert currency for display
if (!function_exists('convertCurrency')) {
    function convertCurrency($amount, $rate = 1) {
       if (!is_numeric($amount)) return 0;
       return $amount * $rate;
    }
}

if (!function_exists('getCurrencySymbol')) {
    function getCurrencySymbol($currencyCode) {
        switch(strtoupper($currencyCode)) {
            case 'TZS': return 'TSh ';
            case 'EUR': return 'â‚¬';
            case 'GBP': return 'Â£';
            case 'KES': return 'KSh ';
            default: return '$';
        }
    }
}

/**
 * Resolves a product image URL by checking database value and disk existence.
 */
if (!function_exists('resolveProductImageUrl')) {
    function resolveProductImageUrl($productId, $imageValue, $size = 'medium') {
        $productId = (int)$productId;
        $imageValue = trim((string)$imageValue);
        
        if ($imageValue === '' || $imageValue === 'placeholder.jpg') {
            return "/stock/assets/images/no-image.png";
        }

        // Absolute URL
        if (preg_match('~^https?://~i', $imageValue)) {
            return $imageValue;
        }

        // Global asset / Absolute path starting with /
        if (strpos($imageValue, '/') === 0 || strpos($imageValue, '\\') === 0) {
            return $imageValue;
        }

        // Relative path containing slashes (e.g. ../../assets/images/...)
        if (strpos($imageValue, '/') !== false || strpos($imageValue, '\\') !== false) {
            return $imageValue;
        }

        // Standard Uploads structure: stock/uploads/products/{id}/{size}/{file}
        $filename = basename(str_replace('\\', '/', $imageValue));
        if ($filename === '' || $filename === '.' || $filename === '..') {
             return "/stock/assets/images/no-image.png";
        }

        // We check existence via product_image.php logic or direct path.
        // For simplicity in templates, we can return the PHP endpoint URL.
        return "/stock/product_image.php?product_id={$productId}&size={$size}&file=" . rawurlencode($filename);
    }
}

// End of functions.php
