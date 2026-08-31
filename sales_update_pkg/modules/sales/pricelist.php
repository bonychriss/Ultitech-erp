<?php
require_once '../../includes/config.php';
require_once 'functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) $_SESSION['user_id'] = 1;

$currency = 'TZS';

// Company settings Ã¢â‚¬â€ table may not exist on all deployments
try {
    $company_settings = $pdo->query("SELECT * FROM sales_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $company_settings = false;
}
if (!$company_settings) {
    $company_settings = [
        'company_name' => defined('COMPANY_NAME') ? COMPANY_NAME : 'Ultimate General Trading',
        'company_address' => defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : 'Dar es Salaam, Tanzania',
        'company_logo' => 'Untitled.jpg',
        'default_currency' => 'TZS',
        'company_phone' => '',
        'company_email' => '',
        'company_tin' => '',
        'company_vat' => '',
        'bank_details' => '',
        'company_website' => '',
        'include_catalogue' => 0
    ];
}

// Products: tolerate missing columns; join category / brand when available
$products = [];
$pricelist_meta = ['last_updated_iso' => null];
try {
    $productCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    $productCols = is_array($productCols) ? $productCols : [];
    if (in_array('unit_price', $productCols, true)) {
        $priceExpr = 'p.unit_price AS selling_price';
    } elseif (in_array('selling_price', $productCols, true)) {
        $priceExpr = 'p.selling_price';
    } else {
        $priceExpr = '0 AS selling_price';
    }
    if (in_array('main_image', $productCols, true) && in_array('image', $productCols, true)) {
        $imgExpr = 'COALESCE(p.main_image, p.image) AS main_image';
    } elseif (in_array('main_image', $productCols, true)) {
        $imgExpr = 'p.main_image AS main_image';
    } elseif (in_array('image', $productCols, true)) {
        $imgExpr = 'p.image AS main_image';
    } else {
        $imgExpr = 'NULL AS main_image';
    }

    $joins = '';
    $extraSelect = '';
    if (in_array('category_id', $productCols, true)) {
        $joins .= ' LEFT JOIN categories cat ON p.category_id = cat.id ';
        $extraSelect .= ', COALESCE(cat.name, \'\') AS category_name';
    } else {
        $extraSelect .= ', \'\' AS category_name';
    }

    $brandJoined = false;
    if (in_array('brand_id', $productCols, true)) {
        try {
            $pdo->query('SELECT 1 FROM brands LIMIT 1');
            $joins .= ' LEFT JOIN brands br ON p.brand_id = br.id ';
            $extraSelect .= ', COALESCE(br.name, \'\') AS brand_name';
            $brandJoined = true;
        } catch (Throwable $e) {
            /* no brands table */
        }
    }
    if (!$brandJoined && in_array('brand', $productCols, true)) {
        $extraSelect .= ', COALESCE(p.brand, \'\') AS brand_name';
        $brandJoined = true;
    }
    if (!$brandJoined) {
        $extraSelect .= ', \'\' AS brand_name';
    }

    if (in_array('updated_at', $productCols, true)) {
        $extraSelect .= ', p.updated_at AS row_updated_at';
    } elseif (in_array('modified_at', $productCols, true)) {
        $extraSelect .= ', p.modified_at AS row_updated_at';
    } else {
        $extraSelect .= ', NULL AS row_updated_at';
    }

    if (in_array('is_published', $productCols, true)) {
        $extraSelect .= ', p.is_published AS row_is_published';
    } else {
        $extraSelect .= ', NULL AS row_is_published';
    }
    if (in_array('status', $productCols, true)) {
        $extraSelect .= ', p.status AS row_status';
    } else {
        $extraSelect .= ', NULL AS row_status';
    }

    $products = $pdo->query("
        SELECT p.id, p.product_code, p.name, p.description, $priceExpr, $imgExpr
            $extraSelect
        FROM products p
        $joins
        ORDER BY p.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $latestTs = null;
    foreach ($products as &$pr) {
        $ts = null;
        if (!empty($pr['row_updated_at'])) {
            $ts = strtotime((string) $pr['row_updated_at']);
        }
        if ($ts && ($latestTs === null || $ts > $latestTs)) {
            $latestTs = $ts;
        }
        $pr['is_catalog_active'] = true;
        if (isset($pr['row_is_published']) && $pr['row_is_published'] !== null && $pr['row_is_published'] !== '') {
            $pr['is_catalog_active'] = (int) $pr['row_is_published'] === 1;
        } elseif (!empty($pr['row_status'])) {
            $st = strtolower(trim((string) $pr['row_status']));
            $pr['is_catalog_active'] = in_array($st, ['active', 'published', '1', 'yes', 'true'], true);
        }
    }
    unset($pr);
    if ($latestTs) {
        $pricelist_meta['last_updated_iso'] = gmdate('c', $latestTs);
    }
} catch (Throwable $e) {
    $products = [];
}
$currency = $company_settings['default_currency'] ?? 'TZS';
$logoFile = $company_settings['company_logo'] ?? 'Untitled.jpg';
$logoPath = '/assets/images/' . $logoFile;

// Fetch current user details
$userId = $_SESSION['user_id'];
$user_data = $pdo->prepare("SELECT full_name, signature_path FROM users WHERE id = ?");
$user_data->execute([$userId]);
$currentUser = $user_data->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price List | <?php echo htmlspecialchars($company_settings['company_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background-color: #F9F9F9;
            font-family: 'Outfit', system-ui, -apple-system, sans-serif;
            color: #374151;
            font-size: 16px;
        }
        .main-content {
            padding: 0;
            max-width: 100%;
            margin: 0 auto;
            min-height: calc(100vh - 64px);
        }
        .pl-btn-primary {
            background-color: #2563EB;
            color: #fff;
            border: 1px solid #2563EB;
        }
        .pl-btn-primary:hover {
            background-color: #1D4ED8;
            border-color: #1D4ED8;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in { animation: fadeIn 0.2s ease-out forwards; }
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <?php include '../../includes/header_employee.php'; ?>

    <div class="main-content" id="react-root"></div>

    <script>
        window.APP_DATA = {
            products: <?= json_encode($products) ?>,
            company: <?= json_encode($company_settings) ?>,
            currency: <?= json_encode($currency) ?>,
            logoPath: <?= json_encode($logoPath) ?>,
            currentUser: <?= json_encode($currentUser) ?>,
            meta: <?= json_encode($pricelist_meta ?? ['last_updated_iso' => null]) ?>
        };
    </script>

    <script type="text/babel">
        const { useState, useMemo, useEffect } = React;

        function formatCurrency(amount) {
            const cur = window.APP_DATA.currency || 'TZS';
            try {
                return new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: cur,
                    minimumFractionDigits: 0
                }).format(Number(amount) || 0);
            } catch (e) {
                return cur + ' ' + String(Number(amount) || 0);
            }
        }

        function formatPlainNumber(amount) {
            return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(Number(amount) || 0);
        }

        function formatMoneyDashboard(amount) {
            const cur = window.APP_DATA.currency || 'TZS';
            try {
                return new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: cur,
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(Number(amount) || 0);
            } catch (e) {
                return cur + ' ' + formatPlainNumber(amount);
            }
        }

        function escapeHtml(s) {
            if (s == null) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function PriceListApp() {
            const data = window.APP_DATA;
            const [products, setProducts] = useState(data.products.map(p => ({
                ...p,
                edited_price: p.selling_price
            })));

            const [search, setSearch] = useState('');
            const [customerName, setCustomerName] = useState('');
            const [isModalOpen, setIsModalOpen] = useState(false);
            const [isGenerating, setIsGenerating] = useState(false);
            const [genStats, setGenStats] = useState({ progress: 0, time: 0, speed: 0 });
            const [categoryFilter, setCategoryFilter] = useState('');
            const [brandFilter, setBrandFilter] = useState('');
            const [activeOnly, setActiveOnly] = useState(true);
            const [showMoreFilters, setShowMoreFilters] = useState(false);
            const [minPrice, setMinPrice] = useState('');
            const [maxPrice, setMaxPrice] = useState('');
            const [page, setPage] = useState(1);
            const [pageSize, setPageSize] = useState(10);
            const [menuRowId, setMenuRowId] = useState(null);

            const logDebug = (msg) => {
                console.log(`[DEBUG] ${msg}`);
            };

            const categoryOptions = useMemo(() => {
                const set = new Set();
                products.forEach(p => {
                    const n = (p.category_name || '').trim();
                    if (n) set.add(n);
                });
                return Array.from(set).sort((a, b) => a.localeCompare(b));
            }, [products]);

            const brandOptions = useMemo(() => {
                const set = new Set();
                products.forEach(p => {
                    const n = (p.brand_name || '').trim();
                    if (n) set.add(n);
                });
                return Array.from(set).sort((a, b) => a.localeCompare(b));
            }, [products]);

            const filteredProducts = useMemo(() => {
                const s = search.trim().toLowerCase();
                const minN = minPrice === '' ? null : Number(minPrice);
                const maxN = maxPrice === '' ? null : Number(maxPrice);
                return products.filter(p => {
                    if (activeOnly && p.is_catalog_active === false) return false;
                    if (categoryFilter && (p.category_name || '').trim() !== categoryFilter) return false;
                    if (brandFilter && (p.brand_name || '').trim() !== brandFilter) return false;
                    const ep = Number(p.edited_price) || 0;
                    if (minN !== null && !Number.isNaN(minN) && ep < minN) return false;
                    if (maxN !== null && !Number.isNaN(maxN) && ep > maxN) return false;
                    if (!s) return true;
                    return (
                        (p.name || '').toLowerCase().includes(s) ||
                        (p.description || '').toLowerCase().includes(s) ||
                        (p.product_code || '').toLowerCase().includes(s) ||
                        (p.category_name || '').toLowerCase().includes(s) ||
                        (p.brand_name || '').toLowerCase().includes(s)
                    );
                });
            }, [search, products, categoryFilter, brandFilter, activeOnly, minPrice, maxPrice]);

            const totalPages = Math.max(1, Math.ceil(filteredProducts.length / pageSize) || 1);
            const paginatedProducts = useMemo(() => {
                const p = Math.min(page, totalPages);
                const start = (p - 1) * pageSize;
                return filteredProducts.slice(start, start + pageSize);
            }, [filteredProducts, page, pageSize, totalPages]);

            const visiblePageNumbers = useMemo(() => {
                const t = totalPages;
                const p = Math.min(page, t);
                if (t <= 9) return Array.from({ length: t }, (_, i) => i + 1);
                const nums = new Set([1, t]);
                for (let i = p - 2; i <= p + 2; i++) {
                    if (i >= 1 && i <= t) nums.add(i);
                }
                const sorted = [...nums].sort((a, b) => a - b);
                const out = [];
                for (let i = 0; i < sorted.length; i++) {
                    if (i > 0 && sorted[i] - sorted[i - 1] > 1) out.push('ellipsis');
                    out.push(sorted[i]);
                }
                return out;
            }, [totalPages, page]);

            useEffect(() => {
                const onDocClick = (ev) => {
                    if (!ev.target.closest('[data-pl-row-menu]')) setMenuRowId(null);
                };
                document.addEventListener('click', onDocClick);
                return () => document.removeEventListener('click', onDocClick);
            }, []);

            useEffect(() => {
                const tp = Math.max(1, Math.ceil(filteredProducts.length / pageSize) || 1);
                if (page > tp) setPage(tp);
            }, [filteredProducts.length, pageSize, page]);

            useEffect(() => {
                setPage(1);
            }, [search, categoryFilter, brandFilter, activeOnly, minPrice, maxPrice, pageSize]);

            const dashboardStats = useMemo(() => {
                const totalProducts = products.length;
                const pricedItems = products.filter(p => Number(p.selling_price) > 0 || Number(p.edited_price) > 0).length;
                const totalValue = products.reduce((acc, p) => acc + (Number(p.edited_price) || 0), 0);
                let lastLabel = 'â€”';
                const iso = data.meta && data.meta.last_updated_iso;
                if (iso) {
                    try {
                        lastLabel = new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    } catch (e) { /* ignore */ }
                }
                return { totalProducts, pricedItems, totalValue, lastLabel };
            }, [products, data.meta]);

            const resetRowPrice = (id) => {
                setProducts(prev => prev.map(p => (p.id === id ? { ...p, edited_price: p.selling_price } : p)));
                setMenuRowId(null);
            };

            const clearAllFilters = () => {
                setSearch('');
                setCategoryFilter('');
                setBrandFilter('');
                setActiveOnly(true);
                setMinPrice('');
                setMaxPrice('');
                setShowMoreFilters(false);
            };

            const handlePriceChange = (id, newPrice) => {
                setProducts(products.map(p =>
                    p.id === id ? { ...p, edited_price: parseFloat(newPrice) || 0 } : p
                ));
            };

            const resetPrices = () => {
                setProducts(products.map(p => ({ ...p, edited_price: p.selling_price })));
            };

            const generatePDF = () => {
                logDebug('Download button clicked');
                if (typeof html2pdf === 'undefined') {
                    logDebug('CRITICAL: html2pdf is undefined!');
                    alert('PDF library is still loading. Please wait a moment and try again.');
                    return;
                }

                logDebug('Starting generation flow...');
                setIsGenerating(true);
                setIsModalOpen(false);
                setGenStats({ progress: 5, time: '0.0', speed: '1.2' });
                
                logDebug(`Customer Name: "${customerName}"`);
                logDebug(`Products to process: ${products.length}`);
                const start = Date.now();
                const timer = setInterval(() => {
                    setGenStats(prev => {
                        const elapsed = ((Date.now() - start) / 1000).toFixed(1);
                        const newProgress = Math.min(99, parseFloat(prev.progress) + (Math.random() * 1.5));
                        const mockSpeed = (Math.random() * 2 + 1.5).toFixed(1);
                        return { 
                            progress: newProgress, 
                            time: elapsed,
                            speed: mockSpeed 
                        };
                    });
                }, 150);

                setTimeout(() => {
                    try {
                        logDebug('Executing startPDFGeneration...');
                        startPDFGeneration(customerName, timer);
                    } catch (e) {
                        logDebug(`CATCH ERROR: ${e.message}`);
                        console.error(e);
                        clearInterval(timer);
                        setIsGenerating(false);
                        alert('An error occurred while preparing the PDF.');
                    }
                }, 1000);
            };

            const getBase64Image = (url) => {
                return new Promise((resolve, reject) => {
                    const img = new Image();
                    img.crossOrigin = 'Anonymous';
                    img.src = url;
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        canvas.width = img.width;
                        canvas.height = img.height;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0);
                        resolve(canvas.toDataURL('image/jpeg'));
                    };
                    img.onerror = () => reject(new Error('Could not load logo for repeating header'));
                });
            };

            const startPDFGeneration = (name, timer) => {
                logDebug('Creating PDF element in memory...');
                const element = document.createElement('div');
                element.style.width = '190mm';
                element.style.padding = '12mm';
                element.style.backgroundColor = '#ffffff';
                element.style.color = '#111827';
                element.style.fontFamily = "'Outfit', sans-serif";

                const co = data.company || {};
                const footerName = escapeHtml(co.company_name || '');
                const footerAddr = escapeHtml(co.company_address || '');
                const logoUrl = String(data.logoPath || '').replace(/"/g, '');

                element.innerHTML = `
                    <style>
                        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
                        tr { page-break-inside: avoid !important; break-inside: avoid !important; }
                        td, th { border: 1px solid #e5e7eb; word-wrap: break-word; }
                        .pdf-header-p1 { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 20px; border-bottom: 2px solid #001f3f; margin-bottom: 30px; }
                    </style>

                    <div class="pdf-header-p1">
                        <div>
                            <div style="font-size: 10px; font-weight: 700; color: #2563eb; margin-bottom: 6px; text-transform: uppercase;">Dear ${escapeHtml(name || 'Valued Customer')},</div>
                            <h1 style="font-size: 32px; font-weight: 800; margin: 0; color: #001f3f; letter-spacing: -1px; text-transform: uppercase;">PRICE LIST</h1>
                            <div style="font-size: 12px; font-weight: 700; color: #111827; margin-top: 4px;">${footerName}</div>
                            <div style="font-size: 10px; color: #6b7280; margin-top: 4px;">Ã°Å¸â€œâ€¦ Date: ${escapeHtml(new Date().toLocaleDateString())}</div>
                        </div>
                        <img src="${logoUrl}" style="max-height: 65px; max-width: 180px; object-fit: contain;" alt="" />
                    </div>

                    <table>
                        <thead>
                            <tr style="background: #001f3f;">
                                <th style="width: 6%; padding: 12px 8px; text-align: center; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">#</th>
                                <th style="width: 14%; padding: 12px 8px; text-align: left; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">Photo</th>
                                <th style="width: 25%; padding: 12px 8px; text-align: left; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">Product</th>
                                <th style="width: 35%; padding: 12px 8px; text-align: left; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">Description</th>
                                <th style="width: 20%; padding: 12px 8px; text-align: right; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">Price (${escapeHtml(data.currency)})</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${products.map((p, i) => `
                                <tr>
                                    <td style="padding: 10px 8px; font-size: 11px; color: #6b7280; text-align: center;">${i + 1}</td>
                                    <td style="padding: 10px 8px;">
                                        <div style="width: 48px; height: 48px; border-radius: 4px; overflow: hidden; background: #f9fafb; margin: 0 auto;">
                                            <img src="${p.main_image ? `/stock/uploads/products/${p.id}/medium/${p.main_image}` : placeholderImg}" 
                                                 style="width: 100%; height: 100%; object-fit: cover;" />
                                        </div>
                                    </td>
                                    <td style="padding: 10px 8px; font-size: 12px; font-weight: 700; color: #111827; vertical-align: middle;">${escapeHtml(p.name)}</td>
                                    <td style="padding: 10px 8px; font-size: 11px; color: #4b5563; vertical-align: middle;">${escapeHtml(p.description || 'Ã¢â‚¬â€')}</td>
                                    <td style="padding: 10px 8px; text-align: right; font-size: 12px; font-weight: 800; color: #111827; vertical-align: middle;">
                                        ${formatPlainNumber(p.edited_price)}
                                        ${p.edited_price !== p.selling_price ? `<div style="font-size: 9px; color: #d97706; font-weight: 500; margin-top: 2px;">Was ${formatPlainNumber(p.selling_price)}</div>` : ''}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>

                    <div style="margin-top: 80px; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 40px; page-break-inside: avoid; break-inside: avoid;">
                        <h2 style="font-size: 20px; color: #001f3f; margin: 0 0 12px 0; font-weight: 800; text-transform: uppercase; letter-spacing: -0.5px;">Thank You for Choosing ${footerName}!</h2>
                        <p style="font-size: 13px; color: #4b5563; line-height: 1.8; max-width: 550px; margin: 0 auto; font-style: italic;">
                            "We are honored to be your partner. Our commitment is to deliver excellence through quality products and dedicated service. 
                            We look forward to building a lasting relationship and contributing to your success."
                        </p>
                        <div style="margin-top: 20px; display: flex; justify-content: center; gap: 20px; color: #2563eb; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 2px;">
                            <span>Ã¢â€”Â PREMIUM QUALITY</span>
                            <span>Ã¢â€”Â RELIABLE SERVICE</span>
                            <span>Ã¢â€”Â EXPERT SUPPORT</span>
                        </div>
                    </div>

                    <div style="margin-top: 60px; display: flex; justify-content: space-between; align-items: flex-end; page-break-inside: avoid; break-inside: avoid;">
                        <div style="text-align: center;">
                            <div style="font-size: 11px; font-weight: 800; color: #111827; text-transform: uppercase;">${footerName}</div>
                            <div style="font-size: 10px; color: #6b7280; margin-top: 4px;">Ã°Å¸â€œÂ ${footerAddr}</div>
                        </div>
                        <div style="text-align: center; width: 220px;">
                            <div style="font-size: 10px; color: #6b7280; margin-bottom: 5px; text-transform: uppercase; font-weight: 600;">Sales Representative</div>
                            <div style="height: 45px; display: flex; align-items: center; justify-content: center;">
                                ${data.currentUser && data.currentUser.signature_path ? `
                                    <img src="/${data.currentUser.signature_path}" style="max-height: 45px; max-width: 160px; object-fit: contain;" />
                                ` : `
                                    <div style="width: 100%; border-bottom: 1px solid #e5e7eb; margin-top: 30px;"></div>
                                `}
                            </div>
                            <div style="border-top: 2px solid #001f3f; margin-top: 5px; padding-top: 8px;">
                                <div style="font-size: 12px; font-weight: 800; color: #111827;">${escapeHtml(data.currentUser ? data.currentUser.full_name : 'Authorized Signatory')}</div>
                            </div>
                        </div>
                    </div>
                `;

                const opt = {
                    margin: [30, 10, 15, 10], // Further increased top margin
                    filename: `PriceList_${new Date().toISOString().split('T')[0]}.pdf`,
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 1.5, useCORS: true, logging: false },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'] }
                };

                logDebug('Starting html2pdf pipeline...');
                html2pdf().set(opt).from(element).toPdf().get('pdf').then(async (pdf) => {
                    logDebug('Post-processing PDF pages for headers...');
                    const totalPages = pdf.internal.getNumberOfPages();
                    
                    let logoBase64 = null;
                    try {
                        logoBase64 = await getBase64Image(logoUrl);
                        logDebug('Logo converted to base64 successfully');
                    } catch (err) {
                        logDebug(`Warning: Logo repeat failed - ${err.message}`);
                    }

                    for (let i = 1; i <= totalPages; i++) {
                        pdf.setPage(i);
                        
                        // ONLY add the repeating header to pages 2 and above
                        // Page 1 already has the unique HTML header with the logo
                        if (i > 1) {
                            // Header background / line
                            pdf.setDrawColor(229, 231, 235);
                            pdf.setLineWidth(0.2);
                            pdf.line(10, 20, 200, 20);

                            // Repeating Logo (Top Right)
                            if (logoBase64) {
                                pdf.addImage(logoBase64, 'JPEG', 165, 5, 35, 12, undefined, 'FAST');
                            }

                            // Small repeating title (Top Left)
                            pdf.setFontSize(8);
                            pdf.setTextColor(37, 99, 235);
                            pdf.setFont('helvetica', 'bold');
                            pdf.text('OFFICIAL PRICE LIST', 10, 10);
                            
                            pdf.setFontSize(7);
                            pdf.setTextColor(107, 114, 128);
                            pdf.setFont('helvetica', 'normal');
                            pdf.text(`${footerName} | Page ${i} of ${totalPages}`, 10, 14);
                        } else {
                            // Optional: Just add page numbering to the bottom of page 1 if needed
                            // But for now, we leave the top clean as page 1 has its own logo
                        }
                    }
                    logDebug('Headers added to all pages');
                }).save().then(() => {
                    logDebug('PDF successfully saved/downloaded');
                    clearInterval(timer);
                    setGenStats(s => ({ ...s, progress: 100 }));
                    setTimeout(() => {
                        setIsGenerating(false);
                        setGenStats({ progress: 0, time: 0, speed: 0 });
                    }, 1000);
                }).catch(err => {
                    logDebug(`PDF SAVE ERROR: ${err.message || err}`);
                    clearInterval(timer);
                    setIsGenerating(false);
                    setGenStats({ progress: 0, time: 0, speed: 0 });
                    alert('Failed to generate PDF. The list might be too large.');
                });
            };

            const placeholderImg = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2248%22 height=%2248%22 viewBox=%220 0 24 24%22 fill=%22%239ca3af%22%3E%3Cpath d=%22M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z%22/%3E%3C/svg%3E';

            const startIdx = filteredProducts.length === 0 ? 0 : (Math.min(page, totalPages) - 1) * pageSize + 1;
            const endIdx = filteredProducts.length === 0 ? 0 : Math.min(filteredProducts.length, Math.min(page, totalPages) * pageSize);

            return (
                <div className="max-w-[1440px] mx-auto animate-fade-in px-4 lg:px-10 pb-12 pt-2">
                    <nav className="no-print text-sm text-gray-500 mb-5 flex items-center gap-2 flex-wrap">
                        <a href="dashboard/index.php?module=sales" className="hover:text-[#2563EB] transition-colors">Sales</a>
                        <span className="text-gray-300">&gt;</span>
                        <span className="text-gray-900 font-medium">Price list</span>
                    </nav>

                    <div className="no-print flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6 mb-8">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h1 className="text-3xl font-bold text-gray-900 tracking-tight">Price list</h1>
                                <a href="settings/index.php" className="text-gray-400 hover:text-violet-600 transition-colors p-1" title="Sales settings">
                                    <i className="fas fa-cog text-lg"></i>
                                </a>
                            </div>
                            <p className="text-gray-500 mt-2 text-base">Browse all products and their unit prices.</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2 shrink-0">
                            <button type="button" onClick={resetPrices} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-gray-200 bg-white text-gray-700 font-medium hover:bg-gray-50 text-sm shadow-sm">
                                <i className="fas fa-rotate-left text-gray-500"></i> Reset prices
                            </button>
                            <button type="button" onClick={() => setIsModalOpen(true)} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-[#2563EB] hover:bg-[#1D4ED8] text-white font-semibold text-sm shadow-sm">
                                <i className="fas fa-download"></i> Download PDF
                            </button>
                            <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-gray-200 bg-white text-gray-700 font-medium hover:bg-gray-50 text-sm shadow-sm">
                                <i className="fas fa-print text-gray-500"></i> Print
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8 no-print">
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                            <div className="w-14 h-14 rounded-xl bg-violet-100 text-violet-600 flex items-center justify-center shrink-0">
                                <i className="fas fa-cubes text-xl"></i>
                            </div>
                            <div>
                                <div className="text-2xl font-bold text-gray-900 tabular-nums">{dashboardStats.totalProducts}</div>
                                <div className="text-sm text-gray-500 font-medium">Total Products</div>
                            </div>
                        </div>
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                            <div className="w-14 h-14 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
                                <i className="fas fa-tag text-xl"></i>
                            </div>
                            <div>
                                <div className="text-2xl font-bold text-gray-900 tabular-nums">{dashboardStats.pricedItems}</div>
                                <div className="text-sm text-gray-500 font-medium">Priced Items</div>
                            </div>
                        </div>
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                            <div className="w-14 h-14 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                                <i className="fas fa-coins text-xl"></i>
                            </div>
                            <div className="min-w-0">
                                <div className="text-xl font-bold text-gray-900 tabular-nums truncate">{formatMoneyDashboard(dashboardStats.totalValue)}</div>
                                <div className="text-sm text-gray-500 font-medium">Total Value (Approx.)</div>
                            </div>
                        </div>
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                            <div className="w-14 h-14 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                                <i className="fas fa-calendar-days text-xl"></i>
                            </div>
                            <div>
                                <div className="text-xl font-bold text-gray-900">{dashboardStats.lastLabel}</div>
                                <div className="text-sm text-gray-500 font-medium">Last Updated</div>
                            </div>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-5 mb-6 no-print">
                        <div className="flex flex-col xl:flex-row gap-3 xl:items-center">
                            <div className="relative flex-1 min-w-[220px]">
                                <i className="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search name, code, description..."
                                    className="w-full pl-10 pr-3 py-2.5 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-200 focus:border-violet-400 focus:bg-white"
                                />
                            </div>
                            <div className="flex flex-wrap gap-2 xl:shrink-0">
                                <select
                                    value={categoryFilter}
                                    onChange={(e) => setCategoryFilter(e.target.value)}
                                    className="text-sm border border-gray-200 rounded-lg px-3 py-2.5 bg-white text-gray-700 min-w-[160px] focus:outline-none focus:ring-2 focus:ring-violet-200 focus:border-violet-400"
                                >
                                    <option value="">All Categories</option>
                                    {categoryOptions.map(c => (
                                        <option key={c} value={c}>{c}</option>
                                    ))}
                                </select>
                                <select
                                    value={brandFilter}
                                    onChange={(e) => setBrandFilter(e.target.value)}
                                    className="text-sm border border-gray-200 rounded-lg px-3 py-2.5 bg-white text-gray-700 min-w-[140px] focus:outline-none focus:ring-2 focus:ring-violet-200 focus:border-violet-400"
                                >
                                    <option value="">All Brands</option>
                                    {brandOptions.map(b => (
                                        <option key={b} value={b}>{b}</option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    onClick={() => setShowMoreFilters(v => !v)}
                                    className={`inline-flex items-center gap-2 text-sm font-medium px-3 py-2.5 rounded-lg border transition-colors ${showMoreFilters ? 'border-violet-300 bg-violet-50 text-violet-800' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'}`}
                                >
                                    <i className="fas fa-filter"></i> More Filters
                                </button>
                            </div>
                        </div>
                        {showMoreFilters && (
                            <div className="mt-4 pt-4 border-t border-gray-100 flex flex-wrap gap-3 items-end">
                                <div className="min-w-[120px]">
                                    <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Min price</label>
                                    <input type="number" step="any" value={minPrice} onChange={(e) => setMinPrice(e.target.value)} placeholder="0" className="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-violet-200" />
                                </div>
                                <div className="min-w-[120px]">
                                    <label className="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Max price</label>
                                    <input type="number" step="any" value={maxPrice} onChange={(e) => setMaxPrice(e.target.value)} placeholder="Any" className="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-violet-200" />
                                </div>
                            </div>
                        )}
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            {activeOnly && (
                                <span className="inline-flex items-center gap-2 pl-3 pr-2 py-1.5 rounded-full text-sm bg-violet-50 text-violet-800 border border-violet-100">
                                    Active items only
                                    <button type="button" className="w-7 h-7 rounded-full hover:bg-violet-100 flex items-center justify-center text-violet-600" onClick={() => setActiveOnly(false)} aria-label="Remove filter">
                                        <i className="fas fa-times text-xs"></i>
                                    </button>
                                </span>
                            )}
                            {!activeOnly && (
                                <button type="button" onClick={() => setActiveOnly(true)} className="text-sm text-violet-700 font-medium hover:underline">
                                    Show active items only
                                </button>
                            )}
                            {(search || categoryFilter || brandFilter || minPrice || maxPrice || !activeOnly) && (
                                <button type="button" onClick={clearAllFilters} className="text-sm font-medium text-gray-500 hover:text-[#2563EB] ml-1">
                                    Clear all
                                </button>
                            )}
                        </div>
                    </div>

                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                        {filteredProducts.length === 0 ? (
                            <div className="py-24 text-center px-4">
                                <i className="fas fa-tags text-5xl text-gray-200 mb-4"></i>
                                <p className="text-gray-700 font-semibold text-lg">No products found</p>
                                <p className="text-gray-400 text-sm mt-2">Try adjusting search or filters.</p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left border-collapse min-w-[900px]">
                                        <thead>
                                            <tr className="bg-gray-50/90 border-b border-gray-200 text-xs font-bold text-gray-500 uppercase tracking-wider">
                                                <th className="w-14 px-4 py-3.5 text-center">#</th>
                                                <th className="w-24 px-4 py-3.5">Image</th>
                                                <th className="px-4 py-3.5 min-w-[200px]">Product Details</th>
                                                <th className="px-4 py-3.5 whitespace-nowrap">Code</th>
                                                <th className="px-4 py-3.5">Category</th>
                                                <th className="px-4 py-3.5 text-right whitespace-nowrap">Unit Price ({data.currency})</th>
                                                <th className="w-14 px-4 py-3.5 text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100">
                                            {paginatedProducts.map((p, i) => {
                                                const idx = (Math.min(page, totalPages) - 1) * pageSize + i + 1;
                                                return (
                                                    <tr key={p.id} className="hover:bg-violet-50/40 transition-colors">
                                                        <td className="px-4 py-3 text-sm text-gray-500 text-center align-middle tabular-nums">{idx}</td>
                                                        <td className="px-4 py-3 align-middle">
                                                            <div className="w-12 h-12 rounded-lg border border-gray-200 bg-gray-50 overflow-hidden">
                                                                <img
                                                                    src={p.main_image ? `/stock/uploads/products/${p.id}/medium/${p.main_image}` : placeholderImg}
                                                                    className="w-full h-full object-cover"
                                                                    onError={(e) => { e.target.src = placeholderImg; }}
                                                                    alt=""
                                                                />
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 align-middle">
                                                            <div className="font-semibold text-gray-900">{p.name}</div>
                                                            <div className="text-sm text-gray-500 mt-1 line-clamp-2 max-w-lg" title={p.description || ''}>{p.description || 'â€”'}</div>
                                                        </td>
                                                        <td className="px-4 py-3 align-middle">
                                                            <span className="text-sm font-semibold text-[#2563EB]">{p.product_code || 'â€”'}</span>
                                                        </td>
                                                        <td className="px-4 py-3 align-middle text-sm text-gray-700">{(p.category_name || '').trim() || 'â€”'}</td>
                                                        <td className="px-4 py-3 text-right align-middle">
                                                            <input
                                                                type="number"
                                                                step="any"
                                                                value={p.edited_price}
                                                                onChange={(e) => handlePriceChange(p.id, e.target.value)}
                                                                className={
                                                                    'w-full max-w-[9rem] ml-auto block text-right text-base font-bold text-[#2563EB] bg-transparent border-b border-transparent hover:border-gray-200 focus:border-[#2563EB] focus:outline-none px-1 py-0.5 rounded tabular-nums ' +
                                                                    (p.edited_price !== p.selling_price ? 'ring-1 ring-amber-200 bg-amber-50/80 rounded px-2 py-1 border-b-0' : '')
                                                                }
                                                            />
                                                            {p.edited_price !== p.selling_price && (
                                                                <div className="text-xs text-amber-600 font-medium mt-1">Was {formatCurrency(p.selling_price)}</div>
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 align-middle text-center relative">
                                                            <div className="inline-block relative" data-pl-row-menu>
                                                                <button
                                                                    type="button"
                                                                    className="w-9 h-9 rounded-lg border border-transparent text-gray-500 hover:bg-gray-100 hover:text-gray-800 inline-flex items-center justify-center"
                                                                    onClick={(e) => {
                                                                        e.stopPropagation();
                                                                        setMenuRowId(menuRowId === p.id ? null : p.id);
                                                                    }}
                                                                    aria-label="Row actions"
                                                                >
                                                                    <i className="fas fa-ellipsis-v"></i>
                                                                </button>
                                                                {menuRowId === p.id && (
                                                                    <div className="absolute right-0 mt-1 w-44 bg-white border border-gray-200 rounded-lg shadow-lg z-40 py-1 text-left">
                                                                        <button type="button" className="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50" onClick={() => resetRowPrice(p.id)}>
                                                                            Reset row price
                                                                        </button>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 px-4 py-4 border-t border-gray-100 bg-gray-50/50 no-print">
                                    <p className="text-sm text-gray-600">
                                        Showing <span className="font-semibold text-gray-900">{startIdx}</span> to <span className="font-semibold text-gray-900">{endIdx}</span> of{' '}
                                        <span className="font-semibold text-gray-900">{filteredProducts.length}</span> products
                                    </p>
                                    <div className="flex flex-wrap items-center gap-3 justify-end">
                                        <div className="flex items-center gap-1">
                                            <button type="button" disabled={Math.min(page, totalPages) <= 1} onClick={() => setPage(p => Math.max(1, p - 1))} className="w-9 h-9 rounded-lg border border-gray-200 bg-white text-gray-600 disabled:opacity-40 hover:bg-gray-100 inline-flex items-center justify-center">
                                                <i className="fas fa-chevron-left text-xs"></i>
                                            </button>
                                            {visiblePageNumbers.map((item, idx) => (
                                                item === 'ellipsis' ? (
                                                    <span key={`ellipsis-${idx}`} className="px-2 text-gray-400">...</span>
                                                ) : (
                                                    <button
                                                        key={`page-${item}-${idx}`}
                                                        type="button"
                                                        onClick={() => setPage(item)}
                                                        className={`min-w-[2.25rem] h-9 px-2 rounded-lg text-sm font-semibold inline-flex items-center justify-center ${Math.min(page, totalPages) === item ? 'bg-[#2563EB] text-white shadow-sm' : 'text-gray-600 hover:bg-white border border-transparent hover:border-gray-200'}`}
                                                    >
                                                        {item}
                                                    </button>
                                                )
                                            ))}
                                            <button type="button" disabled={Math.min(page, totalPages) >= totalPages} onClick={() => setPage(p => Math.min(totalPages, p + 1))} className="w-9 h-9 rounded-lg border border-gray-200 bg-white text-gray-600 disabled:opacity-40 hover:bg-gray-100 inline-flex items-center justify-center">
                                                <i className="fas fa-chevron-right text-xs"></i>
                                            </button>
                                        </div>
                                        <select value={pageSize} onChange={(e) => setPageSize(Number(e.target.value))} className="text-sm border border-gray-200 rounded-lg px-2 py-2 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-violet-200">
                                            {[10, 25, 50, 100].map(n => (
                                                <option key={n} value={n}>{n} / page</option>
                                            ))}
                                        </select>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>

                    {isModalOpen && (
                        <div className="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-black/60 no-print" style={{ position: 'fixed', top: 0, left: 0, right: 0, bottom: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(0,0,0,0.6)' }}>
                            <div className="bg-white shadow-2xl w-full max-w-md p-8" style={{ backgroundColor: 'white', borderRadius: '12px', position: 'relative', border: '1px solid #e5e7eb', boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)' }}>
                                <div className="flex justify-between items-center mb-6">
                                    <h3 className="text-2xl font-bold text-gray-900">Prepare PDF</h3>
                                    <button type="button" onClick={() => setIsModalOpen(false)} className="text-gray-400 hover:text-gray-600">
                                        <i className="fas fa-times text-xl"></i>
                                    </button>
                                </div>
                                
                                <div className="mb-6">
                                    <label className="block text-sm font-bold text-gray-700 mb-2 uppercase tracking-wide">Client Name</label>
                                    <input
                                        type="text"
                                        placeholder="Enter customer name..."
                                        value={customerName}
                                        onChange={(e) => setCustomerName(e.target.value)}
                                        className="w-full px-4 py-3 text-lg border-2 border-gray-100 rounded-lg focus:border-[#2563EB] focus:outline-none transition-colors"
                                        autoFocus
                                    />
                                    <p className="text-xs text-gray-500 mt-2 italic">This will appear at the top of the price list.</p>
                                </div>

                                <div className="flex gap-3">
                                    <button type="button" onClick={() => setIsModalOpen(false)} className="flex-1 py-3 text-gray-600 font-semibold hover:bg-gray-50 rounded-lg transition-colors">
                                        Cancel
                                    </button>
                                    <button type="button" onClick={generatePDF} className="flex-[2] bg-[#2563EB] hover:bg-[#1D4ED8] text-white py-3 rounded-lg font-bold shadow-lg shadow-blue-500/30 transition-all active:scale-95">
                                        Download PDF
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {isGenerating && (
                        <div className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-gray-900/95 backdrop-blur-sm no-print">
                            <div className="w-full max-w-md p-8 text-center">
                                <div className="relative mb-8">
                                    <div className="w-24 h-24 border-4 border-white/10 border-t-[#2563EB] rounded-full animate-spin mx-auto"></div>
                                    <div className="absolute inset-0 flex items-center justify-center text-[#2563EB] font-bold text-lg">
                                        {Math.floor(genStats.progress)}%
                                    </div>
                                </div>
                                
                                <h2 className="text-2xl font-bold text-white mb-2">Generating Price List</h2>
                                <p className="text-gray-400 mb-8">Compiling {products.length} products with images...</p>
                                
                                <div className="bg-white/5 rounded-xl p-6 border border-white/10 mb-6">
                                    <div className="grid grid-cols-3 gap-4">
                                        <div>
                                            <div className="text-xs text-gray-500 uppercase font-bold mb-1">Time Elapsed</div>
                                            <div className="text-xl font-mono text-white">{genStats.time}s</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-gray-500 uppercase font-bold mb-1">Process Speed</div>
                                            <div className="text-xl font-mono text-white">{genStats.speed} MB/s</div>
                                        </div>
                                        <div>
                                            <div className="text-xs text-gray-500 uppercase font-bold mb-1">Status</div>
                                            <div className="text-xl font-mono text-green-400">ACTIVE</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div className="w-full bg-white/10 rounded-full h-2 mb-2 overflow-hidden">
                                    <div 
                                        className="bg-[#2563EB] h-full transition-all duration-300 ease-out shadow-[0_0_15px_rgba(37,99,235,0.5)]" 
                                        style={{ width: `${genStats.progress}%` }}
                                    ></div>
                                </div>
                                <div className="flex justify-between text-xs font-medium">
                                    <span className="text-gray-500">OPTIMIZING ASSETS</span>
                                    <span className="text-[#2563EB]">{Math.floor(genStats.progress)}% COMPLETE</span>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('react-root'));
        root.render(<PriceListApp />);
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</body>
</html>

