<?php
// Simple structured knowledge base for the floating chatbot.
// Each entry: id, title, keywords, answer, steps(optional), related(optional)
$CHATBOT_GUIDES = [
    [
        'id' => 'overview',
        'title' => 'System Overview',
        'keywords' => ['overview','help','introduction','guide','start','home'],
        'answer' => 'This Payment Voucher System lets employees create payment vouchers, route them for departmental and finance checks, and finalize them once posted and paid. Key roles: Employee (creates), Department Manager (approves), Finance Checked By (validates), General Manager (final approval). Statuses: Draft, Pending, Posted, Paid.',
        'steps' => [
            'Login with your username and password.',
            'Go to Create Voucher to add payment items.',
            'Fill mandatory fields (Payee, Description, Date, Approvals).',
            'Submit or Save as Draft for later.',
            'Track progress in My Vouchers or Admin views.'
        ],
        'related' => ['create_voucher','voucher_statuses','approvals']
    ],
    [
        'id' => 'create_voucher',
        'title' => 'Creating a Voucher',
        'keywords' => ['create','new voucher','add voucher','voucher create','draft'],
        'answer' => 'Use Create Voucher to define payment items. Each item includes Payment Type, Budget Type, Name (auto-filled), Amount, and Description. You can add multiple items and see a running total.',
        'steps' => [
            'Navigate to Employee > Create Voucher.',
            'Enter Payee and Description.',
            'Pick Payment Type (e.g., Bank Transfer).',
            'Select Budget Type from the standardized list.',
            'Enter Amount and optional Item Description.',
            'Add more items using the + button.',
            'Select Department Manager and Checked By (Finance user).',
            'Click Create Voucher (or use disk icon to save Draft).'
        ],
        'related' => ['budget_types','approvals','drafts']
    ],
    [
        'id' => 'edit_voucher',
        'title' => 'Editing a Voucher',
        'keywords' => ['edit voucher','modify voucher','update voucher'],
        'answer' => 'Open any existing voucher from My Vouchers while it is still pending or draft. You can adjust items, amounts, descriptions, and re-save.',
        'steps' => [
            'Go to My Vouchers.',
            'Click a voucher in Draft or Pending status.',
            'Adjust payment items (add/remove).',
            'Update description or payee if required.',
            'Save changes to resubmit.'
        ],
        'related' => ['voucher_statuses','drafts']
    ],
    [
        'id' => 'approvals',
        'title' => 'Approval Flow',
        'keywords' => ['approval','approve','department manager','checked by','finance','general manager'],
        'answer' => 'Approval steps: Employee creates → Department Manager reviews → Finance (Checked By) validates funds and compliance → General Manager final approval (set later). The system records each approval timestamp.',
        'steps' => [
            'Ensure Department Manager is selected before submission.',
            'Select a Finance department user for Checked By.',
            'General Manager assigned later in the lifecycle.',
            'Monitor notifications for approval updates.'
        ],
        'related' => ['notifications','voucher_statuses']
    ],
    [
        'id' => 'budget_types',
        'title' => 'Budget Types Explained',
        'keywords' => ['budget','budget types','operational','procurement','marketing','tax','capex'],
        'answer' => 'Budget Types categorize spending: Operational Expenses; Procurement & Supplies; Employee Costs; Sales & Marketing; Logistics & Delivery; Administration & Management; Projects & Capital Expenditure (CAPEX); Financial Obligations; Tax & Compliance; Others / Miscellaneous.',
        'steps' => [
            'Pick the most specific category.',
            'Use CAPEX only for capital projects/equipment.',
            'Use Others / Miscellaneous when no other category fits.'
        ],
        'related' => ['create_voucher','reports']
    ],
    [
        'id' => 'voucher_statuses',
        'title' => 'Voucher Statuses',
        'keywords' => ['status','statuses','draft','pending','posted','paid'],
        'answer' => 'Statuses: Draft (incomplete / saved locally or database); Pending (submitted, awaiting approvals); Posted (accounting entry recorded); Paid (funds disbursed). Some views hide Drafts unless filtered.',
        'steps' => [
            'Use Draft to save progress early.',
            'Submit transitions to Pending.',
            'After approvals, voucher may be Posted then Paid.',
            'Reports typically focus on Posted and Paid vouchers.'
        ],
        'related' => ['approvals','reports','drafts']
    ],
    [
        'id' => 'drafts',
        'title' => 'Working with Drafts',
        'keywords' => ['draft','save draft','resume','incomplete'],
        'answer' => 'Draft vouchers let you save progress without full validation. Amounts or approvals can be missing; finalize later by editing and submitting.',
        'steps' => [
            'Click the disk icon to save as Draft.',
            'Find drafts under My Vouchers (may be filtered).',
            'Open a Draft, complete missing fields, submit to move to Pending.'
        ],
        'related' => ['create_voucher','voucher_statuses']
    ],
    [
        'id' => 'attachments',
        'title' => 'Attachments & Supporting Documents',
        'keywords' => ['attachments','supporting documents','files','upload'],
        'answer' => 'Supporting documents count field tracks required attachments. Actual file upload happens with voucher items or a separate interface depending on configuration.',
        'steps' => [
            'Gather all invoices/receipts relevant to each item.',
            'Upload or reference them as required by finance.',
            'Ensure number matches Supporting Documents field.'
        ],
        'related' => ['create_voucher']
    ],
    [
        'id' => 'notifications',
        'title' => 'Notifications & Messages',
        'keywords' => ['notification','messages','alerts','unread'],
        'answer' => 'Notifications show approval progress and status changes. Messages allow direct communication. Badge counters display unread counts in the header.',
        'steps' => [
            'Check the bell icon for system notifications.',
            'Open Messages for direct conversations.',
            'Use Mark all read to clear notifications.'
        ],
        'related' => ['approvals']
    ],
    [
        'id' => 'reports',
        'title' => 'Reports & Analysis',
        'keywords' => ['reports','analysis','export','pdf','summary'],
        'answer' => 'Reports aggregate voucher data; admins can filter by dates, status, and budget types. Use exports for accounting or auditing.',
        'steps' => [
            'Open Admin > Reports.',
            'Select filters (date range, status, budget type).',
            'Generate summary or export if available.'
        ],
        'related' => ['budget_types','voucher_statuses']
    ],
    [
        'id' => 'security',
        'title' => 'Security Practices',
        'keywords' => ['security','safe','privacy','protect','account'],
        'answer' => 'Keep your credentials safe, log out on shared devices, and avoid exposing voucher financial details outside authorized stakeholders.',
        'steps' => [
            'Use strong passwords.',
            'Log out when done.',
            'Do not share sensitive voucher data externally.'
        ],
        'related' => ['overview']
    ],
];

function chatbot_search_guides($query){
    global $CHATBOT_GUIDES;
    $q = strtolower(trim($query));
    if($q === '') return [];
    $scores = [];
    foreach ($CHATBOT_GUIDES as $g){
        $score = 0;
        foreach ($g['keywords'] as $kw){
            if(strpos($q, strtolower($kw)) !== false){ $score += 3; }
        }
        // Partial word token match
        $tokens = preg_split('/\s+/', $q);
        foreach ($tokens as $t){
            if($t !== '' && (strpos(strtolower($g['answer']), $t) !== false || strpos(strtolower($g['title']), $t) !== false)){ $score += 1; }
        }
        if($score > 0){ $scores[$g['id']] = $score; }
    }
    arsort($scores);
    $result = [];
    foreach ($scores as $id => $sc){
        foreach ($CHATBOT_GUIDES as $g){ if($g['id'] === $id){ $result[] = $g; break; } }
        if(count($result) >= 5) break;
    }
    return $result;
}
?>
