<?php

namespace App\Integration\Erp;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Hard gate: ERP connection may only run SELECT / SHOW / DESCRIBE / EXPLAIN.
 * No INSERT/UPDATE/DELETE/ALTER against ERP databases.
 */
class ReadOnlyErpGateway
{
    public function __construct(private readonly string $connectionName = 'erp_ultimate') {}

    public function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }

    /**
     * Point the read-only ERP connection at a tenant database for this request.
     */
    public function useDatabase(?string $database): void
    {
        $database = trim((string) $database);
        if ($database === '') {
            return;
        }

        $current = (string) config("database.connections.{$this->connectionName}.database");
        if ($current === $database) {
            return;
        }

        config(["database.connections.{$this->connectionName}.database" => $database]);
        DB::purge($this->connectionName);
    }

    public function enabled(): bool
    {
        return (bool) config('erp.enabled', env('ERP_SYNC_ENABLED', false));
    }

    public function testConnection(): array
    {
        try {
            $db = $this->connection()->getDatabaseName();
            $accounts = $this->tableExists('erp_accounts')
                ? (int) $this->selectOne('SELECT COUNT(*) AS c FROM erp_accounts')->c
                : 0;
            $journals = $this->tableExists('gl_journal_entries')
                ? (int) $this->selectOne('SELECT COUNT(*) AS c FROM gl_journal_entries')->c
                : 0;
            $wallets = $this->tableExists('financial_accounts')
                ? (int) $this->selectOne('SELECT COUNT(*) AS c FROM financial_accounts')->c
                : 0;

            if ($accounts === 0 && $journals === 0 && $wallets === 0 && ! $this->tableExists('financial_accounts')) {
                return [
                    'ok' => false,
                    'database' => $db,
                    'read_only' => true,
                    'message' => 'ERP database reachable but Balances/GL tables were not found.',
                ];
            }

            return [
                'ok' => true,
                'database' => $db,
                'read_only' => true,
                'erp_accounts' => $accounts,
                'gl_journal_entries' => $journals,
                'financial_accounts' => $wallets,
                'message' => 'Read-only ERP connection OK. No ERP data was modified.',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'database' => config("database.connections.{$this->connectionName}.database"),
                'read_only' => true,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->assertReadOnlySql($sql);

        return $this->connection()->select($sql, $bindings);
    }

    public function selectOne(string $sql, array $bindings = []): object
    {
        $rows = $this->select($sql, $bindings);
        if ($rows === []) {
            throw new RuntimeException('ERP query returned no rows.');
        }

        return $rows[0];
    }

    public function tableExists(string $table): bool
    {
        return Schema::connection($this->connectionName)->hasTable($table);
    }

    public function columnExists(string $table, string $column): bool
    {
        return Schema::connection($this->connectionName)->hasColumn($table, $column);
    }

    private function assertReadOnlySql(string $sql): void
    {
        $normalized = ltrim(strtolower($sql));
        // Strip SQL comments
        $normalized = preg_replace('/^\\s*--.*$/m', '', $normalized) ?? $normalized;
        $normalized = ltrim($normalized);

        $allowed = str_starts_with($normalized, 'select')
            || str_starts_with($normalized, 'show')
            || str_starts_with($normalized, 'describe')
            || str_starts_with($normalized, 'desc ')
            || str_starts_with($normalized, 'explain');

        if (! $allowed) {
            throw new RuntimeException('ERP gateway blocked non-read SQL. Existing ERP accounting is never modified.');
        }

        foreach ([' insert ', ' update ', ' delete ', ' drop ', ' alter ', ' truncate ', ' create ', ' replace ', ' grant ', ' revoke '] as $verb) {
            if (str_contains(' '.$normalized.' ', $verb)) {
                throw new RuntimeException('ERP gateway blocked write keyword in SQL.');
            }
        }
    }
}
