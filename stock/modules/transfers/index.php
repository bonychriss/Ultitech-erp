<?php
// stock/modules/transfers/index.php  clean Coming soon
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/paths.php';
requireLogin();

if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
    $_GET['module'] = 'stocks';
}
$active_module = 'stocks';

$page_title = 'Stock Transfers';
$employeeHeaderTitle = null;
$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--products-desk';
$bodyExtraClass = 'page-products-desk page-transfers-soon';

include __DIR__ . '/../../includes/header.php';
?>
<style>
body.page-transfers-soon,
body.page-transfers-soon.dashboard,
body.page-transfers-soon .layout-main-wrapper,
body.page-transfers-soon .layout-main-wrapper > .flex-grow-1 {
    background: #ffffff !important;
}
body.page-transfers-soon .employee-header.employee-header--products-desk {
    background: #ffffff !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
}
body.page-transfers-soon .employee-header--products-desk::after { display: none !important; }
body.page-transfers-soon .employee-header--products-desk .header-content {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    justify-content: flex-start !important;
    padding: 0.65rem 0 !important;
    gap: 0.5rem;
    width: 100%;
    background: transparent !important;
}
body.page-transfers-soon .employee-header--products-desk .header-left {
    order: 0 !important;
    flex: 0 0 auto !important;
    margin: 0 !important;
    display: flex !important;
}
body.page-transfers-soon .employee-header--products-desk .header-right.header-actions-tray {
    order: 2 !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    display: flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
}
body.page-transfers-soon .employee-header-menu-btn {
    align-items: center !important;
    justify-content: center !important;
    min-width: 2.5rem;
    min-height: 2.5rem;
    padding: 0.2rem !important;
    margin: 0 !important;
}
@media (max-width: 991.98px) {
    body.page-transfers-soon .employee-header-menu-btn {
        display: inline-flex !important;
    }
}
@media (min-width: 992px) {
    body.page-transfers-soon .employee-header-menu-btn {
        display: none !important;
    }
}

main.tr-soon-main {
    flex: 1 1 auto !important;
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 60vh !important;
    min-height: 60dvh !important;
    background: #ffffff !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-sizing: border-box;
    overflow: auto !important;
}

.tr-soon {
    width: 100%;
    max-width: 28rem;
    margin: 0 auto;
    padding: 2rem 1.25rem 3rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    gap: 0.85rem;
    font-family: var(--erp-font-family, inherit);
    color: #0f172a;
    box-sizing: border-box;
}

.tr-soon-stage {
    position: relative;
    width: 7.5rem;
    height: 7.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.35rem;
}

.tr-soon-pulse {
    position: absolute;
    inset: 0;
    border-radius: 9999px;
    border: 1.5px solid rgba(14, 116, 144, 0.3);
    animation: tr-pulse 2.4s ease-out infinite;
    pointer-events: none;
}
.tr-soon-pulse--2 {
    inset: 0.65rem;
    animation-delay: 0.4s;
    border-color: rgba(8, 145, 178, 0.24);
}
.tr-soon-pulse--3 {
    inset: 1.3rem;
    animation-delay: 0.8s;
    border-color: rgba(34, 211, 238, 0.2);
}

.tr-soon-icon-wrap {
    position: relative;
    z-index: 1;
    width: 3.75rem;
    height: 3.75rem;
    border-radius: 1.1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0e7490;
    background: #ecfeff;
    border: 1px solid #a5f3fc;
    animation: tr-float 2.6s ease-in-out infinite;
}
.tr-soon-icon-wrap svg {
    width: 1.75rem;
    height: 1.75rem;
    display: block;
}

.tr-soon-title {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 500;
    letter-spacing: -0.02em;
    color: #0f172a;
    line-height: 1.25;
}

.tr-soon-text {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 400;
    line-height: 1.5;
    color: #64748b;
    max-width: 20rem;
}

.tr-soon-dots {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.4rem;
}
.tr-soon-dots span {
    width: 0.4rem;
    height: 0.4rem;
    border-radius: 9999px;
    background: #67e8f9;
    animation: tr-dot 1.2s ease-in-out infinite;
}
.tr-soon-dots span:nth-child(2) { animation-delay: 0.15s; }
.tr-soon-dots span:nth-child(3) { animation-delay: 0.3s; }

@keyframes tr-pulse {
    0% { transform: scale(0.88); opacity: 0.8; }
    70% { transform: scale(1.14); opacity: 0; }
    100% { transform: scale(1.14); opacity: 0; }
}
@keyframes tr-float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-7px); }
}
@keyframes tr-dot {
    0%, 80%, 100% { transform: translateY(0); opacity: 0.4; }
    40% { transform: translateY(-4px); opacity: 1; }
}

@media (max-width: 767.98px) {
    body.page-transfers-soon .employee-header.employee-header--products-desk {
        padding: 0 0.75rem !important;
    }
    main.tr-soon-main {
        min-height: calc(100vh - 4rem) !important;
        min-height: calc(100dvh - 4rem) !important;
        padding: 0.5rem 0 2rem !important;
    }
    .tr-soon {
        padding: 1.5rem 1rem 2.5rem;
    }
    .tr-soon-title {
        font-size: 1.35rem;
    }
}

html[data-theme="dark"] body.page-transfers-soon,
html[data-theme="dark"] body.page-transfers-soon.dashboard,
html[data-theme="dark"] body.page-transfers-soon .layout-main-wrapper,
html[data-theme="dark"] body.page-transfers-soon .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-transfers-soon main.tr-soon-main {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-transfers-soon .employee-header.employee-header--products-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] .tr-soon-title { color: #f8fafc; }
html[data-theme="dark"] .tr-soon-text { color: #94a3b8; }
html[data-theme="dark"] .tr-soon-icon-wrap {
    background: rgba(8, 145, 178, 0.18);
    border-color: rgba(34, 211, 238, 0.35);
    color: #67e8f9;
}
</style>

<main class="main-content tr-soon-main" role="main">
    <div class="tr-soon" role="status" aria-live="polite">
        <div class="tr-soon-stage" aria-hidden="true">
            <span class="tr-soon-pulse tr-soon-pulse--1"></span>
            <span class="tr-soon-pulse tr-soon-pulse--2"></span>
            <span class="tr-soon-pulse tr-soon-pulse--3"></span>
            <div class="tr-soon-icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M8 7h12"/>
                    <path d="M16 3l4 4-4 4"/>
                    <path d="M16 17H4"/>
                    <path d="M8 21l-4-4 4-4"/>
                </svg>
            </div>
        </div>
        <h1 class="tr-soon-title">Coming soon</h1>
        <p class="tr-soon-text">Stock Transfers module is launching shortly.</p>
        <div class="tr-soon-dots" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
