<?php

namespace App\Http\Controllers;

use App\Domains\Trial\TrialShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public free-trial signup page via erp-laravel Blade + login-ui React dist.
 */
class TrialPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $cfg = $request->attributes->get('trial_cfg');
        if (!is_array($cfg)) {
            $cfg = is_array($GLOBALS['ERP_TRIAL_CONTEXT'] ?? null)
                ? $GLOBALS['ERP_TRIAL_CONTEXT']
                : [];
        }

        $viewData = (new TrialShell())->viewData($cfg);
        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Start Free Trial</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>login-ui/frontend/</code>.</p>'
                . '</body></html>',
                503
            );
        }

        return view('erp.trial-shell', $viewData);
    }
}
