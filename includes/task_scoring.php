<?php

/**
 * Weekly Task Scoring Engine
 *
 * Automatically assigns a weight (1-5) to tasks based on keyword heuristics
 * and the official Ultimate General Trading KPI catalog.
 *
 * Weights:
 * 1 - Routine / Low Value
 * 2 - Standard Operation
 * 3 - High Value / Important
 * 4 - Critical / Strategic
 * 5 - Exceptional / Major Milestone
 */

function calculateTaskWeight($department, $description) {
    if (empty($description)) return 1;

    $desc = strtolower(trim($description));
    $score = 1; // Default score
    $dept = strtolower(trim((string) $department));

    // Prefer official KPI catalog weights when a task maps to a department KPI.
    $catalogFile = dirname(__DIR__) . '/weekly_tasks/includes/kpi_catalog.php';
    if (is_file($catalogFile)) {
        require_once $catalogFile;
        if (function_exists('kpi_match_task_to_kpi')) {
            $matched = kpi_match_task_to_kpi($department, $description);
            if ($matched !== null && !empty($matched['points'])) {
                $score = max($score, (int) $matched['points']);
            }
        }
    }

    // -------------------------------------------------------------------------
    // KEYWORD DICTIONARIES
    // -------------------------------------------------------------------------

    // GENERAL / UNIVERSAL (Applies to all)
    $general_heuristics = [
        5 => [
            'crisis', 'emergency', 'audit', 'compliance breach', 'major incident',
            'strategic partnership', 'board meeting', 'yearly review', 'quarterly review',
            'train new staff', 'mentor', 'leadership', 'cost saving', 'revenue generation'
        ],
        4 => [
            'report', 'presentation', 'optimization', 'improvement', 'deployment',
            'negotiation', 'contract', 'agreement', 'policy', 'framework',
            'analysis', 'investigation', 'troubleshoot', 'root cause', 'solution'
        ],
        3 => [
            'meeting', 'coordination', 'planning', 'scheduling', 'monitoring',
            'review', 'check', 'verify', 'validate', 'testing',
            'documentation', 'record', 'log', 'track', 'update'
        ],
        2 => [
            'email', 'correspondence', 'call', 'follow up', 'follow-up', 'respond',
            'file', 'organize', 'clean', 'tidy', 'maintenance',
            'assist', 'support', 'help', 'attend'
        ],
        1 => [
            'break', 'lunch', 'commute', 'login', 'logout', 'chat',
            'routine', 'daily check', 'scan', 'print', 'copy'
        ]
    ];

    // SALES & MARKETING
    $sales_heuristics = [
        5 => [
            'close deal', 'closed deal', 'signed contract', 'payment received',
            'new client', 'major account', 'partnership signed', 'exceeded target',
            'market penetration', 'competitor win', 'large tender', 'bid won',
            'monthly sales', 'sales target', 'revenue target'
        ],
        4 => [
            'proposal sent', 'quotation sent', 'client visit', 'site visit',
            'demo', 'presentation', 'negotiating', 'objection handling', 'upsell',
            'cross-sell', 'pipeline review', 'forecast', 'strategy meeting',
            'new customer', 'quotation conversion', 'collect payment'
        ],
        3 => [
            'lead generation', 'prospecting', 'cold call', 'warm call',
            'follow up lead', 'client meeting', 'customer service', 'support ticket',
            'crm update', 'database entry', 'market research', 'survey',
            'customer visit', 'dispatch', 'arrange delivery'
        ],
        2 => [
            'inquiry', 'answering query', 'sending brochure', 'social media post',
            'drafting email', 'team sync', 'sales report', 'expense claim'
        ]
    ];

    // IT & SYSTEMS
    $it_heuristics = [
        5 => [
            'server down', 'outage', 'security breach', 'data loss', 'disaster recovery',
            'deploy production', 'critical bug fix', 'ransomware', 'hack', 'firewall breach',
            'infrastructure upgrade', 'cloud migration', 'erp deployment', 'new module',
            'system uptime', 'restore service'
        ],
        4 => [
            'backup verification', 'patch management', 'security audit', 'penetration test',
            'api integration', 'database optimization', 'code review', 'refactoring',
            'feature development', 'bug fix', 'network config', 'firewall rule',
            'successful backup', 'recurring error'
        ],
        3 => [
            'user support', 'helpdesk', 'ticket', 'reset password', 'account creation',
            'software install', 'hardware setup', 'printer fix', 'wifi fix',
            'documentation', 'git commit', 'merge request', 'testing', 'qa',
            'resolve within 24'
        ],
        2 => [
            'update antivirus', 'clear logs', 'monitor dashboard', 'check disk space',
            'inventory check', 'cable management', 'cleaning equipment'
        ]
    ];

    // LOGISTICS & DRIVERS
    $driver_heuristics = [
        5 => [
            'long haul', 'cross-border', 'urgent delivery', 'hazardous material',
            'vip transport', 'accident report', 'breakdown repair', 'customs clearance',
            'police check', 'regulatory inspection', 'massive load', 'container offload',
            'on-time delivery', 'on time delivery'
        ],
        4 => [
            'delivery client', 'pickup supplier', 'vehicle service', 'route planning',
            'fuel management', 'logbook audit', 'safety check', 'tyre change',
            'client signature', 'invoice delivery', 'cash collection',
            'proof of delivery', 'delivery note', 'daily inspection'
        ],
        3 => [
            'daily inspection', 'washing vehicle', 'traffic', 'waiting',
            'loading', 'unloading', 'checking stock', 'verifying manifest',
            'warehouse transfer', 'depot run', 'vehicle maintenance'
        ],
        2 => [
            'cleaning', 'parking', 'refueling', 'checking oil', 'checking water',
            'reporting', 'resting', 'break'
        ]
    ];

    // PROCUREMENT & PURCHASING
    $procurement_heuristics = [
        5 => [
            'negotiate contract', 'supplier agreement', 'cost reduction', 'bulk order',
            'import clearance', 'duty payment', 'critical stock', 'shortage resolution',
            'strategic sourcing', 'vendor audit', 'fraud detection', 'cost saving'
        ],
        4 => [
            'place order', 'verify invoice', 'compare quotes', 'market analysis',
            'supplier meeting', 'quality dispute', 'return merchandise', 'rma',
            'credit note', 'payment term negotiation', 'stock forecast',
            'lead time', 'on-time supplier'
        ],
        3 => [
            'request quote', 'rfq', 'check inventory', 'update pricing',
            'supplier call', 'email supplier', 'track shipment', 'receive goods',
            'verify grn', 'delivery note', 'purchase order'
        ],
        2 => [
            'filing', 'scanning', 'printing po', 'updating contacts',
            'routine check', 'ordering stationery', 'office supplies'
        ]
    ];

    // FINANCE & ACCOUNTING
    $finance_heuristics = [
        5 => [
            'audit', 'tax filing', 'vat return', 'payroll processing', 'monthly closing',
            'yearly closing', 'financial statement', 'board report', 'cash flow crisis',
            'fraud investigation', 'bank reconciliation major', 'investor meeting',
            'receivables', 'collection performance'
        ],
        4 => [
            'process payment', 'approve voucher', 'check run', 'budget review',
            'variance analysis', 'asset register', 'depreciation', 'compliance check',
            'debt collection', 'credit control', 'loan application',
            'invoice accuracy', 'returns submission', 'monthly report'
        ],
        3 => [
            'invoice entry', 'data entry', 'journal entry', 'petty cash',
            'staff claim', 'reimbursement', 'bank run', 'deposit',
            'filing tax', 'archiving', 'query resolution'
        ],
        2 => [
            'printing checks', 'scanning invoices', 'sorting mail', 'answering calls',
            'calculating', 'checking spreadsheet'
        ]
    ];

    // STORE / WAREHOUSE
    $store_heuristics = [
        5 => [
            'stock accuracy', 'physical stock', 'stock take', 'inventory count',
            'stock variance', 'full stock count'
        ],
        4 => [
            'picking accuracy', 'pick order', 'cycle count', 'weekly count',
            'monthly count', 'order fulfillment', 'stock availability'
        ],
        3 => [
            'picking', 'packing', 'bin location', 'allocate stock', 'reserve stock',
            'receive into store', 'put away', 'stock record'
        ],
        2 => [
            'label stock', 'tidy warehouse', 'shelf check', 'count sample'
        ]
    ];

    // -------------------------------------------------------------------------
    // SCORING LOGIC
    // -------------------------------------------------------------------------

    foreach ($general_heuristics as $points => $keywords) {
        foreach ($keywords as $word) {
            if (strpos($desc, $word) !== false) {
                if ($points > $score) {
                    $score = $points;
                }
            }
        }
    }

    $specific_heuristics = [];
    if (strpos($dept, 'sales') !== false || strpos($dept, 'marketing') !== false) {
        $specific_heuristics = $sales_heuristics;
    } elseif (strpos($dept, 'it') !== false || strpos($dept, 'tech') !== false || strpos($dept, 'system') !== false) {
        $specific_heuristics = $it_heuristics;
    } elseif (strpos($dept, 'driver') !== false || strpos($dept, 'logistics') !== false || strpos($dept, 'transport') !== false) {
        $specific_heuristics = $driver_heuristics;
    } elseif (strpos($dept, 'procurement') !== false || strpos($dept, 'purchasing') !== false || strpos($dept, 'supply') !== false) {
        $specific_heuristics = $procurement_heuristics;
    } elseif (strpos($dept, 'finance') !== false || strpos($dept, 'account') !== false) {
        $specific_heuristics = $finance_heuristics;
    } elseif (strpos($dept, 'store') !== false || strpos($dept, 'warehouse') !== false || strpos($dept, 'inventory') !== false) {
        $specific_heuristics = $store_heuristics;
    }

    if (!empty($specific_heuristics)) {
        foreach ($specific_heuristics as $points => $keywords) {
            foreach ($keywords as $word) {
                if (strpos($desc, $word) !== false) {
                    if ($points > $score) {
                        $score = $points;
                    }
                }
            }
        }
    }

    if (strlen($desc) > 50 && $score == 1) {
        $score = 2;
    }

    if (strpos($desc, ',') !== false && strpos($desc, 'and') !== false && strlen($desc) > 30) {
        if ($score < 3) {
            $score++;
        }
    }

    return $score;
}
