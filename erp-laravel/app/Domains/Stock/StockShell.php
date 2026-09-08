<?php

namespace App\Domains\Stock;

/**
 * Stock React asset helpers (used when desks move to Blade shells).
 */
final class StockShell
{
    /**
     * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
     */
    public function loadAssets(): ?array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $cssFile = 'stock-ui.css';
        $jsFile = 'stock-ui.js';
        $cssPath = $root . '/stock/stock-ui/dist/assets/' . $cssFile;
        $jsPath = $root . '/stock/stock-ui/dist/assets/' . $jsFile;
        if (!is_file($jsPath)) {
            return null;
        }

        $assetBase = function_exists('app_url')
            ? rtrim((string) app_url('/stock/stock-ui/dist/assets/'), '/') . '/'
            : '/public_html/stock/stock-ui/dist/assets/';

        return [
            'assetBase' => $assetBase,
            'cssFile' => $cssFile,
            'jsFile' => $jsFile,
            'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
            'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        ];
    }
}
