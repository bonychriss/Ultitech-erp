<?php

namespace App\Domains\Accounting;

/**
 * Company-aware navigation targets for the Accounting React hub.
 */
final class Nav
{
    /**
     * @return list<array{id:string,title:string,desc:string,href:string,icon:string,tone:string}>
     */
    public static function sections(string $companySlug = ''): array
    {
        $u = static function (string $path) use ($companySlug): string {
            if (function_exists('company_url') && $companySlug !== '') {
                return (string) company_url($path, $companySlug);
            }

            return function_exists('app_url')
                ? (string) app_url('/' . ltrim($path, '/'))
                : '/' . ltrim($path, '/');
        };

        $expensesIcon = function_exists('app_url')
            ? (string) app_url('/modules/accounting/assets/expenses-icon.png')
            : '/modules/accounting/assets/expenses-icon.png';

        $sections = [
            [
                'id' => 'balances',
                'title' => 'Balances',
                'desc' => 'Liquidity dashboard, accounts, transfers, transactions.',
                'href' => $u('modules/balances/index') . '?module=balances',
                'icon' => 'scale',
                'tone' => 'cyan',
            ],
            [
                'id' => 'revenue',
                'title' => 'Revenue',
                'desc' => 'Record income, manage revenue entries and credit notes.',
                'href' => $u('revenue_entries') . '?module=revenue',
                'icon' => 'coins',
                'tone' => 'amber',
            ],
            [
                'id' => 'expenses',
                'title' => 'Expenses',
                'desc' => 'Record and manage expense vouchers and payees.',
                'href' => $u('modules/expenses/index') . '?module=expenses',
                'icon' => 'expenses',
                'iconUrl' => $expensesIcon,
                'tone' => 'green',
            ],
            [
                'id' => 'purchases',
                'title' => 'Purchases',
                'desc' => 'Stock Purchase payment vouchers linked to procurement.',
                'href' => $u('modules/purchases/index') . '?module=purchases',
                'icon' => 'cart',
                'tone' => 'blue',
            ],
            [
                'id' => 'journal-entries',
                'title' => 'Journal Entries',
                'desc' => 'Create and review journal entries.',
                'href' => $u('accounting/journal-entries') . '?module=accounting',
                'icon' => 'book',
                'tone' => 'blue',
            ],
            [
                'id' => 'vat-control',
                'title' => 'VAT Control',
                'desc' => 'Monitor output and input VAT, drill into periods, and close months.',
                'href' => $u('accounting/vat-control') . '?module=accounting',
                'icon' => 'percent',
                'tone' => 'amber',
            ],
            [
                'id' => 'settings',
                'title' => 'Accounting Settings',
                'desc' => 'Default sales revenue account and other GL posting defaults.',
                'href' => $u('accounting/settings') . '?module=accounting',
                'icon' => 'sliders',
                'tone' => 'violet',
            ],
            [
                'id' => 'financial-statements',
                'title' => 'Financial Statements',
                'desc' => 'Profit & Loss, Balance Sheet, Cash Flow, Trial Balance and General Ledger.',
                'href' => '#financial-statements',
                'icon' => 'statements',
                'tone' => 'green',
                'children' => [
                    [
                        'id' => 'trial-balance',
                        'title' => 'Trial Balance',
                        'desc' => 'Review debit and credit balances across all accounts.',
                        'href' => $u('accounting/trial-balance') . '?module=accounting',
                        'icon' => 'table',
                        'tone' => 'green',
                    ],
                    [
                        'id' => 'profit-loss',
                        'title' => 'Profit & Loss',
                        'desc' => 'Review revenue, expenses and net profit or loss.',
                        'href' => $u('accounting/profit-loss') . '?module=accounting',
                        'icon' => 'chart',
                        'tone' => 'blue',
                    ],
                    [
                        'id' => 'balance-sheet',
                        'title' => 'Balance Sheet',
                        'desc' => 'Review assets, liabilities and equity as of a date.',
                        'href' => $u('accounting/balance-sheet') . '?module=accounting',
                        'icon' => 'balance',
                        'tone' => 'violet',
                    ],
                    [
                        'id' => 'cash-flow',
                        'title' => 'Cash Flow',
                        'desc' => 'Review money moving through cash and bank accounts.',
                        'href' => $u('accounting/cash-flow') . '?module=accounting',
                        'icon' => 'cash',
                        'tone' => 'cyan',
                    ],
                    [
                        'id' => 'general-ledger',
                        'title' => 'General Ledger',
                        'desc' => 'Browse account postings and running balances.',
                        'href' => $u('accounting/ledger') . '?module=accounting',
                        'icon' => 'ledger',
                        'tone' => 'amber',
                    ],
                ],
            ],
            [
                'id' => 'reconciliation',
                'title' => 'Reconciliation',
                'desc' => 'Match bank statements with system transactions.',
                'href' => $u('accounting/reconciliation') . '?module=accounting',
                'icon' => 'link',
                'tone' => 'cyan',
            ],
        ];

        self::loadBridgeHelper();
        $mode = function_exists('ultitech_accounting_mode') ? ultitech_accounting_mode() : 'parallel';
        if ($mode !== 'off') {
            $bridgeHref = function_exists('ultitech_accounting_bridge_path')
                ? ultitech_accounting_bridge_path($companySlug)
                : $u('accounting/ultitech-bridge') . '?module=accounting';

            $sections[] = [
                'id' => 'ultitech-accounting',
                'title' => $mode === 'primary' ? 'Accounting (New)' : 'New Accounting',
                'desc' => 'Open the upgraded Accounting module inside ERP. Use Classic Accounting anytime to roll back.',
                'href' => $bridgeHref,
                'icon' => 'statements',
                'tone' => 'violet',
            ];
            if (function_exists('ultitech_accounting_classic_path')) {
                $sections[] = [
                    'id' => 'classic-accounting',
                    'title' => 'Classic Accounting',
                    'desc' => 'Open the previous Accounting hub (fallback while upgrading).',
                    'href' => ultitech_accounting_classic_path($companySlug),
                    'icon' => 'book',
                    'tone' => 'cyan',
                ];
            }
        }

        return $sections;
    }

    private static function loadBridgeHelper(): void
    {
        if (function_exists('ultitech_accounting_mode')) {
            return;
        }

        $root = rtrim((string) config('erp.app_root'), '\\/');
        $helper = $root . '/includes/ultitech_accounting_bridge.php';
        if (is_file($helper)) {
            require_once $helper;
        }
    }
}
