<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'System Administrator';
$_SESSION['company_id'] = 1;
$_SESSION['company_slug'] = 'ultimate';

require_once __DIR__ . '/includes/config.php';

echo "Rendering it_meeting_report.php...\n";
$_GET['print'] = '1';

ob_start();
include __DIR__ . '/reports/it_meeting_report.php';
$html = ob_get_clean();

if (!file_exists(__DIR__ . '/tmp')) {
    mkdir(__DIR__ . '/tmp', 0777, true);
}

$htmlFile = __DIR__ . '/tmp/report_temp.html';
file_put_contents($htmlFile, $html);
echo "HTML written to: $htmlFile\n";

$pdfFile = __DIR__ . '/reports/IT_Department_Meeting_Report.pdf';
if (file_exists($pdfFile)) {
    unlink($pdfFile);
}

echo "Generating PDF using Edge headless...\n";
$edgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
$cmd = sprintf(
    '"%s" --headless --disable-gpu --print-to-pdf="%s" "%s"',
    $edgePath,
    $pdfFile,
    $htmlFile
);

echo "Executing command: $cmd\n";
shell_exec($cmd);

if (file_exists($pdfFile) && filesize($pdfFile) > 0) {
    echo "SUCCESS! PDF generated successfully at: $pdfFile (" . filesize($pdfFile) . " bytes)\n";
} else {
    echo "ERROR: PDF generation failed.\n";
}
?>