<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/customer_statement/includes/statement-lib.php';

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE customer_statement_history (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
$db->exec('INSERT INTO customer_statement_history (id, company_id) VALUES (1, 10), (2, 20)');

if (customer_statement_delete_history($db, 10, 2)) {
    throw new RuntimeException("A company deleted another company's statement.");
}
if ((int) $db->query('SELECT COUNT(*) FROM customer_statement_history')->fetchColumn() !== 2) {
    throw new RuntimeException('A statement was unexpectedly deleted.');
}
if (!customer_statement_delete_history($db, 10, 1)) {
    throw new RuntimeException('A company could not delete its own statement.');
}
if (customer_statement_delete_history($db, 10, 1)) {
    throw new RuntimeException('A missing statement was reported as deleted.');
}
if (!customer_statement_delete_history($db, 20, 2)) {
    throw new RuntimeException('The other company could not delete its own statement.');
}

echo "Customer statement history deletion cases passed.\n";
