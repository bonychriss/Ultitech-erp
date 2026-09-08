<?php

namespace App\Http\Controllers;

use App\Domains\Stock\LegacyDeskBridge;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stock home (dashboard) via erp-laravel.
 */
class StockPageController extends Controller
{
    public function show(Request $request): Response
    {
        $result = (new LegacyDeskBridge())->render('dashboard');

        return response($result['html'], $result['ok'] ? 200 : 503)
            ->header('Content-Type', $result['contentType']);
    }
}
