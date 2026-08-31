    </div> <!-- /.flex-grow-1 -->
</div> <!-- /.layout-main-wrapper -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <script>
        window.balIsMobileViewport = window.balIsMobileViewport || function () {
            if (window.matchMedia('(max-width: 768px)').matches) {
                return true;
            }
            if (window.matchMedia('(hover: none) and (pointer: coarse)').matches) {
                return window.innerWidth <= 1024;
            }
            return false;
        };
        window.balInstallToastMobileGuard = window.balInstallToastMobileGuard || function () {
            if (!window.Toast || typeof window.Toast.fire !== 'function' || window.Toast.__balMobileWrapped) {
                return;
            }
            var baseFire = window.Toast.fire.bind(window.Toast);
            window.Toast.fire = function (options) {
                var opts = options || {};
                var icon = String(opts.icon || '').toLowerCase();
                if (icon === 'success' && (window.balSuppressSuccessToast || window.balLottieSuccessShown)) {
                    return Promise.resolve({
                        isConfirmed: false,
                        isDenied: false,
                        isDismissed: true
                    });
                }
                if (window.balIsMobileViewport()) {
                    return Promise.resolve({
                        isConfirmed: false,
                        isDenied: false,
                        isDismissed: true
                    });
                }
                return baseFire(options);
            };
            window.Toast.__balMobileWrapped = true;
        };
        $(document).ready(function () {
            $('.datatable').DataTable();
        });
        if (!window.Toast) {
            window.Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
        }
        window.balInstallToastMobileGuard();
        <?php
        if (!isset($bal_lottie_flash_captured)) {
            $bal_lottie_flash_captured = true;
            $bal_lottie_pending = $_SESSION['bal_lottie_success'] ?? ($_SESSION['success'] ?? '');
            $bal_lottie_show_success = $bal_lottie_pending !== '';
            $bal_lottie_success_message = (string) $bal_lottie_pending;
            if ($bal_lottie_show_success) {
                unset($_SESSION['bal_lottie_success'], $_SESSION['success']);
            }
        }
        ?>
        window.balSuppressSuccessToast = <?= !empty($bal_lottie_show_success) ? 'true' : 'false' ?>;
        <?php if (empty($bal_lottie_show_success) && !empty($_SESSION['success'])): ?>
            window.Toast.fire({ icon: 'success', title: '<?php echo addslashes((string) $_SESSION['success']); ?>' });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            window.Toast.fire({ icon: 'error', title: '<?php echo addslashes($_SESSION['error']); ?>' });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
    </script>
<?php
require __DIR__ . '/lottie-form-overlay.php';
?>
    <script>
        window.balInstallToastMobileGuard();
    </script>
</body>
</html>
