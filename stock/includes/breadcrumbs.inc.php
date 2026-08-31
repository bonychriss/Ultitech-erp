<?php
/**
 * Auto-generated breadcrumbs for Stock module pages.
 * Optional: set $stock_breadcrumbs = [['label'=>'X','href'=>'/path/'], ...] in the page before including header.php (same scope), or $GLOBALS['stock_breadcrumbs'].
 */
if (!function_exists('stock_render_breadcrumbs')) {
    /**
     * @return list<array{label:string, href:?string}>
     */
    function stock_build_stock_breadcrumbs(): array
    {
        global $stockBasePath;

        if (!empty($GLOBALS['stock_breadcrumbs']) && is_array($GLOBALS['stock_breadcrumbs'])) {
            return $GLOBALS['stock_breadcrumbs'];
        }

        $baseUrl = rtrim((string) ($stockBasePath ?? '/stock/'), '/') . '/';
        $stockRootFs = realpath(dirname(__DIR__)); // .../stock
        if ($stockRootFs === false) {
            $stockRootFs = dirname(__DIR__);
        }
        $stockRootFs = str_replace('\\', '/', $stockRootFs);

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $pos = stripos($script, '/stock/');
        if ($pos === false) {
            return [
                ['label' => 'Stock', 'href' => $baseUrl . 'index.php'],
            ];
        }

        $after = trim(substr($script, $pos + strlen('/stock/')), '/');
        if ($after === '') {
            return [
                ['label' => 'Stock', 'href' => null],
            ];
        }

        $parts = $after === '' ? [] : explode('/', $after);
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));

        if (count($parts) === 1 && strtolower($parts[0]) === 'index.php') {
            return [
                ['label' => 'Stock', 'href' => null],
            ];
        }

        $segmentTitle = static function (string $seg): string {
            static $map = [
                'modules' => 'Modules',
                'products' => 'Products',
                'categories' => 'Categories',
                'brands' => 'Brands',
                'suppliers' => 'Suppliers',
                'stock' => 'Stock control',
                'reports' => 'Reports',
                'uploads' => 'Uploaded files',
                'purchases' => 'Purchases',
                'shipments' => 'Shipments',
                'auth' => 'Account',
                'settings' => 'Settings',
                'dashboard.php' => 'Dashboard',
                'index.php' => 'Overview',
                'add.php' => 'Add',
                'edit.php' => 'Edit',
                'view.php' => 'View',
                'delete.php' => 'Delete',
                'stock.php' => 'Stock level report',
                'export_stock.php' => 'Export CSV',
                'replenishment.php' => 'Replenishment',
                'movements.php' => 'Movements',
                'upload.php' => 'Upload',
            ];
            $lower = strtolower($seg);
            if (isset($map[$lower])) {
                return $map[$lower];
            }
            if (preg_match('/\.php$/', $seg)) {
                return ucwords(str_replace(['-', '_', '.php'], [' ', ' ', ''], $seg));
            }

            return ucwords(str_replace(['-', '_'], [' ', ' '], $seg));
        };

        $crumbs = [
            ['label' => 'Stock', 'href' => $baseUrl . 'index.php'],
        ];

        $n = count($parts);
        for ($i = 0; $i < $n; $i++) {
            $seg = $parts[$i];
            $isLast = ($i === $n - 1);
            $isFile = (bool) preg_match('/\.php$/i', $seg);

            if ($isLast && $isFile) {
                if ($seg === 'index.php' && $i > 0) {
                    $parent = $parts[$i - 1];
                    $crumbs[] = ['label' => 'All ' . $segmentTitle($parent), 'href' => null];
                } else {
                    $crumbs[] = ['label' => $segmentTitle($seg), 'href' => null];
                }
                break;
            }

            $relToHere = implode('/', array_slice($parts, 0, $i + 1));
            $indexFs = $stockRootFs . '/' . $relToHere . '/index.php';
            $href = is_file($indexFs) ? ($baseUrl . $relToHere . '/index.php') : null;
            $crumbs[] = ['label' => $segmentTitle($seg), 'href' => $href];
        }

        return $crumbs;
    }

    function stock_render_breadcrumbs(): void
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/stock/modules/auth/login\\.php$#i', $script)) {
            return;
        }

        $items = stock_build_stock_breadcrumbs();
        if (count($items) < 1) {
            return;
        }
        ?>
        <nav class="stock-breadcrumb-nav border-bottom bg-white" aria-label="Breadcrumb" style="--bc-pad: 0.65rem 1.25rem;">
            <ol class="breadcrumb mb-0 py-2 px-3 px-md-4 small" style="font-size: 0.8125rem;">
                <?php foreach ($items as $idx => $item): ?>
                    <?php
                    $label = (string) ($item['label'] ?? '');
                    $href = $item['href'] ?? null;
                    $isLast = ($idx === count($items) - 1);
                    ?>
                    <?php if ($isLast): ?>
                        <li class="breadcrumb-item active text-secondary fw-semibold" aria-current="page"><?= htmlspecialchars($label) ?></li>
                    <?php else: ?>
                        <li class="breadcrumb-item">
                            <?php if ($href !== null && $href !== ''): ?>
                                <a href="<?= htmlspecialchars($href) ?>" class="text-decoration-none"><?= htmlspecialchars($label) ?></a>
                            <?php else: ?>
                                <span class="text-muted"><?= htmlspecialchars($label) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
        <style>
            .stock-breadcrumb-nav .breadcrumb-item + .breadcrumb-item::before {
                content: ">";
                font-weight: 600;
                color: #94a3b8;
                padding: 0 0.35rem;
            }
            .stock-breadcrumb-nav a { color: #4f46e5; }
            .stock-breadcrumb-nav a:hover { color: #3730a3; text-decoration: underline !important; }
        </style>
        <?php
    }
}
