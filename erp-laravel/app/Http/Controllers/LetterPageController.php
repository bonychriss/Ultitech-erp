<?php

namespace App\Http\Controllers;

use App\Domains\Letter\LetterShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Letter compose HTML shell via Blade.
 */
class LetterPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        return $this->render($request, 'compose', 'Letter', 'Letter');
    }

    /**
     * @param array<string,mixed>|null $erp
     */
    public static function clientCfg(?array $erp = null): array
    {
        $erp = $erp ?? [];
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $lib = $root . '/modules/letter/includes/letter-lib.php';
        if (is_file($lib)) {
            require_once $lib;
            if (function_exists('letterBuildClientCfg')) {
                return letterBuildClientCfg($erp);
            }
        }

        return [
            'module' => 'letter',
            'branding' => [
                'companyName' => (string) ($_SESSION['company_name'] ?? 'Company Name'),
                'tagline' => 'Your tagline here',
                'logoUrl' => '',
                'phone' => '',
                'email' => '',
                'website' => '',
                'address' => '',
                'accentColor' => '#E6B800',
                'accentShades' => ['#F7E08A', '#E6B800', '#C9A227', '#8B6914'],
            ],
            'user' => [
                'name' => (string) ($_SESSION['full_name'] ?? 'Your Name'),
                'title' => (string) ($_SESSION['department'] ?? 'Title'),
            ],
        ];
    }

    private function render(Request $request, string $page, string $pageTitle, string $headerTitle): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $viewData = (new LetterShell())->viewData([
            'letterPage' => $page,
            'pageTitle' => $pageTitle,
            'headerTitle' => $headerTitle,
            'clientCfg' => self::clientCfg(is_array($erp) ? $erp : []),
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
