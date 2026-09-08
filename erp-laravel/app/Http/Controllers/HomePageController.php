<?php

namespace App\Http\Controllers;

use App\Domains\Home\HomeShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public marketing homepage via erp-laravel Blade + home-ui React dist.
 */
class HomePageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $cfg = $request->attributes->get('home_cfg');
        if (!is_array($cfg)) {
            $cfg = is_array($GLOBALS['ERP_HOME_CONTEXT'] ?? null)
                ? $GLOBALS['ERP_HOME_CONTEXT']
                : [];
        }

        $viewData = (new HomeShell())->viewData($cfg);
        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>UltiTech ERP</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>home-ui/frontend/</code>.</p>'
                . '</body></html>',
                503
            );
        }

        return view('erp.home-shell', $viewData);
    }
}
