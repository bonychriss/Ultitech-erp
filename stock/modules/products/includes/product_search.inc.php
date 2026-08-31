<?php
/**
 * Shared product search helpers — accurate matching for list + typeahead.
 */

if (!function_exists('stock_products_escape_like')) {
    function stock_products_escape_like(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }
}

if (!function_exists('stock_products_normalize_query')) {
    function stock_products_normalize_query(string $q): string
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        return $q;
    }
}

if (!function_exists('stock_products_query_tokens')) {
    /**
     * @return list<string>
     */
    function stock_products_query_tokens(string $q): array
    {
        $q = stock_products_normalize_query($q);
        if ($q === '') {
            return [];
        }
        $parts = preg_split('/\s+/u', $q) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}

if (!function_exists('stock_products_build_search_clause')) {
    /**
     * Build WHERE fragment + params for product search.
     * Every token must match name, product_code, or brand (AND across tokens).
     *
     * @return array{0:string,1:list<mixed>}
     */
    function stock_products_build_search_clause(string $q, string $alias = 'p', bool $includeBrand = true): array
    {
        $tokens = stock_products_query_tokens($q);
        if ($tokens === []) {
            return ['', []];
        }

        $parts = [];
        $params = [];
        foreach ($tokens as $token) {
            $escaped = stock_products_escape_like($token);
            $like = '%' . $escaped . '%';
            $ors = [
                "LOWER({$alias}.name) LIKE LOWER(?)",
                "LOWER({$alias}.product_code) LIKE LOWER(?)",
            ];
            $params[] = $like;
            $params[] = $like;
            if ($includeBrand) {
                $ors[] = "LOWER(COALESCE({$alias}.brand, '')) LIKE LOWER(?)";
                $params[] = $like;
            }
            $parts[] = '(' . implode(' OR ', $ors) . ')';
        }

        return ['(' . implode(' AND ', $parts) . ')', $params];
    }
}

if (!function_exists('stock_products_search_order_sql')) {
    /**
     * Relevance ORDER BY for a full query string (exact > code prefix > name prefix > contains).
     *
     * @return array{0:string,1:list<mixed>}
     */
    function stock_products_search_order_sql(string $q, string $alias = 'p'): array
    {
        $q = stock_products_normalize_query($q);
        if ($q === '') {
            return ["{$alias}.id DESC", []];
        }

        $escaped = stock_products_escape_like($q);
        $prefix = $escaped . '%';
        $contains = '%' . $escaped . '%';
        $word = '% ' . $escaped . '%';

        $sql = "CASE
            WHEN LOWER({$alias}.product_code) = LOWER(?) THEN 0
            WHEN LOWER({$alias}.name) = LOWER(?) THEN 1
            WHEN LOWER({$alias}.product_code) LIKE LOWER(?) THEN 2
            WHEN LOWER({$alias}.name) LIKE LOWER(?) THEN 3
            WHEN LOWER({$alias}.name) LIKE LOWER(?) THEN 4
            WHEN LOWER({$alias}.product_code) LIKE LOWER(?) THEN 5
            WHEN LOWER({$alias}.name) LIKE LOWER(?) THEN 6
            ELSE 7
          END ASC,
          CHAR_LENGTH({$alias}.name) ASC,
          {$alias}.name ASC";

        $params = [
            $q,       // exact code
            $q,       // exact name
            $prefix,  // code prefix
            $prefix,  // name prefix
            $word,    // name word boundary
            $contains,// code contains
            $contains,// name contains
        ];

        return [$sql, $params];
    }
}

if (!function_exists('stock_products_score_row')) {
    /**
     * Client/fallback scoring helper (0 = best).
     */
    function stock_products_score_row(string $q, string $name, string $code): int
    {
        $qNorm = mb_strtolower(stock_products_normalize_query($q));
        $nameNorm = mb_strtolower(trim($name));
        $codeNorm = mb_strtolower(trim($code));
        if ($qNorm === '') {
            return 99;
        }
        if ($codeNorm === $qNorm) {
            return 0;
        }
        if ($nameNorm === $qNorm) {
            return 1;
        }
        if ($codeNorm !== '' && strpos($codeNorm, $qNorm) === 0) {
            return 2;
        }
        if (strpos($nameNorm, $qNorm) === 0) {
            return 3;
        }
        if (strpos($nameNorm, ' ' . $qNorm) !== false) {
            return 4;
        }
        if ($codeNorm !== '' && strpos($codeNorm, $qNorm) !== false) {
            return 5;
        }
        if (strpos($nameNorm, $qNorm) !== false) {
            return 6;
        }
        return 7;
    }
}
