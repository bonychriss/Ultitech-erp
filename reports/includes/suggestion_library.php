<?php

/**
 * Suggestion Library for Sales Reports
 * 
 * This file contains the dictionary of all possible suggestions and the logic
 * to select relevant ones based on provided metrics.
 */

function getSalesSuggestions($metrics) {
    // 1. Dictionary of all possible suggestions
    // Structure: unique_key => [category, icon, class, text_template, condition_callback]
    $dictionary = [
        'new_customers_needed' => [
            'category' => 'Get more customers',
            'icon' => 'fa-user-plus',
            'class' => 'text-primary',
            'text' => 'You have few customers in the report. Use the Customers module to add new leads, follow up on quotations, and run simple promotions or outreach to grow your customer base.',
            'condition' => function($m) { return $m['num_customers'] < 5 && $m['period_revenue'] > 0; }
        ],
        'grow_customer_base' => [
            'category' => 'Get more customers',
            'icon' => 'fa-user-plus',
            'class' => 'text-primary',
            'text' => 'Keep growing: target new segments, ask your top customers for referrals, and track potential customers in Customers so no lead is lost.',
            'condition' => function($m) { return $m['num_customers'] >= 5; }
        ],
        'start_customer_acquisition' => [
            'category' => 'Get more customers',
            'icon' => 'fa-user-plus',
            'class' => 'text-primary',
            'text' => 'Focus on acquiring new customers: add leads in Customers, send quotations quickly, and follow up to convert quotes into orders.',
            'condition' => function($m) { return $m['num_customers'] == 0 && $m['period_revenue'] == 0; } // Fallback if no revenue/customers
        ],
        'maintain_relationships' => [
            'category' => 'Maintain customers',
            'icon' => 'fa-handshake',
            'class' => 'text-success',
            'text' => 'Your top customers drive revenue. Contact them regularly, check satisfaction, offer support, and consider simple loyalty or repeat-order incentives so they stay and buy more.',
            'condition' => function($m) { return $m['num_customers'] > 0; }
        ],
        'start_relationships' => [
            'category' => 'Maintain customers',
            'icon' => 'fa-handshake',
            'class' => 'text-success',
            'text' => 'Once you have regular customers, maintain them: log contacts in Customers, respond quickly to orders and queries, and resolve any invoice or delivery issues promptly.',
            'condition' => function($m) { return $m['num_customers'] == 0; }
        ],
        'hit_targets' => [
            'category' => 'Increase sales',
            'icon' => 'fa-chart-line',
            'class' => 'text-warning',
            'text' => 'Target is at {yearly_pct}%. To increase sales: convert pending quotations to orders, push your top-selling products, and set clear daily or weekly goals for the sales team.',
            'condition' => function($m) { return $m['yearly_target'] > 0 && $m['yearly_pct'] < 100; }
        ],
        'convert_quotes' => [
            'category' => 'Increase sales',
            'icon' => 'fa-chart-line',
            'class' => 'text-warning',
            'text' => 'You have {num_quotes} quotation(s). Follow up and convert them to orders and invoices. Also focus on your top products and top customers to maximise revenue.',
            'condition' => function($m) { return $m['num_quotes'] > 0 && ($m['yearly_target'] == 0 || $m['yearly_pct'] >= 100); }
        ],
        'general_sales_boost' => [
            'category' => 'Increase sales',
            'icon' => 'fa-chart-line',
            'class' => 'text-warning',
            'text' => 'Increase sales by promoting your best-selling products, upselling to existing customers, and making sure every quotation is followed up until it becomes an order or invoice.',
            'condition' => function($m) { return $m['yearly_target'] == 0 && $m['num_quotes'] == 0; }
        ],
        'reduce_overdue' => [
            'category' => 'Improve cash flow',
            'icon' => 'fa-money-bill-wave',
            'class' => 'text-danger',
            'text' => 'You have {overdue_count} overdue invoice(s) (TZS {overdue_total}). Contact these customers, send payment reminders from the Invoices module, and agree payment plans where needed to improve collections.',
            'condition' => function($m) { return $m['overdue_count'] > 0; }
        ],
        'maintain_cashflow' => [
            'category' => 'Improve cash flow',
            'icon' => 'fa-money-bill-wave',
            'class' => 'text-success',
            'text' => 'No overdue invoices — good. Keep it that way by setting clear payment terms, sending invoices on time, and following up before due dates.',
            'condition' => function($m) { return $m['overdue_count'] == 0; }
        ],
        'quote_conversion_opportunity' => [
            'category' => 'Convert quotations',
            'icon' => 'fa-file-signature',
            'class' => 'text-info',
            'text' => 'You have {num_quotes} quotation(s) in this period. Review each one in Sales Orders / Quotations, follow up with the customer, and convert them to confirmed orders and then to invoices to grow revenue.',
            'condition' => function($m) { return $m['num_quotes'] > 0; }
        ],
        'set_targets' => [
            'category' => 'Targets',
            'icon' => 'fa-bullseye',
            'class' => 'text-muted',
            'text' => 'Set a company yearly target in Sales → Admin → Targets. Then set monthly targets per rep. This helps the team focus and lets you see progress on this report.',
            'condition' => function($m) { return $m['yearly_target'] <= 0 && $m['use_sales_module']; }
        ],
        'missed_targets' => [
            'category' => 'Targets',
            'icon' => 'fa-bullseye',
            'class' => 'text-warning',
            'text' => 'You are below target ({monthly_pct}%). Break the remaining gap into daily goals. If you need {gap_amount}, identify 3 deals worth that amount and focus purely on closing them this week.',
            'condition' => function($m) { 
                return ($m['yearly_target'] > 0 && $m['yearly_pct'] < 80) || ($m['monthly_target'] > 0 && $m['monthly_pct'] < 80); 
            }
        ],
        // --- AI COACHING TIPS ---
        'close_gap_urgency' => [
            'category' => 'Closing Tactic',
            'icon' => 'fa-hourglass-half',
            'class' => 'text-danger',
            'text' => 'End of month is approaching. excessive follow-up can annoy leads, but silence kills deals. Try the "File Closing" email: "Hi [Name], I\'m closing files for the month. Should I keep this quote active or assume you\'re passing for now?" It often triggers a decision.',
            'condition' => function($m) { 
                // Trigger if after 20th of month and target not hit
                return date('j') > 20 && (($m['monthly_target'] > 0 && $m['monthly_pct'] < 90));
            }
        ],
        'quote_mining' => [
            'category' => 'Quick Wins',
            'icon' => 'fa-search-dollar',
            'class' => 'text-info',
            'text' => 'You have {num_quotes} open quotes. Pick the top 3 highest value ones. Call them today with a specific reason: "I have noticed stock is running low on X" or "I can offer free delivery if confirmed by Friday."',
            'condition' => function($m) { return $m['num_quotes'] >= 3; }
        ],
        'upsell_strategy' => [
            'category' => 'Maximize Value',
            'icon' => 'fa-level-up-alt',
            'class' => 'text-success',
            'text' => 'The easiest sale is to an existing customer. For every order you take this week, suggest *one* complementary item. "Did you also need [related product] with that?" rarely hurts and often work.',
            'condition' => function($m) { return $m['period_revenue'] > 0; }
        ],
        'revisit_old_clients' => [
            'category' => 'Pipeline',
            'icon' => 'fa-history',
            'class' => 'text-primary',
            'text' => 'Pipeline dry? valuable leads are often in your past sales. Search for customers who bought 6 months ago but not since. Call to "check in on how the product is working" - no pitch. The order often follows naturally.',
            'condition' => function($m) { return $m['num_customers'] > 0 && $m['num_quotes'] < 3; }
        ]
    ];

    // 2. Evaluate rules
    $suggestions = [];
    $categories_added = []; // To avoid duplicate categories if we want to limit one per category

    // Helper to replace placeholders
    $formatText = function($text, $metrics) {
        return str_replace(
            ['{yearly_pct}', '{monthly_pct}', '{num_quotes}', '{overdue_count}', '{overdue_total}', '{gap_amount}'], 
            [
                number_format($metrics['yearly_pct'] ?? 0, 0),
                number_format($metrics['monthly_pct'] ?? 0, 0),
                $metrics['num_quotes'] ?? 0,
                $metrics['overdue_count'] ?? 0,
                number_format($metrics['overdue_total'] ?? 0),
                number_format(max(0, ($metrics['monthly_target'] ?? 0) - ($metrics['period_revenue'] ?? 0)))
            ], 
            $text
        );
    };

    // Logical Groups to pick one from
    $groups = [
        ['close_gap_urgency'],
        ['quote_mining', 'convert_quotes'], // Prioritize quote mining (specific) over convert_quotes (generic)
        ['upsell_strategy', 'hit_targets', 'general_sales_boost'],
        ['revisit_old_clients'],
        ['reduce_overdue', 'maintain_cashflow'],
        ['set_targets', 'missed_targets'],
        ['new_customers_needed', 'grow_customer_base']
    ];

    foreach ($groups as $group) {
        foreach ($group as $key) {
            if (isset($dictionary[$key])) {
                $rule = $dictionary[$key];
                if (call_user_func($rule['condition'], $metrics)) {
                    // Avoid duplicate 'Increase sales' if 'hit_targets' puts it in, but 'convert_quotes' is separate category?
                    // Actually 'convert_quotes' is category 'Increase sales' or 'Convert quotations'. Dictionary has 'Increase sales' for convert_quotes logic above?
                    // Let's rely on the dictionary category.
                    
                    // Specific logic: 'convert_quotes' used twice? 
                    // In dictionary: 'convert_quotes' key has category 'Increase sales'.
                    // 'quote_conversion_opportunity' key has category 'Convert quotations'.
                    // This matches original logic flow.
                    
                    $suggestions[] = [
                        'category' => $rule['category'],
                        'icon' => $rule['icon'],
                        'class' => $rule['class'],
                        'text' => $formatText($rule['text'], $metrics)
                    ];
                    break; // Pick only the first matching rule from this group
                }
            }
        }
    }

    return $suggestions;
}
