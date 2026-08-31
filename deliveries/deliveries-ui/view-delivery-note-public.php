<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Note <?= htmlspecialchars((string) $note['note_number']) ?></title>
    <?php echo function_exists('sales_document_font_stylesheet_links') ? sales_document_font_stylesheet_links($salesSettings) : ''; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body {
            font-family: <?= $docFontStack ?>;
            color: #1f2937;
            background: #f3f4f6;
            margin: 0;
            padding: 40px;
            font-size: 13px;
            line-height: 1.5;
            -webkit-print-color-adjust: exact;
        }
        .no-print-toolbar {
            max-width: 210mm;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .btn-action {
            padding: 8px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
        }
        .btn-primary { background: #1e3a8a; color: white; }
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .no-print-toolbar { display: none; }
        }
    </style>
</head>
<body>
    <?php if ($signed): ?>
    <div class="no-print-toolbar">
        <a href="javascript:void(0)" onclick="downloadPDF()" class="btn-action btn-primary">Download</a>
    </div>
    <?php endif; ?>

    <?= $documentHtml ?>

    <script>
        function downloadPDF() {
            if (typeof html2pdf === 'undefined') return;
            const element = document.querySelector('#delivery-note-content .page-container');
            if (!element) return;
            html2pdf().set({
                margin: 0,
                filename: '<?= $note['note_number'] ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            }).from(element).save();
        }
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('download') && <?= $signed ? 'true' : 'false' ?>) {
                setTimeout(downloadPDF, 1500);
            }
        });
    </script>
</body>
</html>
