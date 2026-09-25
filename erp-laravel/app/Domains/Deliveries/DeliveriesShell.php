<?php

declare(strict_types=1);

namespace App\Domains\Deliveries;

/**
 * Deliveries React shell: Vite dist under deliveries/deliveries-ui/frontend + ERP Blade chrome.
 * Pages: hub, dashboard, create-delivery, order-details, delivery-notes, create-delivery-note.
 */
final class DeliveriesShell
{
    /**
     * @param array<string,mixed> $cfg
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string,
     *   employeeHeaderTitle:?string,
     *   hideHeaderCompanyBranding:bool,
     *   hideSidebar:bool,
     *   employeeHeaderExtraClass:string,
     *   employeeHeaderCenterHtml:?string,
     *   employeeHeaderRightHtml:?string,
     *   mainRootClass:string,
     *   notFound?:bool,
     *   error?:string
     * }|null
     */
    public function viewData(array $cfg = []): ?array
    {
        $assets = $this->loadAssets();
        if ($assets === null) {
            return null;
        }

        $erp = is_array($cfg['erp'] ?? null) ? $cfg['erp'] : [];
        $page = strtolower(trim((string) ($cfg['page'] ?? 'hub')));
        if ($page === 'create_delivery' || $page === 'create') {
            $page = 'create-delivery';
        }
        if ($page === 'order_details' || $page === 'order') {
            $page = 'order-details';
        }
        if ($page === 'delivery_notes' || $page === 'notes') {
            $page = 'delivery-notes';
        }
        if ($page === 'create_delivery_note' || $page === 'create-note') {
            $page = 'create-delivery-note';
        }
        if (!in_array($page, ['hub', 'dashboard', 'create-delivery', 'order-details', 'delivery-notes', 'create-delivery-note'], true)) {
            $page = 'hub';
        }

        $slug = (string) ($erp['company_slug'] ?? ($cfg['companySlug'] ?? ''));
        $createDispatch = !empty($cfg['createDispatch']) || !empty($_GET['create_dispatch']);
        $orderId = (int) ($cfg['orderId'] ?? ($_GET['order_id'] ?? 0));
        $urls = $this->pageUrls($slug, $createDispatch);

        if ($page === 'order-details') {
            $orderBoot = $this->buildOrderDetailsBoot($assets, $urls, $orderId);
            if (!empty($orderBoot['notFound'])) {
                return [
                    'notFound' => true,
                    'error' => (string) ($orderBoot['error'] ?? 'Order not found.'),
                    'pageTitle' => 'Order Not Found',
                    'bodyClass' => '',
                    'headMarkup' => '',
                    'footerScripts' => '',
                    'employeeHeaderTitle' => null,
                    'hideHeaderCompanyBranding' => true,
                    'hideSidebar' => false,
                    'employeeHeaderExtraClass' => '',
                    'employeeHeaderCenterHtml' => null,
                    'employeeHeaderRightHtml' => null,
                    'mainRootClass' => '',
                ];
            }
            $boot = $orderBoot['boot'];
            $orderRef = (string) ($orderBoot['orderRef'] ?? '');
        } else {
            $boot = $this->buildBoot($page, $assets, $erp, $urls, $createDispatch);
            $orderRef = '';
        }

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $bootJson = json_encode(
            $boot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        );
        if ($bootJson === false) {
            $bootJson = '{"page":"' . $page . '","data":{"urls":{}}}';
        }

        $chromeCss = match ($page) {
            'dashboard' => $this->dashboardChromeCss(),
            'create-delivery' => $this->createChromeCss(),
            'create-delivery-note' => $this->createDeliveryNoteChromeCss(),
            'order-details' => $this->orderDetailsChromeCss(),
            'delivery-notes' => $this->deliveryNotesChromeCss(),
            default => $this->hubChromeCss(),
        };

        $headMarkup = $this->commonHeadExtras()
            . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . $chromeCss . "\n"
            . '<script>window.__DELIVERIES_CFG__ = ' . $bootJson . ';</script>';

        if ($page === 'order-details') {
            $headMarkup .= "\n"
                . '<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>';
        }

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        $isDashboard = $page === 'dashboard';
        $isCreate = $page === 'create-delivery';
        $isCreateNote = $page === 'create-delivery-note';
        $isOrderDetails = $page === 'order-details';
        $isDeliveryNotes = $page === 'delivery-notes';

        $pageTitle = match (true) {
            $isCreate => $createDispatch ? 'New dispatch' : 'New delivery',
            $isCreateNote => 'Create Delivery Note',
            $isOrderDetails => $orderRef !== '' ? ('Order ' . $orderRef) : 'Order Details',
            $isDeliveryNotes => 'Delivery Notes',
            $isDashboard => 'Dashboard',
            default => 'Delivery Logistics',
        };

        $bodyClass = match (true) {
            $isCreate => 'page-products-desk page-dlv-create page-dlv-laravel page-erp-laravel',
            $isCreateNote => 'page-products-desk page-create-delivery-note page-dlv-laravel page-erp-laravel',
            $isOrderDetails => 'page-products-desk page-dlv-order-details page-dlv-laravel page-erp-laravel',
            $isDeliveryNotes => 'page-products-desk page-dlv-notes page-dlv-laravel page-erp-laravel',
            $isDashboard => 'page-products-desk page-dlv-dashboard page-dlv-laravel page-erp-laravel',
            default => 'page-products-desk page-dlv-hub page-dlv-laravel page-erp-laravel',
        };

        $createNoteUrl = (string) (
            ($boot['data']['urls']['createNote'] ?? null)
            ?: ($urls['createDeliveryNote'] ?? '')
        );

        return [
            'pageTitle' => $pageTitle,
            'bodyClass' => $bodyClass,
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => match (true) {
                $isCreate, $isCreateNote, $isOrderDetails => '',
                $isDeliveryNotes => 'Delivery Notes',
                $isDashboard => 'Dashboard',
                default => 'Delivery Logistics',
            },
            'hideHeaderCompanyBranding' => true,
            'hideSidebar' => $page === 'hub',
            'employeeHeaderExtraClass' => 'employee-header--deliveries',
            'employeeHeaderCenterHtml' => ($isDashboard || $isDeliveryNotes)
                ? '<div id="dlv-header-search-mount" class="dlv-header-search-mount"></div>'
                : null,
            'employeeHeaderRightHtml' => match (true) {
                $isDashboard => '<div id="dlv-header-actions-mount" class="dlv-header-actions-mount"></div>',
                $isDeliveryNotes => $this->deliveryNotesCreateButtonHtml($createNoteUrl),
                default => null,
            },
            'mainRootClass' => match (true) {
                $isCreate => 'create-delivery-react-root dlv-react-root',
                $isCreateNote => 'create-delivery-note-react-root dlv-react-root',
                $isOrderDetails => 'order-details-react-root dlv-react-root',
                default => 'dlv-react-root',
            },
        ];
    }

    /**
     * @param array<string,mixed> $assets
     * @param array<string,mixed> $erp
     * @param array<string,string> $urls
     * @return array<string,mixed>
     */
    private function buildBoot(string $page, array $assets, array $erp, array $urls, bool $createDispatch): array
    {
        if ($page === 'create-delivery') {
            $data = $this->loadCreatePayloadData($urls, $createDispatch);
            return [
                'page' => 'create-delivery',
                'createInitUrl' => $assets['createInitUrl'] ?? '',
                'createSubmitUrl' => $assets['createSubmitUrl'] ?? '',
                'createDispatch' => $createDispatch,
                'data' => $data,
            ];
        }

        if ($page === 'create-delivery-note') {
            $data = $this->loadCreateDeliveryNotePayloadData($urls);
            return [
                'page' => 'create-delivery-note',
                'createNoteInitUrl' => $assets['createNoteInitUrl'] ?? '',
                'createNoteSubmitUrl' => $assets['createNoteSubmitUrl'] ?? '',
                'data' => $data,
            ];
        }

        if ($page === 'delivery-notes') {
            $data = $this->loadDeliveryNotesPayloadData($urls);
            return [
                'page' => 'delivery-notes',
                'deliveryNotesInitUrl' => $assets['deliveryNotesInitUrl'] ?? '',
                'aiSearchNotesUrl' => $assets['aiSearchNotesUrl'] ?? '',
                'kpiAiAssistUrl' => $assets['kpiAiAssistUrl'] ?? '',
                'data' => $data,
            ];
        }

        if ($page === 'dashboard') {
            $data = $this->loadDashboardPayloadData($urls, $erp);
            return [
                'page' => 'dashboard',
                'apiUrl' => $assets['apiUrl'] ?? '',
                'actionUrl' => $assets['actionUrl'] ?? '',
                'aiSearchUrl' => $assets['aiSearchUrl'] ?? '',
                'kpiAiAssistUrl' => $assets['kpiAiAssistUrl'] ?? '',
                'createInitUrl' => $assets['createInitUrl'] ?? '',
                'createSubmitUrl' => $assets['createSubmitUrl'] ?? '',
                'data' => $data,
            ];
        }

        return [
            'page' => $page,
            'apiUrl' => $assets['apiUrl'] ?? '',
            'actionUrl' => $assets['actionUrl'] ?? '',
            'aiSearchUrl' => $assets['aiSearchUrl'] ?? '',
            'kpiAiAssistUrl' => $assets['kpiAiAssistUrl'] ?? '',
            'createInitUrl' => $assets['createInitUrl'] ?? '',
            'createSubmitUrl' => $assets['createSubmitUrl'] ?? '',
            'data' => [
                'companyName' => (string) ($erp['company_name'] ?? ''),
                'urls' => $urls,
            ],
        ];
    }

    /**
     * @param array<string,string> $urls
     * @param array<string,mixed> $erp
     * @return array<string,mixed>
     */
    private function loadDashboardPayloadData(array $urls, array $erp): array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $dbFile = $root . '/deliveries/config/database.php';
        $loadFile = $root . '/deliveries/deliveries-ui/load-data.php';
        if (is_file($dbFile)) {
            require_once $dbFile;
        }
        if (is_file($loadFile)) {
            require_once $loadFile;
        }

        global $pdo;
        if ((!isset($pdo) || !($pdo instanceof \PDO)) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['pdo'];
        }

        if ($pdo instanceof \PDO && function_exists('deliveries_load_dashboard_payload')) {
            $query = is_array($_GET) ? $_GET : [];
            $query['skip_ai'] = 1;
            $payload = deliveries_load_dashboard_payload($pdo, $query);
            if (is_array($payload['data'] ?? null)) {
                $data = $payload['data'];
                if (!isset($data['urls']) || !is_array($data['urls'])) {
                    $data['urls'] = $urls;
                } else {
                    $data['urls'] = array_merge($urls, $data['urls']);
                }
                if (empty($data['companyName'])) {
                    $data['companyName'] = (string) ($erp['company_name'] ?? '');
                }
                return $data;
            }
        }

        return [
            'stats' => [
                'activeTrips' => 0,
                'pending' => 0,
                'performanceScore' => 0,
            ],
            'trips' => [],
            'orders' => [],
            'kpiTraces' => [],
            'performance' => null,
            'companyName' => (string) ($erp['company_name'] ?? ''),
            'urls' => $urls,
            'flash' => null,
        ];
    }

    private function deliveryNotesCreateButtonHtml(string $createNoteUrl): string
    {
        $href = htmlspecialchars($createNoteUrl !== '' ? $createNoteUrl : '#', ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" class="dlv-header-create-btn">'
            . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">'
            . '<path d="M12 5v14M5 12h14"/></svg>'
            . '<span>New Note</span></a>';
    }

    /**
     * @param array<string,string> $urls
     * @return array<string,mixed>
     */
    private function loadDeliveryNotesPayloadData(array $urls): array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $dbFile = $root . '/deliveries/config/database.php';
        $loadFile = $root . '/deliveries/deliveries-ui/load-data.php';
        if (is_file($dbFile)) {
            require_once $dbFile;
        }
        if (is_file($loadFile)) {
            require_once $loadFile;
        }

        global $pdo;
        if ((!isset($pdo) || !($pdo instanceof \PDO)) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['pdo'];
        }

        if ($pdo instanceof \PDO && function_exists('deliveries_load_delivery_notes_payload')) {
            $payload = deliveries_load_delivery_notes_payload($pdo, $_GET);
            if (is_array($payload['data'] ?? null)) {
                $data = $payload['data'];
                if (!isset($data['urls']) || !is_array($data['urls'])) {
                    $data['urls'] = $urls;
                } else {
                    $data['urls'] = array_merge($urls, $data['urls']);
                }
                if (empty($data['urls']['createNote']) && !empty($urls['createDeliveryNote'])) {
                    $data['urls']['createNote'] = $urls['createDeliveryNote'];
                }
                if (empty($data['urls']['viewNote']) && !empty($urls['viewDeliveryNote'])) {
                    $data['urls']['viewNote'] = $urls['viewDeliveryNote'];
                }
                return $data;
            }
        }

        return [
            'notes' => [],
            'stats' => [
                'total' => 0,
                'thisMonth' => 0,
                'thisWeek' => 0,
                'customers' => 0,
            ],
            'kpiTraces' => [],
            'urls' => array_merge($urls, [
                'createNote' => $urls['createDeliveryNote'] ?? '',
                'viewNote' => $urls['viewDeliveryNote'] ?? '',
            ]),
            'flash' => null,
        ];
    }

    /**
     * @param array<string,string> $urls
     * @return array<string,mixed>
     */
    private function loadCreateDeliveryNotePayloadData(array $urls): array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $dbFile = $root . '/deliveries/config/database.php';
        $loadFile = $root . '/deliveries/deliveries-ui/load-create-delivery-note-data.php';
        if (is_file($dbFile)) {
            require_once $dbFile;
        }
        if (is_file($loadFile)) {
            require_once $loadFile;
        }

        global $pdo;
        if ((!isset($pdo) || !($pdo instanceof \PDO)) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['pdo'];
        }

        if ($pdo instanceof \PDO && function_exists('deliveries_load_create_delivery_note_payload')) {
            $payload = deliveries_load_create_delivery_note_payload($pdo);
            if (is_array($payload['data'] ?? null)) {
                $data = $payload['data'];
                if (!isset($data['urls']) || !is_array($data['urls'])) {
                    $data['urls'] = $urls;
                } else {
                    $data['urls'] = array_merge($urls, $data['urls']);
                }
                if (empty($data['urls']['deliveryNotes']) && !empty($urls['deliveryNotes'])) {
                    $data['urls']['deliveryNotes'] = $urls['deliveryNotes'];
                }
                return $data;
            }
        }

        return [
            'customers' => [],
            'products' => [],
            'defaultDate' => date('Y-m-d'),
            'productPlaceholder' => function_exists('app_url')
                ? app_url('assets/images/no-image.png')
                : '/assets/images/no-image.png',
            'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
            'urls' => $urls,
        ];
    }

    /**
     * @param array<string,mixed> $assets
     * @param array<string,string> $urls
     * @return array{notFound?:bool,error?:string,boot?:array<string,mixed>,orderRef?:string}
     */
    private function buildOrderDetailsBoot(array $assets, array $urls, int $orderId): array
    {
        $payload = $this->loadOrderDetailsPayload($orderId, $urls);
        if (!($payload['ok'] ?? false)) {
            return [
                'notFound' => true,
                'error' => (string) ($payload['error'] ?? 'Order not found.'),
            ];
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        if (!isset($data['urls']) || !is_array($data['urls'])) {
            $data['urls'] = $urls;
        } else {
            $data['urls'] = array_merge($urls, $data['urls']);
        }

        $resolvedOrderId = (int) ($data['order']['id'] ?? $orderId);
        $orderRef = (string) ($data['order']['invoice_ref'] ?? '');

        return [
            'boot' => [
                'page' => 'order-details',
                'orderDetailsInitUrl' => $assets['orderDetailsInitUrl'] ?? '',
                'uploadEvidenceUrl' => $assets['uploadEvidenceUrl'] ?? '',
                'checkSignatureUrl' => $assets['checkSignatureUrl'] ?? '',
                'submitClientSignatureUrl' => $assets['submitClientSignatureUrl'] ?? '',
                'orderId' => $resolvedOrderId,
                'data' => $data,
            ],
            'orderRef' => $orderRef,
        ];
    }

    /**
     * @param array<string,string> $urls
     * @return array{ok:bool,error?:string,data?:array<string,mixed>}
     */
    private function loadOrderDetailsPayload(int $orderId, array $urls): array
    {
        if ($orderId <= 0) {
            return ['ok' => false, 'error' => 'Order ID is required.'];
        }

        $root = rtrim((string) config('erp.app_root'), '\\/');
        $dbFile = $root . '/deliveries/config/database.php';
        $loadFile = $root . '/deliveries/deliveries-ui/load-order-details-data.php';
        if (is_file($dbFile)) {
            require_once $dbFile;
        }
        if (is_file($loadFile)) {
            require_once $loadFile;
        }

        global $pdo;
        if ((!isset($pdo) || !($pdo instanceof \PDO)) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['pdo'];
        }

        if ($pdo instanceof \PDO && function_exists('deliveries_load_order_details_payload')) {
            $payload = deliveries_load_order_details_payload($pdo, [
                'order_id' => $orderId,
                'uploaded' => $_GET['uploaded'] ?? null,
            ]);
            if (is_array($payload)) {
                return $payload;
            }
        }

        return [
            'ok' => true,
            'data' => [
                'order' => ['id' => $orderId],
                'documents' => [],
                'evidence' => [],
                'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
                'urls' => $urls,
                'flash' => null,
            ],
        ];
    }

    /**
     * @param array<string,string> $urls
     * @return array<string,mixed>
     */
    private function loadCreatePayloadData(array $urls, bool $createDispatch): array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $dbFile = $root . '/deliveries/config/database.php';
        $loadFile = $root . '/deliveries/deliveries-ui/load-create-data.php';
        if (is_file($dbFile)) {
            require_once $dbFile;
        }
        if (is_file($loadFile)) {
            require_once $loadFile;
        }

        global $pdo;
        if ((!isset($pdo) || !($pdo instanceof \PDO)) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) {
            $pdo = $GLOBALS['pdo'];
        }
        if ($pdo instanceof \PDO && function_exists('deliveries_load_create_payload')) {
            $payload = deliveries_load_create_payload($pdo);
            if (is_array($payload['data'] ?? null)) {
                $data = $payload['data'];
                $data['createDispatch'] = $createDispatch;
                if (!isset($data['urls']) || !is_array($data['urls'])) {
                    $data['urls'] = $urls;
                } else {
                    $data['urls'] = array_merge($urls, $data['urls']);
                }
                return $data;
            }
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $department = trim((string) ($_SESSION['department'] ?? ''));
        $isDriver = function_exists('userHasAccessRole')
            ? userHasAccessRole('Driver')
            : (strcasecmp($department, 'Driver') === 0);

        return [
            'drivers' => [],
            'deliveryNotes' => [],
            'warehouses' => [],
            'invoices' => [],
            'currentUser' => [
                'id' => $userId,
                'fullName' => trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')),
                'department' => $department,
                'isDriver' => $isDriver,
            ],
            'csrfToken' => function_exists('csrf_token') ? csrf_token() : '',
            'createDispatch' => $createDispatch,
            'urls' => $urls,
            'routePricing' => [
                'baseFare' => 5000,
                'ratePerKm' => 1500,
                'minimumFare' => 8000,
                'currency' => 'TZS',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function pageUrls(string $slug, bool $createDispatch = false): array
    {
        $urls = [
            'modules' => $this->companyPath('select-module', $slug),
            'dashboard' => $this->companyPath('deliveries/index', $slug, ['module' => 'deliveries']),
            'hub' => $this->companyPath('deliveries/hub', $slug, ['module' => 'deliveries']),
            'driverKpiDelivery' => $this->companyPath('driver-kpi/index', $slug, [
                'module' => 'driver_kpi',
                'service' => 'delivery',
            ]),
            'driverKpiRide' => $this->companyPath('driver-kpi/index', $slug, [
                'module' => 'driver_kpi',
                'service' => 'ride',
            ]),
            'createDelivery' => $this->companyPath('deliveries/create_delivery', $slug, ['module' => 'deliveries']),
            'createDeliveryNote' => $this->companyPath('deliveries/create_delivery_note', $slug, ['module' => 'deliveries']),
            'myDeliveries' => $this->companyPath('deliveries/my_deliveries', $slug, ['module' => 'deliveries']),
            'deliveryNotes' => $this->companyPath('deliveries/delivery_notes', $slug, ['module' => 'deliveries']),
            'viewDeliveryNote' => $this->companyPath('deliveries/view_delivery_note', $slug, ['module' => 'deliveries']),
            'orderDetails' => $this->companyPath('deliveries/order_details', $slug, ['module' => 'deliveries']),
            'dispatchDashboard' => '',
        ];

        if ($createDispatch) {
            $dispatchHelpers = rtrim((string) config('erp.app_root'), '\\/') . '/dispatch/dispatch-helpers.php';
            if (is_file($dispatchHelpers)) {
                require_once $dispatchHelpers;
                if (function_exists('dispatch_module_url')) {
                    $urls['dispatchDashboard'] = (string) dispatch_module_url('dispatch/index.php');
                }
            }
        }

        return $urls;
    }

    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,apiUrl?:string,actionUrl?:string,aiSearchUrl?:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $legacyLib = $root . '/deliveries/deliveries-ui/lib.php';
        if (is_file($legacyLib)) {
            require_once $legacyLib;
            if (function_exists('deliveriesUiLoadReactAssets')) {
                $assets = deliveriesUiLoadReactAssets();
                if (is_array($assets)) {
                    return $assets;
                }
            }
        }

        $distRel = (string) (config('erp.react_dist.deliveries') ?: 'deliveries/deliveries-ui/frontend/dist');
        $distIndex = $root . '/' . $distRel . '/index.html';
        if (!is_file($distIndex)) {
            return null;
        }
        $distHtml = file_get_contents($distIndex) ?: '';
        preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
        preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
        $jsFile = $jsMatch[1] ?? '';
        $cssFile = $cssMatch[1] ?? '';
        if ($jsFile === '' || $cssFile === '') {
            return null;
        }
        $jsPath = $root . '/' . $distRel . '/assets/' . $jsFile;
        $cssPath = $root . '/' . $distRel . '/assets/' . $cssFile;
        if (!is_file($jsPath) || !is_file($cssPath)) {
            return null;
        }

        $assetBase = function_exists('app_url')
            ? rtrim((string) app_url('/' . $distRel . '/assets/'), '/') . '/'
            : '/public_html/' . $distRel . '/assets/';

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => (string) filemtime($cssPath),
            'jsVersion' => (string) filemtime($jsPath),
            'apiUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/init.php')
                : '/public_html/deliveries/deliveries-ui/api/init.php',
            'actionUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/action.php')
                : '/public_html/deliveries/deliveries-ui/api/action.php',
            'aiSearchUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/ai-search.php')
                : '/public_html/deliveries/deliveries-ui/api/ai-search.php',
            'createInitUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/create-init.php')
                : '/public_html/deliveries/deliveries-ui/api/create-init.php',
            'createSubmitUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/create-delivery.php')
                : '/public_html/deliveries/deliveries-ui/api/create-delivery.php',
            'orderDetailsInitUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/order-details-init.php')
                : '/public_html/deliveries/deliveries-ui/api/order-details-init.php',
            'uploadEvidenceUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/upload-evidence.php')
                : '/public_html/deliveries/deliveries-ui/api/upload-evidence.php',
            'checkSignatureUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/check-signature.php')
                : '/public_html/deliveries/deliveries-ui/api/check-signature.php',
            'submitClientSignatureUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/submit-client-signature.php')
                : '/public_html/deliveries/deliveries-ui/api/submit-client-signature.php',
            'deliveryNotesInitUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/delivery-notes-init.php')
                : '/public_html/deliveries/deliveries-ui/api/delivery-notes-init.php',
            'aiSearchNotesUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/ai-search-notes.php')
                : '/public_html/deliveries/deliveries-ui/api/ai-search-notes.php',
            'kpiAiAssistUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/kpi-ai-assist.php')
                : '/public_html/deliveries/deliveries-ui/api/kpi-ai-assist.php',
            'createNoteInitUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/create-delivery-note-init.php')
                : '/public_html/deliveries/deliveries-ui/api/create-delivery-note-init.php',
            'createNoteSubmitUrl' => function_exists('app_url')
                ? app_url('/deliveries/deliveries-ui/api/create-delivery-note.php')
                : '/public_html/deliveries/deliveries-ui/api/create-delivery-note.php',
        ];
    }

    /**
     * @param array<string,scalar> $query
     */
    private function companyPath(string $path, string $slug, array $query = []): string
    {
        if ($slug !== '' && function_exists('company_url')) {
            $url = company_url($path, $slug);
        } elseif (function_exists('app_url')) {
            $url = app_url('/' . ltrim($path, '/'));
        } else {
            $url = '/' . ltrim($path, '/');
        }
        if ($slug !== '' && !isset($query['company_slug'])) {
            $query['company_slug'] = $slug;
        }
        if ($query === []) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    private function commonHeadExtras(): string
    {
        $parts = [
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
        ];
        if (function_exists('app_url')) {
            $erpStylePath = rtrim((string) config('erp.app_root'), '\\/') . '/assets/css/style.css';
            $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
            $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
            if (function_exists('erp_dark_theme_css_url')) {
                $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
            }
        }

        return implode("\n", $parts) . "\n";
    }

    private function hubChromeCss(): string
    {
        return <<<CSS
<style>
html, body.page-dlv-hub.dashboard {
    height: 100%;
    overflow: hidden;
}
body.page-dlv-hub.dashboard .layout-main-wrapper {
    align-items: stretch !important;
    min-height: 100vh !important;
    height: 100vh !important;
    max-height: 100vh !important;
    overflow: hidden !important;
    background: #0a0d12 !important;
}
body.page-dlv-hub.dashboard .layout-main-wrapper > .flex-grow-1 {
    position: relative !important;
    min-height: 0 !important;
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
    overflow: hidden !important;
    padding: 0 !important;
    margin: 0 !important;
}
body.page-dlv-hub,
body.page-dlv-hub.dashboard {
    background: #0a0d12 !important;
}
body.page-dlv-hub .sidebar,
body.page-dlv-hub #native-sidebar {
    display: none !important;
}
body.page-dlv-hub .employee-header.employee-header--deliveries {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 5 !important;
    flex: 0 0 auto !important;
    background: transparent !important;
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
    border: none !important;
    border-bottom: none !important;
    box-shadow: none !important;
    padding: 0 1.5rem !important;
    margin: 0 !important;
    min-height: 56px !important;
    height: 56px !important;
    display: flex !important;
    align-items: center !important;
}
body.page-dlv-hub .employee-header--deliveries::after { display: none !important; }
body.page-dlv-hub .employee-header--deliveries .header-content {
    display: flex !important;
    align-items: center !important;
    width: 100%;
    padding: 0 !important;
    min-height: 56px;
    background: transparent !important;
}
body.page-dlv-hub .employee-header--deliveries .employee-header-page-title {
    color: #fff !important;
    font-size: 1.125rem !important;
    font-weight: 700 !important;
    letter-spacing: -0.02em;
    text-shadow: 0 1px 14px rgba(0,0,0,.45);
}
body.page-dlv-hub .employee-header--deliveries .header-actions-tray .notif .icon-btn,
body.page-dlv-hub .employee-header--deliveries .header-actions-tray #themeToggleBtn {
    color: #fff !important;
    filter: none;
}
body.page-dlv-hub main.main-content.dlv-react-root {
    position: absolute !important;
    inset: 0 !important;
    flex: none !important;
    width: 100% !important;
    max-width: none !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    box-sizing: border-box;
    background: transparent !important;
}
body.page-dlv-hub main.main-content.dlv-react-root #root {
    width: 100%;
    height: 100%;
    min-height: 0;
    margin: 0;
}
</style>
CSS;
    }

    private function dashboardChromeCss(): string
    {
        return <<<CSS
<style>
body.page-dlv-dashboard.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-dlv-dashboard.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-dlv-dashboard,
body.page-dlv-dashboard.dashboard,
body.page-dlv-dashboard .layout-main-wrapper,
body.page-dlv-dashboard .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}
body.page-dlv-dashboard .employee-header.employee-header--deliveries {
    background: #f1f5f9 !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
    align-items: stretch !important;
}
body.page-dlv-dashboard .employee-header--deliveries::after { display: none !important; }
body.page-dlv-dashboard .employee-header--deliveries .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.5rem 0 0 !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-dlv-dashboard .employee-header--deliveries .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount {
    order: 1;
    display: inline-flex !important;
    align-items: center;
    gap: 0.4rem;
    flex-shrink: 0;
}
body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray .notif { order: 2; }
body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray #themeToggleBtn { order: 3; }
@media (min-width: 768px) {
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount {
        display: inline-flex !important;
        gap: 0.5rem;
    }
    body.page-dlv-dashboard .employee-header--deliveries.employee-header--has-center-slot .header-content {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) minmax(280px, 440px) minmax(0, 1fr);
        align-items: center !important;
        gap: 0.75rem 1.25rem !important;
        padding: 0.45rem 0 0 !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-left { display: none !important; }
    body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-heading--with-center {
        grid-column: 1;
        justify-self: start;
    }
    body.page-dlv-dashboard .employee-header--deliveries .employee-header-center-slot {
        grid-column: 2;
        width: 100%;
        justify-content: center !important;
        min-width: 0;
        padding: 0 !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-right.header-actions-tray {
        grid-column: 3;
        justify-self: end;
        margin-left: 0 !important;
        align-self: center !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount {
        width: 100%;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-field {
        position: relative !important;
        display: flex !important;
        align-items: center !important;
        width: 100% !important;
        min-width: 0 !important;
        border-radius: 9999px !important;
        background: #fff !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: none !important;
        overflow: visible !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-field:focus-within {
        border-color: #a5b4fc !important;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12) !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-icon {
        position: absolute !important;
        left: 14px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        color: #94a3b8 !important;
        pointer-events: none !important;
        z-index: 1 !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-input,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount input.dlv-search-input[type="search"] {
        width: 100% !important;
        height: 40px !important;
        margin: 0 !important;
        padding: 0.55rem 2.75rem 0.55rem 2.35rem !important;
        border: none !important;
        outline: none !important;
        box-shadow: none !important;
        background: transparent !important;
        border-radius: 9999px !important;
        font-size: 0.875rem !important;
        color: #0f172a !important;
        -webkit-appearance: none !important;
        appearance: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-search-input:focus {
        border: none !important;
        outline: none !important;
        box-shadow: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-search-mount .dlv-ai-btn {
        position: absolute !important;
        right: 5px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        width: 28px !important;
        height: 28px !important;
        min-width: 28px !important;
        min-height: 28px !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        border-radius: 50% !important;
        background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
        color: #fff !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        z-index: 4 !important;
        cursor: pointer !important;
        box-shadow: 0 2px 6px rgba(99, 102, 241, .35) !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-filter-btn {
        width: 2.25rem;
        height: 2.25rem;
        border: 1px solid #e2e8f0;
        border-radius: 50%;
        background: #fff;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        cursor: pointer;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn--create {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        height: 38px;
        padding: 0 16px;
        border-radius: 10px;
        background: #6d5df6;
        color: #fff !important;
        font-weight: 600;
        font-size: 14px;
        text-decoration: none;
        box-shadow: 0 6px 16px rgba(109, 93, 246, .22);
        white-space: nowrap;
        border: none;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn-label-mobile {
        display: none;
    }
}
@media (max-width: 767.98px) {
    body.page-dlv-dashboard .employee-header--deliveries .employee-header-center-slot {
        display: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-right.header-actions-tray {
        gap: 0.35rem !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray .notif,
    body.page-dlv-dashboard .employee-header--deliveries .header-actions-tray #themeToggleBtn {
        display: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount {
        display: inline-flex !important;
        align-items: center;
        gap: 0.35rem;
        max-width: min(70vw, 16rem);
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-expand {
        display: flex !important;
        align-items: center;
        flex: 0 0 auto;
        min-width: 0;
        position: relative;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-expand.is-open {
        flex: 1 1 auto;
        min-width: 8rem;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-toggle,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-filter-btn {
        width: 2.15rem;
        height: 2.15rem;
        border: 1px solid #e2e8f0;
        border-radius: 50% !important;
        background: #fff;
        color: #475569;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        padding: 0;
        cursor: pointer;
        flex-shrink: 0;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-toggle.is-active,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-toggle.has-value,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-filter-btn.is-active {
        border-color: #c7d2fe;
        color: #6366f1;
        background: #eef2ff;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn--create {
        display: inline-flex !important;
        align-items: center;
        gap: 0.25rem;
        min-height: 2.15rem;
        padding: 0.35rem 0.7rem;
        border-radius: 9999px !important;
        background: #6d5df6;
        color: #fff !important;
        font-weight: 600;
        font-size: 0.75rem;
        text-decoration: none;
        border: none;
        white-space: nowrap;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(109, 93, 246, .22);
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn-label-desktop {
        display: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-btn-label-mobile {
        display: inline !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel {
        position: fixed !important;
        top: calc(var(--header-height, 3rem) + 0.45rem) !important;
        left: 0.75rem !important;
        right: 0.75rem !important;
        width: auto !important;
        max-width: none !important;
        min-width: 0 !important;
        flex: none !important;
        margin: 0 !important;
        z-index: 1300 !important;
        display: none;
        padding: 0.55rem !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 14px !important;
        background: #fff !important;
        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.16) !important;
        opacity: 1 !important;
        overflow: visible !important;
        pointer-events: auto !important;
        box-sizing: border-box !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel.is-open {
        display: block !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-field {
        position: relative !important;
        display: flex !important;
        align-items: center !important;
        width: 100% !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 9999px !important;
        background: #fff !important;
        box-shadow: none !important;
        overflow: hidden !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-field:focus-within {
        border-color: #a5b4fc !important;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12) !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-icon {
        position: absolute !important;
        left: 0.85rem !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        width: 1rem !important;
        height: 1rem !important;
        color: #94a3b8 !important;
        pointer-events: none !important;
        z-index: 2 !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-input,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel input.dlv-search-input[type="search"] {
        width: 100% !important;
        height: 2.5rem !important;
        margin: 0 !important;
        padding: 0.55rem 2.75rem 0.55rem 2.35rem !important;
        border: none !important;
        border-radius: 9999px !important;
        outline: none !important;
        box-shadow: none !important;
        background: transparent !important;
        font-size: 0.875rem !important;
        font-weight: 400 !important;
        color: #0f172a !important;
        -webkit-appearance: none !important;
        appearance: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-search-input:focus {
        border: none !important;
        outline: none !important;
        box-shadow: none !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-ai-btn,
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel button.dlv-ai-btn {
        position: absolute !important;
        right: 0.35rem !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        width: 1.85rem !important;
        height: 1.85rem !important;
        min-width: 1.85rem !important;
        min-height: 1.85rem !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        border-radius: 50% !important;
        background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
        color: #fff !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        z-index: 3 !important;
        box-shadow: 0 2px 6px rgba(99, 102, 241, .35) !important;
    }
    body.page-dlv-dashboard .employee-header--deliveries .dlv-header-actions-mount .dlv-search-panel .dlv-suggestions {
        position: static !important;
        margin-top: 0.45rem !important;
        width: 100% !important;
        max-height: min(50vh, 18rem);
        overflow: auto;
        border: 1px solid #f1f5f9;
        border-radius: 10px;
        box-shadow: none;
    }
}
main.main-content.dlv-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0.35rem 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f1f5f9 !important;
    min-height: calc(100vh - 80px);
}
@media (max-width: 767.98px) {
    body.page-dlv-dashboard main.main-content.dlv-react-root {
        padding: 0.65rem 0.75rem 1.5rem !important;
    }
}
main.main-content.dlv-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-width: 0;
}
html[data-theme="dark"] body.page-dlv-dashboard,
html[data-theme="dark"] body.page-dlv-dashboard .layout-main-wrapper,
html[data-theme="dark"] body.page-dlv-dashboard .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-dlv-dashboard main.main-content.dlv-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-dlv-dashboard .employee-header.employee-header--deliveries {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-dlv-dashboard .employee-header--deliveries .employee-header-page-title {
    color: #f8fafc !important;
}
</style>
CSS;
    }

    private function createChromeCss(): string
    {
        return <<<CSS
<style>
body.page-dlv-create,
body.page-dlv-create.dashboard,
body.page-dlv-create .layout-main-wrapper,
body.page-dlv-create .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-dlv-create.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-dlv-create.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-dlv-create .employee-header.employee-header--deliveries {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
}
body.page-dlv-create .employee-header--deliveries::after { display: none !important; }
main.main-content.create-delivery-react-root,
main.main-content.dlv-react-root.create-delivery-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0.5rem 1.25rem 2.5rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
    min-height: calc(100vh - 64px);
}
main.main-content.create-delivery-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-width: 0;
}
@media (max-width: 1024px) {
    main.main-content.create-delivery-react-root {
        padding: 1rem 0.875rem 1.5rem !important;
    }
}
@media (max-width: 767.98px) {
    main.main-content.create-delivery-react-root {
        padding: 0.875rem 0.75rem 1.5rem !important;
    }
}
html[data-theme="dark"] body.page-dlv-create,
html[data-theme="dark"] body.page-dlv-create .layout-main-wrapper,
html[data-theme="dark"] body.page-dlv-create .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-dlv-create main.main-content.create-delivery-react-root {
    background: #0f172a !important;
}
</style>
CSS;
    }

    private function orderDetailsChromeCss(): string
    {
        return <<<CSS
<style>
body.page-dlv-order-details,
body.page-dlv-order-details.dashboard,
body.page-dlv-order-details .layout-main-wrapper,
body.page-dlv-order-details .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-dlv-order-details.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-dlv-order-details.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-dlv-order-details .employee-header.employee-header--deliveries,
body.page-dlv-order-details .header {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
}
body.page-dlv-order-details .employee-header--deliveries::after { display: none !important; }
main.main-content.order-details-react-root,
main.main-content.dlv-react-root.order-details-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0.5rem 1.25rem 2.5rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
    min-height: calc(100vh - 64px);
}
main.main-content.order-details-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-width: 0;
}
@media (max-width: 1024px) {
    main.main-content.order-details-react-root {
        padding: 1rem 0.875rem 1.5rem !important;
    }
}
@media (max-width: 767.98px) {
    main.main-content.order-details-react-root {
        padding: 0.875rem 0.75rem 1.5rem !important;
    }
}
html[data-theme="dark"] body.page-dlv-order-details,
html[data-theme="dark"] body.page-dlv-order-details .layout-main-wrapper,
html[data-theme="dark"] body.page-dlv-order-details .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-dlv-order-details main.main-content.order-details-react-root {
    background: #0f172a !important;
}
</style>
CSS;
    }

    private function deliveryNotesChromeCss(): string
    {
        return <<<CSS
<style>
body.page-dlv-notes.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-dlv-notes.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-dlv-notes,
body.page-dlv-notes.dashboard,
body.page-dlv-notes .layout-main-wrapper,
body.page-dlv-notes .layout-main-wrapper > .flex-grow-1 {
    background: #f1f5f9 !important;
}
html, body.page-dlv-notes.dashboard, .main-content, .layout-main-wrapper {
    scrollbar-width: none !important;
    -ms-overflow-style: none !important;
}
html::-webkit-scrollbar, body.page-dlv-notes.dashboard::-webkit-scrollbar,
.main-content::-webkit-scrollbar, .layout-main-wrapper::-webkit-scrollbar {
    width: 0 !important;
    height: 0 !important;
    display: none !important;
}
body.page-dlv-notes .employee-header.employee-header--deliveries {
    background: #f1f5f9 !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
    align-items: stretch !important;
}
body.page-dlv-notes .employee-header--deliveries::after { display: none !important; }
body.page-dlv-notes .employee-header--deliveries .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-dlv-notes .employee-header--deliveries .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-dlv-notes .employee-header--deliveries .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-dlv-notes .employee-header--deliveries .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
    align-self: flex-start;
    overflow: visible;
    flex-shrink: 0;
}
body.page-dlv-notes .employee-header--deliveries .header-actions-tray .notif { order: 2; position: relative; z-index: 2; flex-shrink: 0; }
body.page-dlv-notes .employee-header--deliveries .dlv-header-create-btn {
    order: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 38px;
    padding: 0 16px;
    border-radius: 10px;
    background: #6d5df6;
    color: #fff !important;
    font-weight: 600;
    font-size: 14px;
    line-height: 1;
    text-decoration: none;
    box-shadow: 0 6px 16px rgba(109, 93, 246, .22);
    white-space: nowrap;
    border: none;
    flex-shrink: 0;
}
body.page-dlv-notes .employee-header--deliveries .dlv-header-create-btn:hover {
    color: #fff !important;
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(109, 93, 246, .3);
}
body.page-dlv-notes .employee-header--deliveries .header-actions-tray #themeToggleBtn {
    order: 3;
    display: inline-flex !important;
    flex-shrink: 0;
    visibility: visible !important;
    opacity: 1 !important;
    margin: 0;
    padding: 0;
    border: none;
    background: transparent;
}
body.page-dlv-notes .employee-header--deliveries .header-actions-tray #themeToggleBtn.theme-toggle-glass {
    width: auto;
    height: auto;
    border-radius: 999px;
    color: inherit;
}
body.page-dlv-notes .employee-header--deliveries .header-notif-bell-btn {
    align-self: flex-start;
    width: 38px;
    height: 38px;
    margin: 0;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #fff;
    flex-shrink: 0;
}
@media (min-width: 768px) {
    body.page-dlv-notes .employee-header--deliveries.employee-header--has-center-slot .header-content {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) minmax(280px, 440px) minmax(0, 1fr);
        align-items: center !important;
        gap: 0.75rem 1.25rem !important;
        padding-bottom: 0.35rem !important;
    }
    body.page-dlv-notes .employee-header--deliveries .header-left { display: none !important; }
    body.page-dlv-notes .employee-header--deliveries .employee-header-page-heading--with-center {
        grid-column: 1;
        justify-self: start;
        flex: none;
        min-width: 0;
    }
    body.page-dlv-notes .employee-header--deliveries .employee-header-center-slot {
        grid-column: 2;
        flex: none;
        min-width: 0;
        max-width: none;
        width: 100%;
        margin: 0;
        padding: 0 !important;
        justify-content: center !important;
    }
    body.page-dlv-notes .employee-header--deliveries .header-right.header-actions-tray {
        grid-column: 3;
        justify-self: end;
        margin-left: 0 !important;
        align-self: center !important;
    }
    body.page-dlv-notes .employee-header--deliveries .dlv-header-search-mount { width: 100%; }
    body.page-dlv-notes .employee-header--deliveries .dlv-header-search-mount .dlv-search-field {
        position: relative;
        display: flex;
        align-items: center;
        width: 100%;
        border-radius: 9999px;
        background: #fff;
        border: 1px solid #e2e8f0;
        overflow: hidden;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    body.page-dlv-notes .employee-header--deliveries .dlv-header-search-mount .dlv-search-field:focus-within {
        border-color: #a5b4fc;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
    }
    body.page-dlv-notes .employee-header--deliveries .dlv-header-search-mount .dlv-search-icon {
        position: absolute;
        left: 14px;
        color: #94a3b8;
        pointer-events: none;
        z-index: 1;
    }
    body.page-dlv-notes .employee-header--deliveries .dlv-header-search-mount .dlv-search-input {
        width: 100%;
        padding: 0.55rem 2.75rem 0.55rem 2.35rem;
        border: none !important;
        border-radius: 9999px !important;
        font-size: 0.875rem;
        background: transparent !important;
        color: #0f172a;
        outline: none !important;
        box-shadow: none !important;
        -webkit-appearance: none;
        appearance: none;
    }
    body.page-dlv-notes .employee-header--deliveries .dlv-header-search-mount .dlv-ai-btn {
        right: 5px;
        width: 28px;
        height: 28px;
    }
}
@media (max-width: 767.98px) {
    body.page-dlv-notes .employee-header--deliveries .employee-header-center-slot { display: none !important; }
    body.page-dlv-notes .employee-header--deliveries .dlv-header-create-btn { display: none !important; }
}
main.main-content.dlv-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f1f5f9 !important;
    min-height: calc(100vh - 80px);
}
main.main-content.dlv-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: calc(100vh - 80px);
    min-width: 0;
}
@media (max-width: 1280px) { main.main-content.dlv-react-root { padding: 0 1rem 1.75rem !important; } }
@media (max-width: 1024px) { main.main-content.dlv-react-root { padding: 0 0.875rem 1.5rem !important; } }
@media (max-width: 767.98px) {
    body.page-dlv-notes { --header-height: 3rem; }
    body.page-dlv-notes .employee-header.employee-header--deliveries { padding: 0 0.75rem !important; }
    body.page-dlv-notes .employee-header--deliveries .header-content {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 0.5rem !important;
        min-height: 3rem !important;
        padding: 0.5rem 0 !important;
    }
    body.page-dlv-notes .employee-header--deliveries .header-left { position: static !important; flex: 0 0 auto; order: 1; }
    body.page-dlv-notes .employee-header--deliveries .employee-header-page-heading {
        order: 2;
        flex: 1 1 auto;
        min-width: 0;
        margin-left: 0 !important;
        padding-left: 0 !important;
        padding-right: 0.25rem !important;
    }
    body.page-dlv-notes .employee-header--deliveries .employee-header-page-title {
        font-size: 1rem !important;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    body.page-dlv-notes .employee-header--deliveries .header-right.header-actions-tray {
        order: 3;
        flex: 0 0 auto;
        gap: 0.35rem !important;
        margin-left: auto !important;
        align-self: center !important;
    }
    main.main-content.dlv-react-root { padding: 0 0.75rem 1.5rem !important; }
}
html[data-theme="dark"] body.page-dlv-notes,
html[data-theme="dark"] body.page-dlv-notes.dashboard,
html[data-theme="dark"] body.page-dlv-notes .layout-main-wrapper,
html[data-theme="dark"] body.page-dlv-notes .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-dlv-notes main.main-content.dlv-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-dlv-notes .employee-header.employee-header--deliveries {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-dlv-notes .employee-header--deliveries .employee-header-page-title {
    color: #f8fafc !important;
}
</style>
CSS;
    }

    private function createDeliveryNoteChromeCss(): string
    {
        return <<<CSS
<style>
body.page-create-delivery-note,
body.page-create-delivery-note.dashboard,
body.page-create-delivery-note .layout-main-wrapper,
body.page-create-delivery-note .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
    min-height: 100vh;
}
body.page-create-delivery-note.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-create-delivery-note.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-create-delivery-note .employee-header.employee-header--deliveries,
body.page-create-delivery-note .header {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
}
body.page-create-delivery-note .employee-header--deliveries::after { display: none !important; }
main.main-content.create-delivery-note-react-root,
main.main-content.dlv-react-root.create-delivery-note-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0.5rem 1.25rem 2.5rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
    min-height: calc(100vh - 80px);
}
main.main-content.create-delivery-note-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 100%;
    min-width: 0;
}
@media (max-width: 1024px) {
    main.main-content.create-delivery-note-react-root {
        padding: 1rem 0.875rem 1.5rem !important;
    }
}
@media (max-width: 767.98px) {
    main.main-content.create-delivery-note-react-root {
        padding: 0.875rem 0.75rem 1.5rem !important;
        min-height: calc(100vh - 64px);
    }
}
html[data-theme="dark"] body.page-create-delivery-note,
html[data-theme="dark"] body.page-create-delivery-note .layout-main-wrapper,
html[data-theme="dark"] body.page-create-delivery-note .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-create-delivery-note main.main-content.create-delivery-note-react-root {
    background: #0f172a !important;
}
</style>
CSS;
    }
}
