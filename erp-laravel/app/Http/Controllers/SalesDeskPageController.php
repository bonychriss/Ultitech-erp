<?php

namespace App\Http\Controllers;

use App\Domains\Sales\DeskShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Sales desk HTML shells via Blade (Phase 2).
 */
class SalesDeskPageController extends Controller
{
    public function show(Request $request, string $desk): View|Response
    {
        $desk = strtolower(trim($desk));
        $shell = new DeskShell();

        try {
            $viewData = $shell->viewData($desk);
        } catch (RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'required') ? 400 : 404;

            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Sales desk</h1>'
                . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
                . '</body></html>',
                $status
            );
        } catch (Throwable $e) {
            report($e);

            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Sales desk error</h1>'
                . '<p>' . htmlspecialchars($e->getMessage() !== '' ? $e->getMessage() : 'Unexpected error.', ENT_QUOTES, 'UTF-8') . '</p>'
                . '</body></html>',
                500
            );
        }

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Sales desk UI not available</h1>'
                . '<p>Unknown desk or React dist missing for <code>'
                . htmlspecialchars($desk, ENT_QUOTES, 'UTF-8')
                . '</code>. Build the matching <code>modules/sales/.../frontend</code>.</p>'
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
