<?php
/**
 * CRM module JSON API.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/crm-lib.php';
require_once __DIR__ . '/../includes/crm-sales-bridge.php';
require_once __DIR__ . '/../includes/crm-market-bridge.php';

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

$action = strtolower(trim((string) (
    $_GET['action']
    ?? $_POST['action']
    ?? ($jsonBody['action'] ?? '')
)));
if ($action === '') {
    $action = 'list';
}

try {
    $pdo = crmDeskBootstrap();
    requireLogin();

    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($companyId <= 0) {
        crmDeskJsonResponse(false, null, 'Company context is required.', 403);
    }

    switch ($action) {
        case 'list':
            $search = trim((string) ($_GET['search'] ?? ($jsonBody['search'] ?? '')));
            $status = trim((string) ($_GET['status'] ?? ($jsonBody['status'] ?? 'all')));
            crmDeskJsonResponse(true, [
                'contacts' => crmDeskListContacts($pdo, $companyId, $search, $status),
                'stats' => crmDeskStats($pdo, $companyId),
            ]);
            break;

        case 'get':
            $id = (int) ($_GET['id'] ?? ($jsonBody['id'] ?? 0));
            $contact = crmEngineGetContact($pdo, $companyId, $id);
            if ($contact === null) {
                crmDeskJsonResponse(false, null, 'Contact not found.', 404);
            }
            crmSalesBridgeAssertUserContactAccess($pdo, $contact, $userId);
            $customerId = (int) ($contact['customer_id'] ?? 0);
            $sales = $customerId > 0
                ? crmSalesBridgeFetchCustomerSalesDetail($customerId)
                : crmSalesBridgeFetchCustomerSalesDetail(0);

            crmDeskJsonResponse(true, [
                'contact' => $contact,
                'form' => crmSalesBridgeFormFromContact($contact),
                'sales' => $sales,
            ]);
            break;

        case 'create':
            $contact = crmSalesBridgeCreateFromContactForm($pdo, $companyId, $userId, $jsonBody);
            crmDeskJsonResponse(true, [
                'contact' => $contact,
                'stats' => crmDeskStats($pdo, $companyId),
            ], 'Contact created.');
            break;

        case 'update':
            $id = (int) ($jsonBody['id'] ?? 0);
            if ($id <= 0) {
                crmDeskJsonResponse(false, null, 'Contact id is required.', 422);
            }
            $existing = crmEngineGetContact($pdo, $companyId, $id);
            if ($existing === null) {
                crmDeskJsonResponse(false, null, 'Contact not found.', 404);
            }
            crmSalesBridgeAssertUserContactAccess($pdo, $existing, $userId);
            $contact = crmSalesBridgeUpdateFromContactForm($pdo, $companyId, $userId, $id, $jsonBody);
            crmDeskJsonResponse(true, [
                'contact' => $contact,
                'stats' => crmDeskStats($pdo, $companyId),
            ], 'Contact updated.');
            break;

        case 'delete':
            $id = (int) ($jsonBody['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) {
                crmDeskJsonResponse(false, null, 'Contact id is required.', 422);
            }
            $existing = crmEngineGetContact($pdo, $companyId, $id);
            if ($existing === null) {
                crmDeskJsonResponse(false, null, 'Contact not found.', 404);
            }
            crmSalesBridgeAssertUserContactAccess($pdo, $existing, $userId);
            crmEngineDeleteContact($pdo, $companyId, $id);
            crmDeskJsonResponse(true, [
                'stats' => crmDeskStats($pdo, $companyId),
            ], 'Contact deleted.');
            break;

        case 'stats':
            crmDeskJsonResponse(true, crmDeskStats($pdo, $companyId));
            break;

        case 'market_status':
            crmDeskJsonResponse(true, crmMarketStatus($companyId));
            break;

        case 'market_leads':
            $search = trim((string) ($_GET['search'] ?? ($jsonBody['search'] ?? '')));
            $limit = (int) ($_GET['limit'] ?? ($jsonBody['limit'] ?? 100));
            $mine = filter_var($_GET['mine'] ?? ($jsonBody['mine'] ?? false), FILTER_VALIDATE_BOOLEAN);
            $leads = $mine
                ? crmMarketListLeadsForUser($userId, $limit, $search, $companyId)
                : crmMarketListLeads($limit, $search, null, $companyId);
            $imported = crmMarketImportedIds($pdo, $companyId);
            $importedMap = array_fill_keys($imported, true);
            foreach ($leads as &$lead) {
                $lead['imported'] = isset($importedMap[$lead['id']]);
            }
            unset($lead);
            crmDeskJsonResponse(true, [
                'status' => crmMarketStatus($companyId),
                'leads' => $leads,
                'imported_ids' => $imported,
            ]);
            break;

        case 'prospects':
            $search = trim((string) ($_GET['search'] ?? ($jsonBody['search'] ?? '')));
            $limit = (int) ($_GET['limit'] ?? ($jsonBody['limit'] ?? 500));
            $leads = crmMarketListLeadsForUser($userId, $limit, $search, $companyId);
            // Only treat lead/customer contacts as "already added" so Add customer can promote prospects.
            $inCustomers = crmMarketImportedCustomerIds($pdo, $companyId);
            $inCustomersMap = array_fill_keys($inCustomers, true);
            foreach ($leads as &$lead) {
                $lead['imported'] = isset($inCustomersMap[$lead['id']]);
            }
            unset($lead);
            crmDeskJsonResponse(true, [
                'leads' => $leads,
                'count' => count($leads),
                'user_id' => $userId,
            ]);
            break;

        case 'market_search':
            $q = trim((string) ($_GET['q'] ?? ($jsonBody['q'] ?? '')));
            $location = trim((string) ($_GET['location'] ?? ($jsonBody['location'] ?? 'Tanzania')));
            $data = crmMarketRunSearch($q, $location, $pdo, $companyId);
            $imported = crmMarketImportedIds($pdo, $companyId);
            $importedMap = array_fill_keys($imported, true);
            foreach ($data['results'] as &$lead) {
                $lead['imported'] = !empty($lead['imported']) || isset($importedMap[$lead['id']]);
            }
            unset($lead);
            crmDeskJsonResponse(true, $data);
            break;

        case 'market_suggest':
            $q = trim((string) ($_GET['q'] ?? ($jsonBody['q'] ?? '')));
            $location = trim((string) ($_GET['location'] ?? ($jsonBody['location'] ?? 'Tanzania')));
            $data = crmMarketRapidAutocomplete($q, $location);
            if (!$data['ok']) {
                crmDeskJsonResponse(false, ['suggestions' => []], $data['error'] !== '' ? $data['error'] : 'Autocomplete failed.', 400);
            }
            crmDeskJsonResponse(true, ['suggestions' => $data['suggestions']]);
            break;

        case 'market_history':
            crmDeskJsonResponse(true, [
                'records' => crmMarketAttachHistoryAssignedCounts(crmMarketListSearchHistory(200, $companyId), $userId, $companyId),
            ]);
            break;

        case 'market_history_results':
            $historyId = trim((string) ($_GET['id'] ?? ($jsonBody['id'] ?? '')));
            $mine = filter_var($_GET['mine'] ?? ($jsonBody['mine'] ?? false), FILTER_VALIDATE_BOOLEAN);
            $rows = crmMarketListSearchHistoryResults($historyId, $companyId);
            if ($mine) {
                $rows = crmMarketFilterAssignedToUser($rows, $userId);
                // New Leads: do not auto-import. User adds to My Customers with the Action button.
                $inCustomers = array_fill_keys(crmMarketImportedCustomerIds($pdo, $companyId), true);
                foreach ($rows as &$row) {
                    $rid = (string) ($row['id'] ?? '');
                    $row['imported'] = isset($inCustomers[$rid]);
                    $row['assigned_to'] = $row['assigned_to'] ?? $row['assignedTo'] ?? null;
                    $row['assigned_user_name'] = $row['assigned_user_name'] ?? $row['assignedToName'] ?? '';
                }
                unset($row);
                // Per-user unread: opening marks viewed for this login only.
                if ($historyId !== '' && $userId > 0) {
                    crmMarketMarkHistoryViewed($userId, $historyId);
                }
            } else {
                // Saved search: companies assigned to any sales user (all assignees).
                $assignedOnly = [];
                foreach ($rows as $row) {
                    $aid = (int) ($row['assigned_to'] ?? $row['assignedTo'] ?? 0);
                    if ($aid > 0) {
                        $assignedOnly[] = $row;
                    }
                }
                $rows = $assignedOnly;
                $inCustomers = array_fill_keys(crmMarketImportedCustomerIds($pdo, $companyId), true);
                foreach ($rows as &$row) {
                    $rid = (string) ($row['id'] ?? '');
                    $row['imported'] = isset($inCustomers[$rid]);
                    $row['assigned_to'] = $row['assigned_to'] ?? $row['assignedTo'] ?? null;
                    $row['assigned_user_name'] = $row['assigned_user_name'] ?? $row['assignedToName'] ?? '';
                }
                unset($row);
                if ($historyId !== '' && $userId > 0) {
                    crmMarketMarkHistoryViewed($userId, $historyId);
                }
            }
            crmDeskJsonResponse(true, [
                'rows' => $rows,
                'mine' => $mine,
                'user_id' => $userId,
                'viewed' => true,
            ]);
            break;

        case 'market_history_view':
            $historyId = trim((string) ($jsonBody['id'] ?? ($_GET['id'] ?? '')));
            if ($historyId === '') {
                crmDeskJsonResponse(false, null, 'Saved search id is required.', 422);
            }
            $ok = crmMarketMarkHistoryViewed($userId, $historyId);
            crmDeskJsonResponse(true, [
                'id' => $historyId,
                'viewed' => $ok,
                'user_id' => $userId,
            ], $ok ? 'Marked as viewed.' : 'Could not mark as viewed.');
            break;

        case 'market_history_delete':
            $historyId = trim((string) ($jsonBody['id'] ?? ($_GET['id'] ?? '')));
            if ($historyId === '') {
                crmDeskJsonResponse(false, null, 'Saved search id is required.', 422);
            }
            $ok = crmMarketDeleteSearchHistory($historyId, $companyId);
            if (!$ok) {
                crmDeskJsonResponse(false, null, 'Saved search not found.', 404);
            }
            crmDeskJsonResponse(true, [
                'id' => $historyId,
                'records' => crmMarketAttachHistoryAssignedCounts(crmMarketListSearchHistory(200, $companyId), $userId, $companyId),
            ], 'Saved search deleted.');
            break;

        case 'market_history_pdf':
            $historyId = trim((string) ($_GET['id'] ?? ($jsonBody['id'] ?? '')));
            if ($historyId === '') {
                crmDeskJsonResponse(false, null, 'Saved search id is required.', 422);
            }
            crmMarketOutputSearchHistoryPdf($historyId, $companyId);
            break;

        case 'market_import':
            $leadId = trim((string) ($jsonBody['id'] ?? ($_GET['id'] ?? '')));
            if ($leadId === '') {
                crmDeskJsonResponse(false, null, 'Lead id is required.', 422);
            }
            // Full customer form (New Leads) → complete My Customers record.
            if (crmSalesBridgeIsFullCustomerForm($jsonBody)) {
                $result = crmMarketImportLeadFromCustomerForm($pdo, $companyId, $userId, $leadId, $jsonBody);
            } else {
                // Quick Add customer → My Customers (lead contact).
                $result = crmMarketImportLead($pdo, $companyId, $userId, $leadId, 'lead');
            }
            crmDeskJsonResponse(true, [
                'contact' => $result['contact'],
                'created' => $result['created'],
                'promoted' => !empty($result['promoted']),
                'stats' => crmDeskStats($pdo, $companyId),
            ], $result['message']);
            break;

        case 'market_import_bulk':
            $ids = $jsonBody['ids'] ?? [];
            if (!is_array($ids) || $ids === []) {
                crmDeskJsonResponse(false, null, 'Select at least one lead.', 422);
            }
            $created = 0;
            $skipped = 0;
            $errors = [];
            $contacts = [];
            foreach ($ids as $rawId) {
                $leadId = trim((string) $rawId);
                if ($leadId === '') {
                    continue;
                }
                try {
                    $result = crmMarketImportLead($pdo, $companyId, $userId, $leadId, 'lead');
                    $contacts[] = $result['contact'];
                    if ($result['created'] || !empty($result['promoted'])) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                } catch (Throwable $e) {
                    $errors[] = $leadId . ': ' . $e->getMessage();
                }
            }
            crmDeskJsonResponse(true, [
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
                'contacts' => $contacts,
                'stats' => crmDeskStats($pdo, $companyId),
            ], sprintf('Imported %d lead(s). %d already present.', $created, $skipped));
            break;

        case 'market_message_get':
            crmDeskJsonResponse(true, crmMarketGetMessageTemplate());
            break;

        case 'market_message_save':
            crmDeskJsonResponse(true, crmMarketSaveMessageTemplate($jsonBody), 'Message template saved.');
            break;

        case 'market_settings_get':
            crmDeskJsonResponse(true, crmMarketGetSearchSettingsPublic());
            break;

        case 'market_settings_save':
            crmDeskJsonResponse(true, crmMarketSaveSearchSettings($jsonBody), 'API token saved.');
            break;

        case 'market_settings_test':
            $keyOverride = trim((string) ($jsonBody['key'] ?? ($jsonBody['apiKey'] ?? '')));
            $result = crmMarketTestSearchApi($keyOverride !== '' ? $keyOverride : null);
            if (!$result['ok']) {
                crmDeskJsonResponse(false, $result, $result['message'], 400);
            }
            crmDeskJsonResponse(true, $result, $result['message']);
            break;

        case 'market_attribution':
            $mine = filter_var($_GET['mine'] ?? ($jsonBody['mine'] ?? true), FILTER_VALIDATE_BOOLEAN);
            crmDeskJsonResponse(true, crmMarketAttributionStats($pdo, $companyId, $userId, $mine));
            break;

        default:
            crmDeskJsonResponse(false, null, 'Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    crmDeskJsonResponse(false, null, $e->getMessage(), 422);
} catch (Throwable $e) {
    crmDeskJsonResponse(false, null, 'CRM request failed.', 500);
}
