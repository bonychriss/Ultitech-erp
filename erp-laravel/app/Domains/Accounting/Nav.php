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

        return [
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
                'id' => 'journal-entries',
                'title' => 'Journal Entries',
                'desc' => 'Create and review journal entries.',
                'href' => $u('accounting/journal-entries') . '?module=accounting',
                'icon' => 'book',
                'tone' => 'blue',
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
                'id' => 'journal-configuration',
                'title' => 'Journal Configuration',
                'desc' => 'Configure journal settings and posting rules.',
                'href' => $u('accounting/journal-configuration') . '?module=accounting',
                'icon' => 'gear',
                'tone' => 'violet',
            ],
            [
                'id' => 'trial-balance',
                'title' => 'Trial Balance',
                'desc' => 'Generate trial balance for a period.',
                'href' => $u('accounting/trial-balance') . '?module=accounting',
                'icon' => 'table',
                'tone' => 'green',
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
    }
}
