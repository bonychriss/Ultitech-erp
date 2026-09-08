<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inject ERP session context (set by public_html/sales.php or future erp entry).
 */
class AttachErpContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $erp = $GLOBALS['ERP_CONTEXT']
            ?? $GLOBALS['ERP_SALES_CONTEXT']
            ?? $GLOBALS['ERP_SUGGEST_CONTEXT']
            ?? $GLOBALS['ERP_STOCK_CONTEXT']
            ?? null;
        if (!is_array($erp) || empty($erp['user_id'])) {
            abort(401, 'ERP login required. Open this page from the ERP menu.');
        }

        $request->attributes->set('erp', $erp);

        $dbName = trim((string) ($erp['db_name'] ?? ''));
        $mysql = [
            'driver' => 'mysql',
        ];
        if (defined('DB_HOST')) {
            $mysql['host'] = (string) DB_HOST;
        }
        if (defined('DB_USER')) {
            $mysql['username'] = (string) DB_USER;
        }
        if (defined('DB_PASS')) {
            $mysql['password'] = (string) DB_PASS;
        }
        if ($dbName !== '') {
            $mysql['database'] = $dbName;
        } elseif (defined('DB_NAME')) {
            $mysql['database'] = (string) DB_NAME;
        }

        if (count($mysql) > 1) {
            config(['database.default' => 'mysql']);
            foreach ($mysql as $key => $value) {
                config(["database.connections.mysql.{$key}" => $value]);
            }
            app('db')->purge('mysql');
        }

        return $next($request);
    }
}
