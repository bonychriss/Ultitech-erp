<?php

declare(strict_types=1);

function crmEngineEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_contacts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            organization VARCHAR(255) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(64) DEFAULT NULL,
            status ENUM('lead','prospect','customer','inactive') NOT NULL DEFAULT 'lead',
            source VARCHAR(128) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_crm_contacts_company (company_id),
            INDEX idx_crm_contacts_customer (customer_id),
            INDEX idx_crm_contacts_status (company_id, status),
            INDEX idx_crm_contacts_name (company_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $pdo->exec('ALTER TABLE crm_contacts ADD COLUMN customer_id INT UNSIGNED DEFAULT NULL AFTER company_id');
    } catch (Throwable $e) {
        // Column already exists.
    }
    try {
        $pdo->exec('ALTER TABLE crm_contacts ADD INDEX idx_crm_contacts_customer (customer_id)');
    } catch (Throwable $e) {
        // Index already exists.
    }
    try {
        $pdo->exec('ALTER TABLE crm_contacts ADD INDEX idx_crm_contacts_owner (company_id, created_by)');
    } catch (Throwable $e) {
        // Index already exists.
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function crmEngineListContacts(
    PDO $pdo,
    int $companyId,
    ?string $search = null,
    ?string $status = null,
    ?int $createdBy = null,
    ?array $customerIds = null
): array {
    crmEngineEnsureSchema($pdo);

    $sql = 'SELECT * FROM crm_contacts WHERE company_id = ?';
    $params = [$companyId];

    crmEngineAppendUserScopeSql($sql, $params, $createdBy, $customerIds);

    if ($status !== null && $status !== '' && $status !== 'all') {
        $sql .= ' AND status = ?';
        $params[] = $status;
    } else {
        // My Customers desk: keep market Prospects out of the default customer list.
        $sql .= " AND status <> 'prospect'";
    }

    if ($search !== null && trim($search) !== '') {
        $term = '%' . trim($search) . '%';
        $sql .= ' AND (name LIKE ? OR organization LIKE ? OR email LIKE ? OR phone LIKE ?)';
        array_push($params, $term, $term, $term, $term);
    }

    $sql .= ' ORDER BY updated_at DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function crmEngineGetContact(PDO $pdo, int $companyId, int $id): ?array
{
    crmEngineEnsureSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM crm_contacts WHERE company_id = ? AND id = ? LIMIT 1');
    $stmt->execute([$companyId, $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function crmEngineCreateContact(PDO $pdo, int $companyId, int $userId, array $data): array
{
    crmEngineEnsureSchema($pdo);

    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Contact name is required.');
    }

    $status = crmEngineNormalizeStatus((string) ($data['status'] ?? 'lead'));
    $customerId = (int) ($data['customer_id'] ?? 0);

    $stmt = $pdo->prepare('
        INSERT INTO crm_contacts (company_id, customer_id, name, organization, email, phone, status, source, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $companyId,
        $customerId > 0 ? $customerId : null,
        $name,
        crmEngineNullableString($data['organization'] ?? null),
        crmEngineNullableString($data['email'] ?? null),
        crmEngineNullableString($data['phone'] ?? null),
        $status,
        crmEngineNullableString($data['source'] ?? null),
        crmEngineNullableString($data['notes'] ?? null),
        $userId > 0 ? $userId : null,
    ]);

    $id = (int) $pdo->lastInsertId();
    $contact = crmEngineGetContact($pdo, $companyId, $id);
    if ($contact === null) {
        throw new RuntimeException('Failed to load created contact.');
    }

    return $contact;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function crmEngineUpdateContact(PDO $pdo, int $companyId, int $id, array $data): array
{
    crmEngineEnsureSchema($pdo);

    $existing = crmEngineGetContact($pdo, $companyId, $id);
    if ($existing === null) {
        throw new InvalidArgumentException('Contact not found.');
    }

    $name = trim((string) ($data['name'] ?? $existing['name']));
    if ($name === '') {
        throw new InvalidArgumentException('Contact name is required.');
    }

    $status = crmEngineNormalizeStatus((string) ($data['status'] ?? $existing['status']));
    $customerId = (int) ($data['customer_id'] ?? $existing['customer_id'] ?? 0);

    $stmt = $pdo->prepare('
        UPDATE crm_contacts
        SET name = ?, organization = ?, email = ?, phone = ?, status = ?, source = ?, notes = ?, customer_id = ?
        WHERE company_id = ? AND id = ?
    ');
    $stmt->execute([
        $name,
        crmEngineNullableString($data['organization'] ?? $existing['organization']),
        crmEngineNullableString($data['email'] ?? $existing['email']),
        crmEngineNullableString($data['phone'] ?? $existing['phone']),
        $status,
        crmEngineNullableString($data['source'] ?? $existing['source']),
        crmEngineNullableString($data['notes'] ?? $existing['notes']),
        $customerId > 0 ? $customerId : null,
        $companyId,
        $id,
    ]);

    $contact = crmEngineGetContact($pdo, $companyId, $id);
    if ($contact === null) {
        throw new RuntimeException('Failed to load updated contact.');
    }

    return $contact;
}

function crmEngineAssertContactAccess(array $contact, ?int $createdBy): void
{
    if ($createdBy === null || $createdBy <= 0) {
        return;
    }

    if ((int) ($contact['created_by'] ?? 0) !== $createdBy) {
        throw new InvalidArgumentException('Contact not found.');
    }
}

function crmEngineDeleteContact(PDO $pdo, int $companyId, int $id, ?int $createdBy = null): void
{
    crmEngineEnsureSchema($pdo);

    if ($createdBy !== null && $createdBy > 0) {
        $existing = crmEngineGetContact($pdo, $companyId, $id);
        if ($existing === null) {
            throw new InvalidArgumentException('Contact not found.');
        }
        crmEngineAssertContactAccess($existing, $createdBy);
    }

    $stmt = $pdo->prepare('DELETE FROM crm_contacts WHERE company_id = ? AND id = ?');
    $stmt->execute([$companyId, $id]);

    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('Contact not found.');
    }
}

/**
 * @return array<string, int>
 */
function crmEngineStats(PDO $pdo, int $companyId, ?int $createdBy = null, ?array $customerIds = null): array
{
    crmEngineEnsureSchema($pdo);

    $sql = '
        SELECT status, COUNT(*) AS total
        FROM crm_contacts
        WHERE company_id = ?
    ';
    $params = [$companyId];

    crmEngineAppendUserScopeSql($sql, $params, $createdBy, $customerIds);

    $sql .= ' GROUP BY status';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $stats = [
        'lead' => 0,
        'prospect' => 0,
        'customer' => 0,
        'inactive' => 0,
        'total' => 0,
    ];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $key = (string) ($row['status'] ?? '');
        $count = (int) ($row['total'] ?? 0);
        if (isset($stats[$key])) {
            $stats[$key] = $count;
        }
        $stats['total'] += $count;
    }

    return $stats;
}

function crmEngineNormalizeStatus(string $status): string
{
    $status = strtolower(trim($status));
    $allowed = ['lead', 'prospect', 'customer', 'inactive'];
    if (!in_array($status, $allowed, true)) {
        return 'lead';
    }

    return $status;
}

/**
 * @param array<int|string> $customerIds
 */
function crmEngineAppendUserScopeSql(string &$sql, array &$params, ?int $userId, ?array $customerIds): void
{
    if ($userId === null || $userId <= 0) {
        return;
    }

    $scopedCustomerIds = [];
    foreach ($customerIds ?? [] as $customerId) {
        $customerId = (int) $customerId;
        if ($customerId > 0) {
            $scopedCustomerIds[] = $customerId;
        }
    }

    if ($scopedCustomerIds !== []) {
        $placeholders = implode(',', array_fill(0, count($scopedCustomerIds), '?'));
        $sql .= " AND (created_by = ? OR customer_id IN ($placeholders))";
        $params[] = $userId;
        foreach ($scopedCustomerIds as $customerId) {
            $params[] = $customerId;
        }

        return;
    }

    $sql .= ' AND created_by = ?';
    $params[] = $userId;
}

function crmEngineNullableString(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $text = trim((string) $value);

    return $text === '' ? null : $text;
}
