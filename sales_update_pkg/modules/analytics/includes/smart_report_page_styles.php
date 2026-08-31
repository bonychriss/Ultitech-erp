<style>
    body.da-page .employee-header.employee-header--page-context {
        padding-bottom: 8px !important;
    }
    body.da-page .employee-header-page-heading {
        gap: 6px !important;
    }
    body.da-page .employee-header-page-title {
        font-family: inherit !important;
        font-size: 20px !important;
        font-weight: 700 !important;
        letter-spacing: -0.02em !important;
    }
    body.da-page .employee-header-page-subtitle {
        margin-top: 0 !important;
    }
    .sa-header-meta {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        font-size: 13px;
        line-height: 1.4;
    }
    .sa-header-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #475569;
        text-decoration: none;
        font-weight: 600;
        white-space: nowrap;
    }
    .sa-header-back:hover {
        color: #2563eb;
        text-decoration: none;
    }
    .sa-header-dot {
        color: #cbd5e1;
        font-weight: 700;
    }
    .sa-header-period {
        color: #94a3b8;
        white-space: nowrap;
    }
    .sa-page {
        margin-top: 16px;
    }
    .sa-date-filters {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: flex-end;
        gap: 12px;
        width: 100%;
        margin-bottom: 16px;
        padding: 0;
        background: transparent;
        border: none;
        box-shadow: none;
    }
    .sa-date-filter-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 130px;
    }
    .sa-date-filter-group label {
        font-size: 11px;
        font-weight: 600;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .sa-date-filter-group input {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 6px 10px;
        font-size: 13px;
        background: #fff;
        color: #1e293b;
    }
    .sa-date-menu {
        position: relative;
        align-self: flex-end;
    }
    .sa-date-menu-toggle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
        cursor: pointer;
        white-space: nowrap;
        line-height: 1.4;
    }
    .sa-date-menu-toggle:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #1e293b;
    }
    .sa-date-menu-toggle .bi {
        font-size: 11px;
        transition: transform 0.15s ease;
    }
    .sa-date-menu.is-open .sa-date-menu-toggle .bi {
        transform: rotate(180deg);
    }
    .sa-date-menu-panel {
        position: absolute;
        top: calc(100% + 6px);
        right: 0;
        z-index: 30;
        min-width: 160px;
        padding: 6px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.1);
    }
    .sa-date-menu-panel[hidden] {
        display: none;
    }
    .sa-date-menu-item {
        display: block;
        padding: 8px 10px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        color: #475569;
        text-decoration: none;
        white-space: nowrap;
    }
    .sa-date-menu-item:hover {
        background: #f1f5f9;
        color: #1e293b;
        text-decoration: none;
    }
    .sa-date-menu-item.is-active {
        background: #eef2ff;
        color: #4338ca;
    }
    .sa-date-menu-item--reset {
        color: #64748b;
    }
    .sa-date-menu-divider {
        height: 1px;
        margin: 4px 6px;
        background: #e2e8f0;
    }
    .sa-matrix-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
    }
    .sa-matrix-card.is-tree-collapsed .sa-row-child {
        display: none;
    }
    .sa-matrix-card.is-tree-collapsed .sa-matrix-actions {
        display: none;
    }
    .sa-matrix-card.is-tree-collapsed .sa-row-drill-panel {
        display: none;
    }
    .sa-matrix-scroll {
        overflow-x: auto;
        width: 100%;
    }
    .sa-matrix {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        min-width: 900px;
    }
    .sa-matrix thead th {
        background: #f8fafc;
        color: #475569;
        font-weight: 600;
        padding: 10px 12px;
        border-bottom: 1px solid #e2e8f0;
        border-right: 1px solid #f1f5f9;
        white-space: nowrap;
        text-align: right;
    }
    .sa-matrix thead th.sa-col-label {
        text-align: left;
        min-width: 220px;
        position: sticky;
        left: 72px;
        z-index: 2;
        background: #f8fafc;
    }
    .sa-matrix thead th.sa-col-num,
    .sa-matrix tbody td.sa-col-num {
        text-align: center;
        width: 42px;
        min-width: 42px;
        position: sticky;
        left: 0;
        z-index: 2;
        background: inherit;
    }
    .sa-matrix thead th.sa-col-check,
    .sa-matrix tbody td.sa-col-check {
        width: 36px;
        min-width: 36px;
        text-align: center;
        position: sticky;
        left: 42px;
        z-index: 2;
        background: inherit;
    }
    .sa-matrix tbody td {
        padding: 9px 12px;
        border-bottom: 1px solid #f1f5f9;
        border-right: 1px solid #f8fafc;
        text-align: right;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    .sa-matrix-val--zero { color: #dc2626; font-weight: 400; }
    .sa-matrix-val--low { color: #64748b; font-weight: 500; }
    .sa-matrix-val--mid { color: #2563eb; font-weight: 600; }
    .sa-matrix-val--high { color: #059669; font-weight: 700; }
    .sa-matrix-val--total { color: #0f172a; font-weight: 700; }
    .sa-matrix tbody tr.sa-row-total td.sa-matrix-val--zero { color: #ef4444; }
    .sa-matrix tbody td.sa-col-label {
        text-align: left;
        font-weight: 500;
        color: #111827;
        position: sticky;
        left: 72px;
        z-index: 1;
        background: #fff;
    }
    .sa-matrix thead th.sa-col-meta,
    .sa-matrix tbody td.sa-col-meta {
        text-align: left;
        min-width: 150px;
        max-width: 200px;
        color: #475569;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sa-matrix tbody tr.sa-row-total td.sa-col-meta {
        color: #94a3b8;
        font-weight: 400;
    }
    .sa-col-image {
        width: 52px;
        min-width: 52px;
        text-align: center;
        padding: 6px 8px;
    }
    .sa-product-thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        display: block;
        margin: 0 auto;
    }
    .sa-matrix tbody tr.sa-row-total td {
        background: #f8fafc;
        font-weight: 700;
        color: #0f172a;
    }
    .sa-matrix tbody tr.sa-row-total td.sa-col-label {
        background: #f8fafc;
    }
    .sa-matrix tbody tr:hover td {
        background: #f1f5f9;
    }
    .sa-matrix tbody tr.sa-row-total:hover td {
        background: #eef2f7;
    }
    .sa-matrix tbody tr.sa-row-child td.sa-col-label-child {
        padding-left: 32px;
        font-weight: 400;
    }
    .sa-matrix tbody tr.sa-row-child td {
        background: #fff;
    }
    .sa-matrix tbody tr.sa-row-child:hover td {
        background: #f8fafc;
    }
    .sa-matrix tbody tr.sa-row-drillable {
        cursor: pointer;
    }
    .sa-matrix tbody tr.sa-row-drillable.sa-row-selected td {
        background: #eff6ff;
    }
    .sa-matrix tbody tr.sa-row-drill-panel td {
        padding: 0;
        background: #fff;
        border-bottom: 1px solid #e2e8f0;
    }
    .sa-drill-invoices {
        overflow-x: auto;
        width: 100%;
    }
    .sa-matrix.sa-matrix--invoices {
        min-width: 100%;
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
    }
    .sa-matrix.sa-matrix--invoices thead th.sa-col-check,
    .sa-matrix.sa-matrix--invoices tbody td.sa-col-check,
    .sa-matrix.sa-matrix--invoices thead th.sa-invoice-gap,
    .sa-matrix.sa-matrix--invoices tbody td.sa-invoice-gap,
    .sa-matrix.sa-matrix--invoices thead th.sa-col-label,
    .sa-matrix.sa-matrix--invoices thead th.sa-col-meta,
    .sa-matrix.sa-matrix--invoices tbody td.sa-col-label,
    .sa-matrix.sa-matrix--invoices tbody td.sa-col-meta,
    .sa-matrix.sa-matrix--invoices thead th.sa-col-status,
    .sa-matrix.sa-matrix--invoices tbody td.sa-col-status {
        position: static;
        left: auto;
        z-index: auto;
    }
    .sa-matrix.sa-matrix--invoices .sa-invoice-gap {
        width: 42px;
        min-width: 42px;
        max-width: 42px;
        padding: 0;
    }
    .sa-matrix.sa-matrix--invoices .sa-col-month-gap {
        width: 48px;
        min-width: 48px;
        padding: 0;
        border-right-color: transparent;
    }
    .sa-matrix.sa-matrix--invoices .sa-col-amount {
        min-width: 110px;
    }
    .sa-matrix.sa-matrix--invoices .sa-col-balance {
        padding-right: 18px;
    }
    .sa-matrix.sa-matrix--invoices .sa-matrix-val.sa-col-amount {
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sa-matrix.sa-matrix--invoices .sa-col-status {
        text-align: left;
        min-width: 96px;
        width: 96px;
        padding-left: 16px;
        color: #475569;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sa-matrix.sa-matrix--invoices thead th.sa-col-status {
        text-align: left;
    }
    .sa-matrix.sa-matrix--invoices tbody tr.sa-row-invoice td {
        background: #fff;
    }
    .sa-matrix.sa-matrix--invoices tbody tr.sa-row-invoice:hover td {
        background: #f8fafc;
    }
    .sa-matrix.sa-matrix--invoices tbody td.sa-col-label-child {
        padding-left: 32px;
    }
    .sa-matrix.sa-matrix--invoices tbody td.sa-col-label a {
        color: #111827;
        font-weight: 500;
        text-decoration: none;
    }
    .sa-matrix.sa-matrix--invoices tbody td.sa-col-label a:hover {
        color: #2563eb;
        text-decoration: underline;
    }
    .sa-drill-empty {
        padding: 14px 16px 14px 44px;
        font-size: 13px;
    }
    .sa-drill-loading {
        padding: 16px;
        font-size: 13px;
        color: #64748b;
    }
    .sa-tree-toggle {
        border: none;
        background: transparent;
        padding: 0;
        margin-right: 4px;
        color: #64748b;
        cursor: pointer;
        vertical-align: middle;
        font-size: 12px;
    }
    .sa-matrix-footer {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border-top: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .sa-level-input {
        width: 52px;
        height: 32px;
        padding: 0 8px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 13px;
        text-align: center;
    }
    .sa-matrix-block {
        margin-top: 16px;
    }
    .sa-matrix-block .sales-drill-sub {
        margin-top: 0;
    }
    .sales-drill-section .sa-matrix-card {
        margin-top: 16px;
    }
    .sa-matrix-actions {
        display: flex;
        justify-content: center;
        padding: 10px 14px 12px;
        border-top: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .sa-view-all-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #d1d5db;
        background: #fff;
        color: #2563eb;
        font-size: 13px;
        font-weight: 600;
        padding: 8px 14px;
        border-radius: 8px;
        cursor: pointer;
    }
    .sa-view-all-btn:hover {
        background: #eff6ff;
        border-color: #93c5fd;
    }
    .sa-matrix-card.is-preview-expanded .sa-view-all-btn .bi-chevron-down {
        transform: rotate(180deg);
    }
    .sa-revenue-kpi-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    .sa-revenue-kpi-row .sales-kpi-label {
        text-transform: none;
        letter-spacing: 0;
        font-weight: 500;
        font-size: 13px;
    }
    .sa-revenue-kpi-row .sales-kpi-value {
        font-size: 18px;
    }
    .sa-revenue-kpi-row .sales-kpi-icon {
        width: 40px;
        height: 40px;
        font-size: 18px;
    }
    @media (min-width: 768px) {
        .sa-revenue-kpi-row {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }
    @media (min-width: 1100px) {
        .sa-revenue-kpi-row {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }
        .sa-revenue-kpi-row .sales-kpi-card {
            min-height: 92px;
            padding: 14px 16px;
        }
        .sa-revenue-kpi-row .sales-kpi-value {
            font-size: 19px;
        }
        .sa-revenue-kpi-row .sales-kpi-label {
            font-size: 14px;
        }
    }
    .sa-intelligence {
        margin-top: 0;
    }
    .sales-drill-sections {
        display: flex;
        flex-direction: column;
        gap: 28px;
    }
    .sales-drill-metrics-row {
        display: grid;
        grid-template-columns: 1fr;
        gap: 28px;
    }
    @media (min-width: 1100px) {
        .sales-drill-metrics-row {
            grid-template-columns: 1fr 1.2fr;
            align-items: stretch;
        }
    }
    .sales-drill-section {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px 22px 22px;
        scroll-margin-top: 88px;
    }
    .sales-drill-section--metrics {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .sales-drill-section-head {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 18px;
    }
    .sales-drill-section-badge {
        flex-shrink: 0;
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    .sales-drill-section-badge--success { background: #ecfdf5; color: #059669; }
    .sales-drill-section-badge--info { background: #eff6ff; color: #2563eb; }
    .sales-drill-section-badge--warning { background: #fffbeb; color: #d97706; }
    .sales-drill-section-badge--danger { background: #fef2f2; color: #dc2626; }
    .sales-drill-section-badge--secondary { background: #f1f5f9; color: #475569; }
    .sales-drill-section-badge--primary { background: #eef2ff; color: #4f46e5; }
    .sales-drill-section-copy h3 {
        margin: 0 0 4px;
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.3;
    }
    .sales-drill-section-copy .sales-drill-desc {
        margin: 0;
    }
    .sales-drill-section h3 {
        margin: 0 0 6px;
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .sales-kpi-grid {
        display: grid;
        gap: 20px;
        margin-top: 8px;
    }
    .sales-kpi-grid--2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .sales-kpi-grid--4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .sales-kpi-grid--5 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .sales-kpi-grid--6 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    @media (min-width: 640px) {
        .sales-kpi-grid--4 {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .sales-kpi-grid--5 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        .sales-kpi-grid--6 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }
    @media (min-width: 1024px) {
        .sales-kpi-grid--5 {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }
    }
    .sales-kpi-card {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
        background: #f8fafc;
        border: 1px solid #e8edf3;
        border-radius: 10px;
        min-height: 76px;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .sales-kpi-card:hover {
        border-color: #dbe3ec;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }
    .sa-performance-kpi-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }
    .sa-performance-kpi-row .sales-kpi-label {
        text-transform: none;
        letter-spacing: 0;
        font-weight: 500;
        font-size: 13px;
    }
    .sa-performance-kpi-row .sales-kpi-value {
        font-size: 18px;
    }
    @media (min-width: 768px) {
        .sa-performance-kpi-row {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }
    @media (min-width: 1100px) {
        .sa-performance-kpi-row .sales-kpi-value {
            font-size: 19px;
        }
        .sa-performance-kpi-row .sales-kpi-label {
            font-size: 14px;
        }
    }
    .sa-rep-performance-block {
        margin-top: 4px;
    }
    .sa-rep-performance-block .sales-drill-sub {
        margin: 0 0 10px;
        font-size: 14px;
        font-weight: 700;
        color: #0f172a;
    }
    .sa-matrix.sa-matrix--rep-performance {
        min-width: 100%;
        width: 100%;
        table-layout: auto;
    }
    .sa-matrix.sa-matrix--rep-performance thead th.sa-col-label,
    .sa-matrix.sa-matrix--rep-performance tbody td.sa-col-label {
        position: static;
        left: auto;
        z-index: auto;
        text-align: left;
        min-width: 180px;
    }
    .sa-matrix.sa-matrix--rep-performance tbody tr.sa-row-rep-link {
        cursor: pointer;
    }
    .sa-matrix.sa-matrix--rep-performance tbody td.sa-col-label-child {
        padding-left: 32px;
        font-weight: 500;
    }
    .sa-drill-rep-detail {
        display: grid;
        gap: 20px;
        padding: 14px 16px 16px;
        background: #f8fafc;
    }
    @media (min-width: 992px) {
        .sa-drill-rep-detail {
            grid-template-columns: 1fr 1fr;
        }
    }
    .sa-drill-rep-section {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px 14px;
    }
    .sa-drill-rep-heading {
        margin: 0 0 10px;
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 8px;
    }
    .sa-drill-rep-meta {
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
    }
    .sa-drill-rep-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        margin-bottom: 0;
    }
    .sa-drill-rep-table th {
        background: #f8fafc;
        color: #64748b;
        font-weight: 600;
        padding: 8px 10px;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .sa-drill-rep-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .sa-drill-rep-table tfoot td {
        border-bottom: none;
        border-top: 1px solid #e2e8f0;
        background: #f8fafc;
    }   
    .sa-drill-rep-total td {   
        font-weight: 700;   
        color: #0f172a;   
    }   
    .sa-drill-rep-table a {   
        color: #2563eb;   
        text-decoration: none;   
        font-weight: 600;   
    }   
    .sa-drill-rep-table a:hover {   
        text-decoration: underline;   
    }   
    .sa-achieve--good { color: #059669; font-weight: 600; }
    .sa-achieve--warn { color: #d97706; font-weight: 600; }
    .sa-achieve--low { color: #dc2626; font-weight: 600; }
    .sa-achieve--na { color: #94a3b8; }
    .sa-achieve--contrib { color: #475569; font-weight: 500; }
    .sa-rep-performance-note,
    .sa-rep-performance-empty {
        margin-top: 12px;
        font-size: 13px;
    }
    .sa-target-source {
        color: #94a3b8;
        font-size: 11px;
        margin-left: 2px;
        cursor: help;
    }
    .sa-soon-panel {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 28px 24px;
        margin-top: 4px;
        background: #fff;
        border: 1px solid #e8edf3;
        border-radius: 16px;
    }
    .sa-soon-panel-graphic {
        position: relative;
        width: 72px;
        height: 72px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
    }
    .sa-soon-panel-ring {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 100%);
        border: 1px solid #e0e7ff;
    }
    .sa-soon-panel-graphic .bi {
        position: relative;
        font-size: 28px;
        color: #6366f1;
    }
    .sa-soon-panel-eyebrow {
        margin: 0;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #6366f1;
    }
    .sa-fulfillment-kpi-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    @media (min-width: 768px) {
        .sa-fulfillment-kpi-row {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }
    .sa-fulfillment-kpi-row .sales-kpi-label {
        text-transform: none;
        letter-spacing: 0;
        font-weight: 500;
        font-size: 13px;
    }
    .sa-fulfillment-kpi-row .sales-kpi-value--soon {
        font-size: 15px;
        font-weight: 600;
        color: #94a3b8;
    }
    .sa-ar-aging-kpi-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
    }
    @media (min-width: 768px) {
        .sa-ar-aging-kpi-row {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }
    @media (min-width: 1200px) {
        .sa-ar-aging-kpi-row {
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }
    }
    .sa-ar-aging-kpi-row .sales-kpi-label {
        text-transform: none;
        letter-spacing: 0;
        font-weight: 500;
        font-size: 13px;
    }
    .sa-ar-aging-kpi-row .sales-kpi-value {
        font-size: 16px;
    }
    @media (min-width: 1100px) {
        .sa-ar-aging-kpi-row .sales-kpi-value {
            font-size: 17px;
        }
        .sa-ar-aging-kpi-row .sales-kpi-label {
            font-size: 14px;
        }
    }
    .sales-kpi-card--soon {
        opacity: 0.92;
        background: #fff;
        border-style: dashed;
        border-color: #dbe3ec;
        cursor: not-allowed;
    }
    .sales-kpi-card--soon .sales-kpi-icon {
        opacity: 0.75;
    }
    .sales-kpi-card--soon:hover {
        box-shadow: none;
        border-color: #dbe3ec;
    }
    .sales-kpi-icon {
        flex-shrink: 0;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
    }
    .sales-kpi-body {
        min-width: 0;
        flex: 1;
    }
    .sales-kpi-label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #64748b;
        margin-bottom: 6px;
        line-height: 1.2;
    }
    .sales-kpi-value {
        display: block;
        font-size: 17px;
        font-weight: 700;
        color: #0f172a;
        font-variant-numeric: tabular-nums;
        line-height: 1.35;
        word-break: break-word;
    }
    .sales-kpi-card--blue .sales-kpi-icon { background: #eff6ff; color: #2563eb; }
    .sales-kpi-card--amber .sales-kpi-icon { background: #fffbeb; color: #d97706; }
    .sales-kpi-card--green .sales-kpi-icon { background: #ecfdf5; color: #059669; }
    .sales-kpi-card--violet .sales-kpi-icon { background: #f5f3ff; color: #7c3aed; }
    .sales-kpi-card--indigo .sales-kpi-icon { background: #eef2ff; color: #4f46e5; }
    .sales-kpi-card--slate .sales-kpi-icon { background: #f1f5f9; color: #475569; }
    .sales-kpi-card--up .sales-kpi-icon { background: #ecfdf5; color: #059669; }
    .sales-kpi-card--up .sales-kpi-value { color: #059669; }
    .sales-kpi-card--down .sales-kpi-icon { background: #fef2f2; color: #dc2626; }
    .sales-kpi-card--down .sales-kpi-value { color: #dc2626; }
    .sales-kpi-card--neutral .sales-kpi-icon { background: #f1f5f9; color: #64748b; }
    .sales-drill-section h4.sales-drill-sub {
        margin: 16px 0 8px;
        font-size: 13px;
        font-weight: 600;
        color: #334155;
    }
    .sales-drill-desc {
        margin: 0 0 14px;
        font-size: 13px;
        color: #64748b;
    }
    .sales-drill-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 12px;
    }
    .sales-drill-stat {
        padding: 12px 14px;
        background: #f8fafc;
        border: 1px solid #f1f5f9;
        border-radius: 8px;
    }
    .sales-drill-stat .label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #64748b;
        margin-bottom: 4px;
    }
    .sales-drill-stat .value {
        font-size: 15px;
        font-weight: 700;
        color: #0f172a;
        font-variant-numeric: tabular-nums;
    }
    .sales-drill-table {
        font-size: 12px;
        margin-bottom: 0;
    }
    .sales-drill-table th {
        background: #f8fafc;
        color: #475569;
        font-weight: 600;
        white-space: nowrap;
    }
    .sales-drill-table td,
    .sales-drill-table th {
        padding: 8px 12px;
        vertical-align: middle;
    }

    /* AR Aging Custom Visual Layout */
    .sa-aging-progress-wrapper {
        margin-bottom: 24px;
        margin-top: 4px;
    }
    .sa-aging-progress {
        display: flex;
        height: 18px;
        border-radius: 9px;
        overflow: hidden;
        background: #e2e8f0;
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.08);
    }
    .sa-aging-progress-bar {
        height: 100%;
        transition: width 0.4s ease;
    }
    .sa-aging-progress-bar--current { background: linear-gradient(90deg, #10b981, #059669); }
    .sa-aging-progress-bar--1-30 { background: linear-gradient(90deg, #3b82f6, #2563eb); }
    .sa-aging-progress-bar--31-60 { background: linear-gradient(90deg, #f59e0b, #d97706); }
    .sa-aging-progress-bar--61-90 { background: linear-gradient(90deg, #f97316, #ea580c); }
    .sa-aging-progress-bar--90-plus { background: linear-gradient(90deg, #ef4444, #dc2626); }

    .sa-aging-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    .sa-aging-card {
        position: relative;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px 16px 16px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        overflow: hidden;
    }
    .sa-aging-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
        border-color: #cbd5e1;
    }
    .sa-aging-card-accent {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }
    .sa-aging-card--current .sa-aging-card-accent { background: #10b981; }
    .sa-aging-card--1-30 .sa-aging-card-accent { background: #3b82f6; }
    .sa-aging-card--31-60 .sa-aging-card-accent { background: #f59e0b; }
    .sa-aging-card--61-90 .sa-aging-card-accent { background: #f97316; }
    .sa-aging-card--90-plus .sa-aging-card-accent { background: #ef4444; }
    .sa-aging-card--total .sa-aging-card-accent { background: #6366f1; }

    .sa-aging-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 4px;
    }
    .sa-aging-card-icon {
        font-size: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
    }
    .sa-aging-card--current .sa-aging-card-icon { background: #ecfdf5; color: #059669; }
    .sa-aging-card--1-30 .sa-aging-card-icon { background: #eff6ff; color: #2563eb; }
    .sa-aging-card--31-60 .sa-aging-card-icon { background: #fffbeb; color: #d97706; }
    .sa-aging-card--61-90 .sa-aging-card-icon { background: #fff7ed; color: #ea580c; }
    .sa-aging-card--90-plus .sa-aging-card-icon { background: #fef2f2; color: #dc2626; }
    .sa-aging-card--total .sa-aging-card-icon { background: #eef2ff; color: #4f46e5; }

    .sa-aging-card-badge {
        font-size: 11px;
        font-weight: 600;
        padding: 2px 6px;
        border-radius: 6px;
    }
    .sa-aging-card--current .sa-aging-card-badge { background: #d1fae5; color: #065f46; }
    .sa-aging-card--1-30 .sa-aging-card-badge { background: #dbeafe; color: #1e40af; }
    .sa-aging-card--31-60 .sa-aging-card-badge { background: #fef3c7; color: #92400e; }
    .sa-aging-card--61-90 .sa-aging-card-badge { background: #ffedd5; color: #9a3412; }
    .sa-aging-card--90-plus .sa-aging-card-badge { background: #fee2e2; color: #991b1b; }
    .sa-aging-card--total .sa-aging-card-badge { background: #e0e7ff; color: #3730a3; }

    .sa-aging-card-label {
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
    }
    .sa-aging-card-value {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        font-variant-numeric: tabular-nums;
        line-height: 1.25;
    }
    .sa-data-checker {
        margin-bottom: 16px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #fff;
        overflow: hidden;
    }
    .sa-data-checker-inner {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 14px;
    }
    .sa-data-checker-icon {
        flex-shrink: 0;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        background: #f1f5f9;
        color: #64748b;
    }
    .sa-data-checker-copy {
        min-width: 0;
        flex: 1;
    }
    .sa-data-checker-title {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 2px;
    }
    .sa-data-checker-status {
        display: block;
        font-size: 12px;
        color: #64748b;
        line-height: 1.45;
    }
    .sa-data-checker--loading .sa-data-checker-icon {
        animation: saCheckerPulse 1.2s ease-in-out infinite;
    }
    .sa-data-checker--ok {
        border-color: #bbf7d0;
        background: linear-gradient(180deg, #f0fdf4 0%, #fff 100%);
    }
    .sa-data-checker--ok .sa-data-checker-icon {
        background: #dcfce7;
        color: #16a34a;
    }
    .sa-data-checker--error {
        border-color: #fecaca;
        background: linear-gradient(180deg, #fef2f2 0%, #fff 100%);
    }
    .sa-data-checker--error .sa-data-checker-icon {
        background: #fee2e2;
        color: #dc2626;
    }
    .sa-data-checker--blocking {
        margin-bottom: 0;
    }
    .sa-data-checker-blocked-note {
        margin: 12px 0 0;
        padding: 10px 12px;
        border-radius: 8px;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        color: #9a3412;
        font-size: 12px;
        font-weight: 600;
        line-height: 1.45;
    }
    .sa-data-checker--blocking .sa-data-checker-body {
        border-top: 1px solid #fecaca;
        padding: 0 14px 14px;
    }
    .sa-data-checker-issues {
        margin: 10px 0 0;
        padding: 0;
        list-style: none;
    }
    .sa-data-checker-issues li {
        padding: 10px 12px;
        border-radius: 8px;
        background: #fff;
        border: 1px solid #fecaca;
        font-size: 12px;
        color: #334155;
        line-height: 1.45;
    }
    .sa-data-checker-issues li + li {
        margin-top: 8px;
    }
    .sa-data-checker-issues strong {
        display: block;
        color: #991b1b;
        font-size: 12px;
        margin-bottom: 4px;
    }
    .sa-data-checker-issues em {
        display: block;
        font-style: normal;
        color: #64748b;
        font-size: 11px;
        margin-top: 4px;
    }
    .sa-data-checker-details {
        margin: 10px 0 0;
        padding-left: 18px;
        font-size: 12px;
        color: #475569;
        line-height: 1.45;
    }
    .sa-data-checker-details li + li {
        margin-top: 6px;
    }
    @keyframes saCheckerPulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.45; }
    }
    .sa-matrix.sa-matrix--finance { min-width: 720px; }
    .sa-matrix.sa-matrix--finance thead th,
    .sa-matrix.sa-matrix--finance tbody td { position: static; left: auto; z-index: auto; }
    .sa-matrix.sa-matrix--finance thead th.sa-col-label,
    .sa-matrix.sa-matrix--finance tbody td.sa-col-label { text-align: left; min-width: 200px; }
    .fi-table-card { margin-top: 12px; }

    /* Profit & Loss Statement panel */
    .fi-pl-panel {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 22px 24px 24px;
        margin-bottom: 28px;
        scroll-margin-top: 88px;
    }
    .fi-pl-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }
    .fi-pl-title {
        margin: 0;
        font-size: 22px;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: -0.02em;
    }
    .fi-pl-subtitle {
        margin: 4px 0 0;
        font-size: 13px;
        color: #64748b;
    }
    .fi-pl-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .fi-pl-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 34px;
        padding: 0 12px;
        border: 1px solid #dbe2ea;
        border-radius: 8px;
        background: #fff;
        color: #334155;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
    }
    .fi-pl-btn:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
    }
    .fi-pl-toolbar {
        display: grid;
        grid-template-columns: minmax(220px, 1.4fr) repeat(3, minmax(140px, 1fr)) auto;
        gap: 12px;
        align-items: end;
        padding: 14px 16px;
        background: #f8fafc;
        border: 1px solid #e8edf3;
        border-radius: 10px;
        margin-bottom: 20px;
    }
    .fi-pl-field label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #64748b;
        margin-bottom: 5px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .fi-pl-input-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
        height: 36px;
        padding: 0 10px;
        border: 1px solid #dbe2ea;
        border-radius: 8px;
        background: #fff;
    }
    .fi-pl-input-wrap .bi { color: #94a3b8; font-size: 14px; }
    .fi-pl-input-wrap input[type="date"] {
        border: none;
        background: transparent;
        font-size: 12px;
        color: #0f172a;
        min-width: 0;
        flex: 1;
        padding: 0;
    }
    .fi-pl-input-sep { color: #94a3b8; font-size: 12px; }
    .fi-pl-select {
        width: 100%;
        height: 36px;
        border: 1px solid #dbe2ea;
        border-radius: 8px;
        padding: 0 10px;
        font-size: 12px;
        background: #fff;
        color: #0f172a;
    }
    .fi-pl-generate {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        height: 36px;
        padding: 0 16px;
        border: none;
        border-radius: 8px;
        background: #0b1f5d;
        color: #fff;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
    }
    .fi-pl-generate:hover { background: #132d75; }
    .fi-pl-kpi-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }
    @media (min-width: 900px) {
        .fi-pl-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (min-width: 1200px) {
        .fi-pl-kpi-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .fi-pl-toolbar {
            grid-template-columns: minmax(240px, 1.5fr) repeat(3, minmax(150px, 1fr)) auto;
        }
    }
    .fi-pl-kpi {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        padding: 14px 14px 12px;
        border: 1px solid #e8edf3;
        border-radius: 10px;
        background: #fff;
        min-height: 92px;
    }
    .fi-pl-kpi-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
    }
    .fi-pl-kpi--emerald .fi-pl-kpi-icon { background: #ecfdf5; color: #059669; }
    .fi-pl-kpi--emerald .fi-pl-kpi-value { color: #059669; }
    .fi-pl-kpi--blue .fi-pl-kpi-icon { background: #eff6ff; color: #2563eb; }
    .fi-pl-kpi--blue .fi-pl-kpi-value { color: #2563eb; }
    .fi-pl-kpi--violet .fi-pl-kpi-icon { background: #f5f3ff; color: #7c3aed; }
    .fi-pl-kpi--violet .fi-pl-kpi-value { color: #7c3aed; }
    .fi-pl-kpi--amber .fi-pl-kpi-icon { background: #fffbeb; color: #d97706; }
    .fi-pl-kpi--amber .fi-pl-kpi-value { color: #d97706; }
    .fi-pl-kpi--teal .fi-pl-kpi-icon { background: #f0fdfa; color: #0d9488; }
    .fi-pl-kpi--teal .fi-pl-kpi-value { color: #0d9488; }
    .fi-pl-kpi--rose .fi-pl-kpi-icon { background: #fff1f2; color: #e11d48; }
    .fi-pl-kpi--rose .fi-pl-kpi-value { color: #e11d48; }
    .fi-pl-kpi-body { min-width: 0; }
    .fi-pl-kpi-label {
        display: block;
        font-size: 12px;
        color: #64748b;
        margin-bottom: 4px;
    }
    .fi-pl-kpi-value {
        display: block;
        font-size: 17px;
        font-weight: 700;
        line-height: 1.25;
        font-variant-numeric: tabular-nums;
        margin-bottom: 4px;
    }
    .fi-pl-change {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        font-size: 11px;
        font-weight: 600;
    }
    .fi-pl-change--up { color: #059669; }
    .fi-pl-change--down { color: #dc2626; }
    .fi-pl-change--flat { color: #94a3b8; }
    .fi-pl-table-wrap {
        border: 1px solid #e8edf3;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }
    .fi-pl-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .fi-pl-table thead th {
        background: #f8fafc;
        color: #475569;
        font-weight: 700;
        padding: 12px 14px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: bottom;
        text-align: left;
    }
    .fi-pl-table thead th span {
        display: block;
        font-size: 10px;
        font-weight: 600;
        color: #94a3b8;
        margin-top: 2px;
    }
    .fi-pl-col-num { width: 56px; text-align: center !important; }
    .fi-pl-col-label { min-width: 220px; }
    .fi-pl-col-amt, .fi-pl-col-change { text-align: right !important; white-space: nowrap; }
    .fi-pl-table td {
        padding: 10px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        font-variant-numeric: tabular-nums;
    }
    .fi-pl-row--section td {
        font-weight: 800;
        color: #1d4ed8;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        background: #fcfdff;
        padding-top: 14px;
    }
    .fi-pl-row--item .fi-pl-col-label {
        color: #334155;
        font-weight: 500;
        padding-left: 28px;
    }
    .fi-pl-row--total td {
        font-weight: 800;
        color: #0f172a;
    }
    .fi-pl-row--total.fi-pl-row--green td { background: #f0fdf4; }
    .fi-pl-row--total.fi-pl-row--blue td { background: #eff6ff; }
    .fi-pl-footnote {
        margin: 0;
        padding: 10px 14px 12px;
        font-size: 11px;
        color: #94a3b8;
        background: #fafafa;
        border-top: 1px solid #f1f5f9;
    }
    .fi-pl-foldable {
        margin-top: 4px;
        border: 1px solid #e8edf3;
        border-radius: 10px;
    }
    .fi-pl-foldable .fi-pl-table thead th {
        background: #f8fafc;
        color: #475569;
        font-weight: 700;
        padding: 12px 14px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: bottom;
        text-align: left;
        font-size: 12px;
    }
    .fi-pl-foldable .fi-pl-table thead th.fi-pl-col-amt,
    .fi-pl-foldable .fi-pl-table thead th.fi-pl-col-change {
        text-align: right;
    }
    .fi-pl-foldable .fi-pl-table thead th span {
        display: block;
        font-size: 10px;
        font-weight: 600;
        color: #94a3b8;
        margin-top: 2px;
    }
    .fi-pl-foldable .fi-pl-col-amt,
    .fi-pl-foldable .fi-pl-col-change {
        text-align: right !important;
        white-space: nowrap;
    }
    .fi-pl-foldable .sa-row-child.fi-pl-row--section td {
        font-weight: 800;
        color: #1d4ed8;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        background: #fcfdff;
        padding-top: 12px;
    }
    .fi-pl-foldable .sa-row-child.fi-pl-row--item .sa-col-label-child {
        padding-left: 28px;
        font-weight: 500;
        color: #334155;
    }
    .fi-pl-foldable .sa-row-child.fi-pl-row--total td {
        font-weight: 800;
        color: #0f172a;
    }
    .fi-pl-foldable .sa-row-child.fi-pl-row--total.fi-pl-row--green td { background: #f0fdf4; }
    .fi-pl-foldable .sa-row-child.fi-pl-row--total.fi-pl-row--blue td { background: #eff6ff; }
    .fi-pl-foldable + .fi-pl-footnote {
        border: 1px solid #e8edf3;
        border-top: none;
        border-radius: 0 0 10px 10px;
        margin-top: 0;
    }
    @media (max-width: 900px) {
        .fi-pl-toolbar {
            grid-template-columns: 1fr 1fr;
        }
        .fi-pl-generate { grid-column: 1 / -1; width: 100%; }
    }
    @media print {
        .fi-pl-toolbar, .fi-pl-actions, .sa-date-filters { display: none !important; }
        .fi-pl-panel { border: none; padding: 0; }
    }
</style>
