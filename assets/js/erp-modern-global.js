/**
 * ERP Modern Global Utility
 * Handles automated table labeling and generic selection modals
 */

(function() {
    // 1. Automated Table Responsive Indexer
    function labelTables() {
        const tables = document.querySelectorAll('.main-content table:not(.no-responsive), .content-wrapper table:not(.no-responsive)');
        tables.forEach(table => {
            // Add identifying class if not present
            if (!table.classList.contains('responsive-table')) {
                table.classList.add('responsive-table');
            }

            const headerCells = table.querySelectorAll('thead th');
            if (headerCells.length === 0) return;

            const headers = Array.from(headerCells).map(th => th.textContent.trim());
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                cells.forEach((td, idx) => {
                    if (!td.hasAttribute('data-label') && headers[idx]) {
                        td.setAttribute('data-label', headers[idx]);
                    }
                });
            });
        });
    }

    // 2. Global Creation Type Selection (Generic SweetAlert2 Pattern)
    window.showGlobalCreationSelection = function(config) {
        if (!window.Swal) {
            console.error('SweetAlert2 not loaded');
            return;
        }

        Swal.fire({
            title: config.title || 'Select Creation Type',
            html: `
                <div class="creation-selection-wrapper" style="padding: 20px 10px;">
                    <div class="d-grid gap-3">
                        ${config.options.map(opt => `
                            <button onclick="window.location.href='${opt.url}'" 
                                    class="btn-modern-create" 
                                    style="width: 100%; border: 1px solid #e2e8f0; justify-content: space-between; padding: 15px 20px;">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <i class="${opt.icon || 'fas fa-plus'}" style="font-size: 1.2rem; opacity: 0.8;"></i>
                                    <div style="text-align: left;">
                                        <div style="font-weight: 700; color: #0f172a;">${opt.label}</div>
                                        <div style="font-size: 0.75rem; color: #64748b; font-weight: 400;">${opt.description || ''}</div>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right" style="font-size: 0.8rem; opacity: 0.3;"></i>
                            </button>
                        `).join('')}
                    </div>
                </div>
            `,
            showConfirmButton: false,
            showCloseButton: true,
            width: '450px',
            padding: '0',
            background: '#ffffff',
            customClass: {
                popup: 'rounded-xl shadow-2xl'
            }
        });
    };

    // Run on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', labelTables);
    } else {
        labelTables();
    }

    // Also run after any AJAX complete if global jQuery exists
    if (window.jQuery) {
        $(document).ajaxComplete(function() {
            setTimeout(labelTables, 200);
        });
    }

})();
