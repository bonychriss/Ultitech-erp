<?php

declare(strict_types=1);

require_once __DIR__ . '/report-domain-store.php';

function reportDomainProcurementSnapshot(PDO $pdo, array $filters): array
{
    return reportDomainStoreSnapshot($pdo, $filters);
}

function reportDomainProcurementFetch(PDO $pdo, string $source, array $filters): array
{
    return reportDomainStoreFetch($pdo, $source, $filters);
}

function reportDomainProcurementErpMenu(): array
{
    return reportDomainStoreErpMenu();
}

function reportDomainProcurementSupplierOptions(PDO $pdo): array
{
    return [];
}
