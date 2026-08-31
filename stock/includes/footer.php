    </div> <!-- /.flex-grow-1 -->
</div> <!-- /.layout-main-wrapper -->

    <!-- Bootstrap 5 JS Bundle (Removed - Loaded in Header) -->
    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('.datatable').DataTable();
        });

        // SweetAlert Toast for Flash Messages (Stock module – consistent theme)
        if (!window.Toast) {
            window.Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                customClass: { popup: 'swal-stock-toast' },
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
        }

        <?php if(isset($_SESSION['success'])): ?>
            window.Toast.fire({
                icon: 'success',
                title: <?php echo json_encode($_SESSION['success']); ?>
            });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            window.Toast.fire({
                icon: 'error',
                title: <?php echo json_encode($_SESSION['error']); ?>
            });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if(isset($_SESSION['warning'])): ?>
            window.Toast.fire({
                icon: 'warning',
                title: <?php echo json_encode($_SESSION['warning']); ?>
            });
            <?php unset($_SESSION['warning']); ?>
        <?php endif; ?>

        <?php if(isset($_SESSION['info'])): ?>
            window.Toast.fire({
                icon: 'info',
                title: <?php echo json_encode($_SESSION['info']); ?>
            });
            <?php unset($_SESSION['info']); ?>
        <?php endif; ?>
    </script>
</body>
</html>
