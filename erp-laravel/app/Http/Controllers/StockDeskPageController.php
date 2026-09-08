<?php

namespace App\Http\Controllers;

use App\Domains\Stock\DeskShell;
use App\Domains\Stock\LegacyDeskBridge;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stock desk pages via erp-laravel (legacy PHP include bridge).
 */
class StockDeskPageController extends Controller
{
    public function show(Request $request, string $desk): Response
    {
        $desk = strtolower(trim($desk));
        if (!in_array($desk, DeskShell::laravelDesks(), true)) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Unknown stock desk</h1>'
                . '<p><code>' . htmlspecialchars($desk, ENT_QUOTES, 'UTF-8') . '</code></p>'
                . '</body></html>',
                404
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $result = (new LegacyDeskBridge())->render($desk);

        return response($result['html'], $result['ok'] ? 200 : 503)
            ->header('Content-Type', $result['contentType']);
    }
}
