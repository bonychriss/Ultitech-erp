/**
 * Stock module: consistent SweetAlert2 + redirect helper
 * Use from PHP or React: StockAlert.success('Saved!', 'index.php');
 */
(function () {
    'use strict';
    var theme = {
        confirmColor: '#0d9488',
        cancelColor: '#64748b'
    };
    function fire(opts) {
        if (typeof Swal === 'undefined') {
            if (opts.redirect) window.location.href = opts.redirect;
            return Promise.resolve();
        }
        return Swal.fire({
            confirmButtonColor: theme.confirmColor,
            cancelButtonColor: theme.cancelColor,
            customClass: { confirmButton: 'swal-stock-confirm' },
            ...opts
        }).then(function (result) {
            if (opts.redirect && (result.isConfirmed !== false)) window.location.href = opts.redirect;
            return result;
        });
    }
    window.StockAlert = {
        success: function (message, redirectUrl) {
            return fire({
                title: 'Success',
                text: message || 'Done.',
                icon: 'success',
                confirmButtonText: 'OK',
                redirect: redirectUrl || null
            });
        },
        error: function (message, redirectUrl) {
            return fire({
                title: 'Error',
                text: message || 'Something went wrong.',
                icon: 'error',
                confirmButtonText: 'OK',
                redirect: redirectUrl || null
            });
        },
        warning: function (message, redirectUrl) {
            return fire({
                title: 'Warning',
                text: message || 'Please check and try again.',
                icon: 'warning',
                confirmButtonText: 'OK',
                redirect: redirectUrl || null
            });
        },
        confirm: function (message, title, onConfirm) {
            title = title || 'Confirm';
            return Swal.fire({
                title: title,
                text: message || 'Are you sure?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: theme.confirmColor,
                cancelButtonColor: theme.cancelColor,
                confirmButtonText: 'Yes',
                cancelButtonText: 'Cancel'
            }).then(function (result) {
                if (result.isConfirmed && typeof onConfirm === 'function') onConfirm();
                return result;
            });
        }
    };
})();
