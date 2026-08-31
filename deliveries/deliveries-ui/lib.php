<?php

declare(strict_types=1);

function deliveriesUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/deliveries/deliveries-ui'), '/') . '/' . $relativePath;
    }
    return '/deliveries/deliveries-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string,apiUrl:string,actionUrl:string}|null
 */
function deliveriesUiLoadReactAssets(): ?array
{
    $uiDir = __DIR__ . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => deliveriesUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        'apiUrl' => deliveriesUiPublicUrl('api/init.php'),
        'actionUrl' => deliveriesUiPublicUrl('api/action.php'),
        'createInitUrl' => deliveriesUiPublicUrl('api/create-init.php'),
        'createSubmitUrl' => deliveriesUiPublicUrl('api/create-delivery.php'),
        'createNoteInitUrl' => deliveriesUiPublicUrl('api/create-delivery-note-init.php'),
        'createNoteSubmitUrl' => deliveriesUiPublicUrl('api/create-delivery-note.php'),
        'myDeliveriesInitUrl' => deliveriesUiPublicUrl('api/my-deliveries-init.php'),
        'orderDetailsInitUrl' => deliveriesUiPublicUrl('api/order-details-init.php'),
        'uploadEvidenceUrl' => deliveriesUiPublicUrl('api/upload-evidence.php'),
        'checkSignatureUrl' => deliveriesUiPublicUrl('api/check-signature.php'),
        'submitClientSignatureUrl' => deliveriesUiPublicUrl('api/submit-client-signature.php'),
        'submitFeedbackUrl' => deliveriesUiPublicUrl('api/submit-feedback.php'),
        'aiSearchUrl' => deliveriesUiPublicUrl('api/ai-search.php'),
        'aiSearchNotesUrl' => deliveriesUiPublicUrl('api/ai-search-notes.php'),
        'deliveryNotesInitUrl' => deliveriesUiPublicUrl('api/delivery-notes-init.php'),
        'deliveryNoteViewInitUrl' => deliveriesUiPublicUrl('api/delivery-note-view-init.php'),
        'customerReviewsInitUrl' => deliveriesUiPublicUrl('api/customer-reviews-init.php'),
        'kpiAiAssistUrl' => deliveriesUiPublicUrl('api/kpi-ai-assist.php'),
        'gradeFeedbackUrl' => deliveriesUiPublicUrl('api/grade-feedback.php'),
        'estimateRoutePriceUrl' => deliveriesUiPublicUrl('api/estimate-route-price.php'),
    ];
}
