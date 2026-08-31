<?php
require_once '../../includes/config.php';
require_once '../../includes/mailer.php';
require_once 'functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();

$type = $_REQUEST['type'] ?? ''; // Accept GET or POST
$id = $_REQUEST['id'] ?? 0;

if (!$id || !$type) {
    die("Invalid request.");
}

// Fetch Document
if ($type === 'order') {
    $stmt = $pdo->prepare("SELECT so.*, c.company_name, c.contact_person, c.email FROM sales_orders so JOIN customers c ON so.customer_id = c.id WHERE so.id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    $docNumber = $doc['order_number'];
    $docTitle = ($doc['status'] === 'quotation') ? 'Quotation' : 'Sales Order';
    $amount = $doc['total_amount'];
    $currency = $doc['currency'];
} elseif ($type === 'invoice') {
    $stmt = $pdo->prepare("SELECT i.*, c.company_name, c.contact_person, c.email FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    $docNumber = $doc['invoice_number'];
    $docTitle = 'Invoice';
    $amount = $doc['total_amount'];
    $currency = $doc['currency'] ?? 'TZS';
} else {
    die("Unknown document type.");
}

if (!$doc) {
    die("Document not found.");
}

if (empty($doc['email'])) {
    die("Customer has no email address.");
}

// Fetch Settings for Company Name logic if needed, or use static
$company_settings = $pdo->query("SELECT * FROM sales_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$companyName = $company_settings['company_name'] ?? 'Ultimate General Trading';

// Handle Attachment
$attachments = [];
if (isset($_POST['pdf_base64']) && !empty($_POST['pdf_base64'])) {
    $pdfData = $_POST['pdf_base64'];
    if (strpos($pdfData, 'base64,') !== false) {
        $pdfData = explode('base64,', $pdfData)[1];
    }
    $attachments[] = [
        'content' => base64_decode($pdfData),
        'name' => $docTitle . '_' . $docNumber . '.pdf',
        'type' => 'application/pdf'
    ];
}

// Fetch Sender Name
$senderName = 'Sales Team';
if (isset($_SESSION['user_id'])) {
    $stmtSender = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmtSender->execute([$_SESSION['user_id']]);
    $userRow = $stmtSender->fetch();
    if ($userRow && !empty($userRow['full_name'])) {
        $senderName = $userRow['full_name'];
    }
}

// Update Body (User Template)
// Subject: Quotation for your request – Ultimate System [#SO-2026-00005]
$subject = "$docTitle for your request - $companyName [#$docNumber]";

// Body
$body = "Dear " . ($doc['contact_person'] ?: 'Customer') . ",<br><br>";
$body .= "It was a pleasure speaking with you.<br>";
$body .= "We have prepared a tailored $docTitle for you based on our discussion. We are confident that this offer provides the best value and quality for your requirements.<br><br>";
$body .= "Please find the attached $docTitle #$docNumber.<br><br>";
$body .= "If you have any questions or would like to adjust any details, please let me know. I am happy to help!<br><br>";
$body .= "Best regards,<br>";
$body .= "<strong>$senderName</strong>";

if (sendEmail($doc['email'], $subject, $body, true, $attachments)) {
    // Redirect back with success
    $redirect = ($type === 'invoice') ? "invoices/view.php?id=$id" : "orders/view.php?id=$id";
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>body { font-family: sans-serif; background: #f0f2f5; }</style>
    </head>
    <body>
        <script>
            Swal.fire({
                title: 'Email Sent!',
                text: 'Email sent successfully to <?php echo $doc['email']; ?>!',
                icon: 'success',
                confirmButtonColor: '#008784',
                confirmButtonText: 'OK'
            }).then((result) => {
                window.location.href = '<?php echo $redirect; ?>';
            });
        </script>
    </body>
    </html>
    <?php
} else {
    echo "Failed to send email. Check error logs.";
}
?>
