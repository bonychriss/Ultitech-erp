<?php
/**
 * Centered SweetAlert2 success modal with Lottie animation (after payment registered).
 */
$salesPaymentLottieFlash = null;
if (!empty($_SESSION['sales_payment_lottie_success'])) {
    $salesPaymentLottieFlash = $_SESSION['sales_payment_lottie_success'];
    unset($_SESSION['sales_payment_lottie_success']);
}

if ($salesPaymentLottieFlash === null || $salesPaymentLottieFlash === '') {
    return;
}

$paymentLottieMessage = is_array($salesPaymentLottieFlash)
    ? trim((string) ($salesPaymentLottieFlash['message'] ?? 'Payment registered successfully.'))
    : trim((string) $salesPaymentLottieFlash);
if ($paymentLottieMessage === '') {
    $paymentLottieMessage = 'Payment registered successfully.';
}
if (is_array($salesPaymentLottieFlash)) {
    $paidAmount = (float) ($salesPaymentLottieFlash['amount'] ?? 0);
    $paidCurrency = trim((string) ($salesPaymentLottieFlash['currency'] ?? ''));
    if ($paidAmount > 0 && $paidCurrency !== '') {
        $paymentLottieMessage .= ' (' . $paidCurrency . ' ' . number_format($paidAmount, 2) . ')';
    }
}

$paymentLottieTitle = is_array($salesPaymentLottieFlash)
    ? trim((string) ($salesPaymentLottieFlash['title'] ?? 'Payment recorded'))
    : 'Payment recorded';
if ($paymentLottieTitle === '') {
    $paymentLottieTitle = 'Payment recorded';
}

$balancesLottieBase = function_exists('app_url')
    ? rtrim((string) app_url('/modules/balances'), '/')
    : '/modules/balances';
$paymentLottieJsonUrl = $balancesLottieBase . '/assets/lottie/voucher-success.json';
$paymentLottieFile = dirname(__DIR__, 3) . '/balances/assets/lottie/voucher-success.json';
if (!is_readable($paymentLottieFile)) {
    $paymentLottieFile = dirname(__DIR__, 4) . '/erp/petty-cash/assets/lottie/voucher-success.json';
    if (is_readable($paymentLottieFile)) {
        $pettyBase = function_exists('app_url')
            ? rtrim((string) app_url('/erp/petty-cash'), '/')
            : '/erp/petty-cash';
        $paymentLottieJsonUrl = $pettyBase . '/assets/lottie/voucher-success.json';
    }
}

$paymentLottieInline = null;
if (is_readable($paymentLottieFile)) {
    $decoded = json_decode((string) file_get_contents($paymentLottieFile), true);
    if (is_array($decoded)) {
        $paymentLottieInline = $decoded;
    }
}
?>
<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js" as="script" crossorigin="anonymous">
<?php if ($paymentLottieJsonUrl !== ''): ?>
<link rel="preload" href="<?= htmlspecialchars($paymentLottieJsonUrl, ENT_QUOTES, 'UTF-8') ?>" as="fetch" crossorigin="anonymous">
<?php endif; ?>
<style>
    .sales-payment-success-popup.swal2-popup {
        border-radius: 20px;
        padding: 1.25rem 1.25rem 1.5rem;
    }
    .sales-payment-success-popup .swal2-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0f172a;
        padding-top: 0.25rem;
    }
    .sales-payment-success-popup .swal2-html-container {
        margin: 0.5rem 0 0;
        overflow: visible;
    }
    .sales-payment-success-popup .swal2-confirm {
        border-radius: 10px;
        font-weight: 600;
        padding: 0.65rem 1.5rem;
        min-width: 120px;
    }
    #sales-payment-lottie-root {
        width: 100%;
        max-width: 240px;
        height: 220px;
        margin: 0 auto 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    #sales-payment-lottie-root .sales-payment-lottie-fallback {
        width: 88px;
        height: 88px;
        border-radius: 50%;
        background: #dcfce7;
        color: #16a34a;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
    }
    .sales-payment-success-msg {
        margin: 0;
        color: #475569;
        font-size: 0.9375rem;
        line-height: 1.5;
        font-weight: 500;
        text-align: center;
    }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Swal === 'undefined') {
        return;
    }
    window.salesPaymentSuccessShown = true;

    var title = <?= json_encode($paymentLottieTitle, JSON_UNESCAPED_UNICODE) ?>;
    var message = <?= json_encode($paymentLottieMessage, JSON_UNESCAPED_UNICODE) ?>;
    var jsonUrl = <?= json_encode($paymentLottieJsonUrl, JSON_UNESCAPED_SLASHES) ?>;
    var inlineData = <?= $paymentLottieInline !== null
        ? json_encode($paymentLottieInline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : 'null' ?>;

    Swal.fire({
        title: title,
        icon: false,
        html: '<div id="sales-payment-lottie-root"><div class="sales-payment-lottie-fallback" aria-hidden="true"><i class="fas fa-circle-check"></i></div></div>'
            + '<p class="sales-payment-success-msg"></p>',
        showConfirmButton: true,
        confirmButtonText: 'Done',
        confirmButtonColor: '#2563eb',
        allowOutsideClick: false,
        allowEscapeKey: true,
        width: '420px',
        padding: '1.25rem',
        customClass: { popup: 'sales-payment-success-popup' },
        didOpen: function () {
            var msgEl = Swal.getHtmlContainer();
            if (msgEl) {
                var p = msgEl.querySelector('.sales-payment-success-msg');
                if (p) {
                    p.textContent = message;
                }
            }
            var root = document.getElementById('sales-payment-lottie-root');
            if (!root || typeof lottie === 'undefined') {
                return;
            }
            var opts = {
                container: root,
                renderer: 'svg',
                loop: false,
                autoplay: true,
                rendererSettings: { progressiveLoad: false, hideOnTransparent: true }
            };
            if (inlineData) {
                opts.animationData = inlineData;
            } else if (jsonUrl) {
                opts.path = jsonUrl;
            } else {
                return;
            }
            try {
                var anim = lottie.loadAnimation(opts);
                root.classList.add('has-lottie');
                anim.addEventListener('DOMLoaded', function () {
                    var fb = root.querySelector('.sales-payment-lottie-fallback');
                    if (fb) {
                        fb.style.display = 'none';
                    }
                });
            } catch (e) {
                /* keep fa fallback */
            }
        }
    });
});
</script>
