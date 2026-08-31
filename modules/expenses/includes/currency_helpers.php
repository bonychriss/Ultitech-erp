<?php

if (!function_exists('expenses_currency_iso')) {
    function expenses_currency_iso(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === 'TSH') {
            return 'TZS';
        }

        return $code;
    }
}

if (!function_exists('expenses_is_base_currency')) {
    function expenses_is_base_currency(string $code): bool
    {
        return expenses_currency_iso($code) === 'TZS';
    }
}

if (!function_exists('expenses_currency_display_code')) {
    function expenses_currency_display_code(string $code): string
    {
        $iso = expenses_currency_iso($code);
        if ($iso === 'TZS') {
            return 'TSh';
        }

        return $iso;
    }
}

if (!function_exists('expenses_currency_name')) {
    function expenses_currency_name(string $code): string
    {
        $iso = expenses_currency_iso($code);
        $names = [
            'TZS' => 'Tanzanian Shilling',
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'KES' => 'Kenyan Shilling',
            'UGX' => 'Ugandan Shilling',
            'RWF' => 'Rwandan Franc',
            'ZAR' => 'South African Rand',
            'CNY' => 'Chinese Yuan',
            'INR' => 'Indian Rupee',
            'AED' => 'UAE Dirham',
            'SAR' => 'Saudi Riyal',
            'JPY' => 'Japanese Yen',
            'CHF' => 'Swiss Franc',
            'CAD' => 'Canadian Dollar',
            'AUD' => 'Australian Dollar',
            'SGD' => 'Singapore Dollar',
            'HKD' => 'Hong Kong Dollar',
            'NGN' => 'Nigerian Naira',
            'GHS' => 'Ghanaian Cedi',
            'ZMW' => 'Zambian Kwacha',
            'MZN' => 'Mozambican Metical',
            'BIF' => 'Burundian Franc',
            'MWK' => 'Malawian Kwacha',
            'EGP' => 'Egyptian Pound',
            'QAR' => 'Qatari Riyal',
            'KWD' => 'Kuwaiti Dinar',
            'OMR' => 'Omani Rial',
            'BHD' => 'Bahraini Dinar',
            'SEK' => 'Swedish Krona',
            'NOK' => 'Norwegian Krone',
            'DKK' => 'Danish Krone',
            'PLN' => 'Polish Zloty',
            'TRY' => 'Turkish Lira',
            'BRL' => 'Brazilian Real',
            'MXN' => 'Mexican Peso',
        ];

        return $names[$iso] ?? $iso;
    }
}

if (!function_exists('expenses_currency_flag_country')) {
    function expenses_currency_flag_country(string $code): string
    {
        $iso = expenses_currency_iso($code);
        $map = [
            'TZS' => 'tz',
            'USD' => 'us',
            'EUR' => 'eu',
            'GBP' => 'gb',
            'KES' => 'ke',
            'UGX' => 'ug',
            'RWF' => 'rw',
            'ZAR' => 'za',
            'CNY' => 'cn',
            'INR' => 'in',
            'AED' => 'ae',
            'SAR' => 'sa',
            'JPY' => 'jp',
            'CHF' => 'ch',
            'CAD' => 'ca',
            'AUD' => 'au',
            'SGD' => 'sg',
            'HKD' => 'hk',
            'NGN' => 'ng',
            'GHS' => 'gh',
            'ZMW' => 'zm',
            'MZN' => 'mz',
            'BIF' => 'bi',
            'MWK' => 'mw',
            'EGP' => 'eg',
            'QAR' => 'qa',
            'KWD' => 'kw',
            'OMR' => 'om',
            'BHD' => 'bh',
            'SEK' => 'se',
            'NOK' => 'no',
            'DKK' => 'dk',
            'PLN' => 'pl',
            'TRY' => 'tr',
            'BRL' => 'br',
            'MXN' => 'mx',
            'ATS' => 'at',
            'BWP' => 'bw',
            'CUC' => 'cu',
            'DZD' => 'dz',
            'IQD' => 'iq',
            'IRR' => 'ir',
            'KRW' => 'kr',
            'MYR' => 'my',
            'MZM' => 'mz',
            'NAD' => 'na',
            'NLG' => 'nl',
            'NZD' => 'nz',
            'PKR' => 'pk',
            'ZWD' => 'zw',
            'SDR' => '',
        ];

        return $map[$iso] ?? '';
    }
}

if (!function_exists('expenses_currency_flag_url')) {
    function expenses_currency_flag_url(string $code): string
    {
        $country = expenses_currency_flag_country($code);
        if ($country !== '') {
            return 'https://flagcdn.com/w40/' . $country . '.png';
        }

        return 'data:image/svg+xml,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">'
            . '<circle cx="12" cy="12" r="10" fill="#e2e8f0"/>'
            . '<path d="M8 12h8M12 8v8" stroke="#64748b" stroke-width="1.5" stroke-linecap="round"/>'
            . '</svg>'
        );
    }
}

if (!function_exists('expenses_currency_catalog')) {
    /**
     * @return array<int, array{code:string,iso:string,label:string,flag_url:string}>
     */
    function expenses_currency_catalog(): array
    {
        $botFile = dirname(__DIR__, 3) . '/includes/bot_exchange_rates.php';
        if (is_file($botFile)) {
            require_once $botFile;
        }

        $botCodes = [];
        if (function_exists('bot_exchange_rates_load')) {
            try {
                $pack = bot_exchange_rates_load();
                $botCodes = array_keys(is_array($pack['rates'] ?? null) ? $pack['rates'] : []);
            } catch (Throwable $e) {
                error_log('expenses_currency_catalog bot rates: ' . $e->getMessage());
            }
        }

        $priority = [
            'TSh', 'USD', 'EUR', 'GBP', 'KES', 'UGX', 'RWF', 'ZAR',
            'CNY', 'INR', 'AED', 'SAR', 'JPY', 'CHF', 'CAD', 'AUD',
            'SGD', 'NGN', 'GHS', 'ZMW', 'MZN', 'EGP', 'QAR',
        ];

        $seen = [];
        $out = [];

        $add = static function (string $code) use (&$out, &$seen): void {
            $display = expenses_currency_display_code($code);
            if (isset($seen[$display])) {
                return;
            }
            $seen[$display] = true;
            $iso = expenses_currency_iso($display);
            $out[] = [
                'code' => $display,
                'iso' => $iso,
                'label' => sprintf('%s - %s', $display, expenses_currency_name($display)),
                'flag_url' => expenses_currency_flag_url($display),
            ];
        };

        foreach ($priority as $code) {
            $add($code);
        }
        foreach ($botCodes as $code) {
            $add((string) $code);
        }

        return $out;
    }
}
