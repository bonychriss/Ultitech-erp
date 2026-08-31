<?php
/**
 * Bank of Tanzania (BOT) indicative exchange rates + optional AI refresh.
 * Rates are TZS per 1 unit of foreign currency (BOT "Mean" column).
 */

if (!function_exists('bot_exchange_rates_cache_path')) {
    function bot_exchange_rates_cache_path(): string
    {
        $root = dirname(__DIR__);
        $dir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'bot_exchange_rates.json';
    }
}

if (!function_exists('bot_exchange_rates_read_cache')) {
    /**
     * @return array<string,mixed>|null
     */
    function bot_exchange_rates_read_cache(): ?array
    {
        $path = bot_exchange_rates_cache_path();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}

if (!function_exists('bot_exchange_rates_write_cache')) {
    /**
     * @param array<string,mixed> $data
     */
    function bot_exchange_rates_write_cache(array $data): void
    {
        $path = bot_exchange_rates_cache_path();
        $data['cached_at'] = date('c');
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('bot_exchange_rates_scrape')) {
    /**
     * Scrape BOT indicative rates page.
     *
     * @return array{transaction_date:?string,rates:array<string,array{mean:float,buying:float,selling:float}>}
     */
    function bot_exchange_rates_scrape(): array
    {
        $url = 'https://www.bot.go.tz/ExchangeRate/excRates?lang=en';
        $html = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; UltimateERP/1.0; +BOT-rates)',
            ]);
            $html = (string) curl_exec($ch);
            curl_close($ch);
        }
        if ($html === '') {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 25,
                    'header' => "User-Agent: UltimateERP/1.0\r\n",
                ],
            ]);
            $html = (string) @file_get_contents($url, false, $ctx);
        }

        $rates = [];
        $transactionDate = null;

        if ($html !== '') {
            if (preg_match_all('/\|\s*\d+\s*\|\s*([A-Z]{3})\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([\d.]+)\s*\|\s*([^|]+)\|/u', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $code = strtoupper(trim($m[1]));
                    if ($code === 'GOLD' || !preg_match('/^[A-Z]{3}$/', $code)) {
                        continue;
                    }
                    $rates[$code] = [
                        'buying' => (float) $m[2],
                        'selling' => (float) $m[3],
                        'mean' => (float) $m[4],
                    ];
                    if ($transactionDate === null) {
                        $transactionDate = trim($m[5]);
                    }
                }
            }

            if ($rates === [] && class_exists('DOMDocument')) {
                $dom = new DOMDocument();
                libxml_use_internal_errors(true);
                if (@$dom->loadHTML($html)) {
                    $xpath = new DOMXPath($dom);
                    $rows = $xpath->query('//table//tr');
                    if ($rows) {
                        foreach ($rows as $tr) {
                            if (!($tr instanceof DOMElement)) {
                                continue;
                            }
                            $cells = $tr->getElementsByTagName('td');
                            if ($cells->length < 5) {
                                continue;
                            }
                            $code = strtoupper(trim($cells->item(1)->textContent ?? ''));
                            if (!preg_match('/^[A-Z]{3}$/', $code) || $code === 'GOLD') {
                                continue;
                            }
                            $buying = (float) str_replace(',', '', trim($cells->item(2)->textContent ?? '0'));
                            $selling = (float) str_replace(',', '', trim($cells->item(3)->textContent ?? '0'));
                            $mean = (float) str_replace(',', '', trim($cells->item(4)->textContent ?? '0'));
                            $rates[$code] = [
                                'buying' => $buying,
                                'selling' => $selling,
                                'mean' => $mean,
                            ];
                            if ($transactionDate === null) {
                                $transactionDate = trim($cells->item(5)->textContent ?? '');
                            }
                        }
                    }
                }
                libxml_clear_errors();
            }
        }

        return [
            'transaction_date' => $transactionDate,
            'rates' => $rates,
            'source' => 'BOT',
            'source_url' => $url,
        ];
    }
}

if (!function_exists('bot_exchange_rate_ai_lookup')) {
    function bot_exchange_rate_ai_lookup(string $currency): ?array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === 'TZS') {
            return null;
        }

        if (!function_exists('ai_openai_request')) {
            $helper = __DIR__ . '/ai_helpers.php';
            if (is_file($helper)) {
                require_once $helper;
            }
        }
        if (!function_exists('ai_openai_request')) {
            return null;
        }

        try {
            $result = ai_openai_request([
                [
                    'role' => 'system',
                    'content' => 'You are a financial data assistant. Reply with JSON only, no markdown fences.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Give the latest Bank of Tanzania (BOT) official listed MEAN exchange rate: how many Tanzanian Shillings (TZS) equal 1 '
                        . $currency . '. Use the most recent BOT published indicative/mean rate. '
                        . 'Return exactly: {"rate":<number>,"as_of":"YYYY-MM-DD","source":"BOT"}',
                ],
            ], 'gpt-4o-mini');

            $content = trim((string) ($result['content'] ?? ''));
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
            $json = json_decode($content, true);
            if (!is_array($json) || !isset($json['rate'])) {
                return null;
            }
            $rate = (float) $json['rate'];
            if ($rate <= 0) {
                return null;
            }

            return [
                'mean' => $rate,
                'as_of' => (string) ($json['as_of'] ?? date('Y-m-d')),
                'source' => 'BOT+AI',
            ];
        } catch (Throwable $e) {
            error_log('bot_exchange_rate_ai_lookup: ' . $e->getMessage());

            return null;
        }
    }
}

if (!function_exists('bot_exchange_rates_load')) {
    /**
     * @return array<string,mixed>
     */
    function bot_exchange_rates_load(bool $forceRefresh = false, int $maxAgeSeconds = 21600): array
    {
        if (!$forceRefresh) {
            $cached = bot_exchange_rates_read_cache();
            if (is_array($cached) && !empty($cached['rates']) && !empty($cached['cached_at'])) {
                $age = time() - strtotime((string) $cached['cached_at']);
                if ($age >= 0 && $age < $maxAgeSeconds) {
                    return $cached;
                }
            }
        }

        $scraped = bot_exchange_rates_scrape();
        if (!empty($scraped['rates'])) {
            bot_exchange_rates_write_cache($scraped);

            return $scraped;
        }

        $cached = bot_exchange_rates_read_cache();
        if (is_array($cached) && !empty($cached['rates'])) {
            return $cached;
        }

        return [
            'transaction_date' => null,
            'rates' => [],
            'source' => 'BOT',
        ];
    }
}

if (!function_exists('bot_get_exchange_rate')) {
    /**
     * TZS per 1 unit of currency (BOT mean). TZS returns 1.0.
     *
     * @return array{rate:float,source:string,as_of:?string,via_ai:bool}|null
     */
    function bot_get_exchange_rate(string $currency, bool $allowAi = true): ?array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'TZS') {
            return [
                'rate' => 1.0,
                'source' => 'BOT',
                'as_of' => date('Y-m-d'),
                'via_ai' => false,
            ];
        }
        if ($currency === '') {
            return null;
        }

        $pack = bot_exchange_rates_load();
        $rates = is_array($pack['rates'] ?? null) ? $pack['rates'] : [];
        if (isset($rates[$currency]['mean']) && (float) $rates[$currency]['mean'] > 0) {
            return [
                'rate' => (float) $rates[$currency]['mean'],
                'source' => 'BOT',
                'as_of' => isset($pack['transaction_date']) ? (string) $pack['transaction_date'] : null,
                'via_ai' => false,
            ];
        }

        if (!$allowAi) {
            return null;
        }

        $ai = bot_exchange_rate_ai_lookup($currency);
        if ($ai !== null && (float) $ai['mean'] > 0) {
            return [
                'rate' => (float) $ai['mean'],
                'source' => (string) ($ai['source'] ?? 'BOT+AI'),
                'as_of' => (string) ($ai['as_of'] ?? date('Y-m-d')),
                'via_ai' => true,
            ];
        }

        return null;
    }
}
