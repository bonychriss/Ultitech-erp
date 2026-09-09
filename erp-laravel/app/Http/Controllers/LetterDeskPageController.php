<?php

namespace App\Http\Controllers;

use App\Domains\Letter\DeskShell;
use App\Domains\Letter\LetterShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Letter desk pages via erp-laravel (React shell).
 */
class LetterDeskPageController extends Controller
{
    public function show(Request $request, string $desk): View|Response
    {
        $desk = strtolower(trim($desk));
        if (!in_array($desk, DeskShell::laravelDesks(), true)) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Unknown letter desk</h1>'
                . '<p><code>' . htmlspecialchars($desk, ENT_QUOTES, 'UTF-8') . '</code></p>'
                . '</body></html>',
                404
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $erp = $request->attributes->get('erp') ?? [];
        $meta = DeskShell::reactDesks()[$desk];

        $viewData = (new LetterShell())->viewData([
            'letterPage' => $meta['page'],
            'pageTitle' => $meta['title'],
            'headerTitle' => $meta['header'],
            'clientCfg' => LetterPageController::clientCfg(is_array($erp) ? $erp : []),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Letter UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>modules/letter/frontend</code>.</p>'
                . '</body></html>',
                503
            );
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];
        $page_title = $viewData['pageTitle'];
        $employeeHeaderTitle = $viewData['employeeHeaderTitle'];
        $hideHeaderCompanyBranding = $viewData['hideHeaderCompanyBranding'];
        $employeeHeaderExtraClass = $viewData['employeeHeaderExtraClass'];

        return view('erp.react-shell', $viewData + [
            'page_title' => $page_title,
            'employeeHeaderTitle' => $employeeHeaderTitle,
            'hideHeaderCompanyBranding' => $hideHeaderCompanyBranding,
            'employeeHeaderExtraClass' => $employeeHeaderExtraClass,
        ]);
    }
}
