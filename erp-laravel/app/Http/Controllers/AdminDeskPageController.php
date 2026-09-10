<?php

namespace App\Http\Controllers;

use App\Domains\Admin\DeskShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Admin desks via erp-laravel (React shells under admin/*-ui).
 */
class AdminDeskPageController extends Controller
{
    public function show(Request $request, string $desk): View|Response
    {
        $desk = strtolower(trim($desk));
        if (!in_array($desk, DeskShell::laravelDesks(), true)) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Unknown admin desk</h1>'
                . '<p><code>' . htmlspecialchars($desk, ENT_QUOTES, 'UTF-8') . '</code></p>'
                . '</body></html>',
                404
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if (function_exists('requireAdmin')) {
            requireAdmin();
        } elseif (function_exists('requireLogin')) {
            requireLogin();
        }

        $erp = $request->attributes->get('erp') ?? [];
        $apiBase = function_exists('app_url')
            ? rtrim((string) app_url('/admin/email-settings-ui/api'), '/')
            : '/public_html/admin/email-settings-ui/api';

        $viewData = (new DeskShell())->viewData($desk, [
            'apiBase' => $apiBase,
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Email settings UI missing</h1>'
                . '<p>Build <code>admin/email-settings-ui/frontend</code> with npm.</p>'
                . '</body></html>',
                503
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return view('erp.react-shell', $viewData);
    }
}
