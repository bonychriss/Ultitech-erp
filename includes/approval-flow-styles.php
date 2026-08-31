<?php
/**
 * Inline Approval Flow CSS so layout works even if external stylesheet path 404s.
 */
$approvalFlowCssPath = dirname(__DIR__) . '/assets/css/approval-flow.css';
if (is_readable($approvalFlowCssPath)) {
    $approvalFlowCss = file_get_contents($approvalFlowCssPath);
    if ($approvalFlowCss !== false && $approvalFlowCss !== '') {
        echo '<style id="approval-flow-styles">' . "\n" . $approvalFlowCss . "\n" . '</style>' . "\n";
    }
}
