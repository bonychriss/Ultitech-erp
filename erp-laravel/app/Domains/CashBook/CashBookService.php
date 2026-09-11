<?php

namespace App\Domains\CashBook;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Cash book recording: books, cash-in / cash-out entries, running balance.
 */
final class CashBookService
{
    public function __construct()
    {
        Schema::ensure();
    }

    /** @return list<array<string,mixed>> */
    public function listBooks(?string $status = 'active'): array
    {
        $q = DB::table('cash_books')->orderBy('name');
        if ($status !== null && $status !== '' && $status !== 'all') {
            $q->where('status', $status);
        }
        $rows = $q->get();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrateBook((array) $row);
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function getBook(int $id): ?array
    {
        $row = DB::table('cash_books')->where('id', $id)->first();
        if (!$row) {
            return null;
        }

        return $this->hydrateBook((array) $row);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createBook(array $data, int $userId): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Book name is required.');
        }
        $opening = round((float) ($data['opening_balance'] ?? 0), 2);
        $notes = trim((string) ($data['notes'] ?? ''));
        $id = (int) DB::table('cash_books')->insertGetId([
            'name' => mb_substr($name, 0, 120),
            'opening_balance' => $opening,
            'notes' => $notes !== '' ? $notes : null,
            'status' => 'active',
            'created_by' => $userId > 0 ? $userId : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->getBook($id) ?? ['id' => $id, 'name' => $name];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updateBook(int $id, array $data): array
    {
        $book = $this->getBook($id);
        if ($book === null) {
            throw new InvalidArgumentException('Cash book not found.');
        }

        $patch = ['updated_at' => now()];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Book name is required.');
            }
            $patch['name'] = mb_substr($name, 0, 120);
        }
        if (array_key_exists('opening_balance', $data)) {
            $patch['opening_balance'] = round((float) $data['opening_balance'], 2);
        }
        if (array_key_exists('notes', $data)) {
            $notes = trim((string) $data['notes']);
            $patch['notes'] = $notes !== '' ? $notes : null;
        }
        if (array_key_exists('status', $data)) {
            $status = strtolower(trim((string) $data['status']));
            if (!in_array($status, ['active', 'archived'], true)) {
                throw new InvalidArgumentException('Invalid book status.');
            }
            $patch['status'] = $status;
        }

        DB::table('cash_books')->where('id', $id)->update($patch);

        return $this->getBook($id) ?? $book;
    }

    public function deleteBook(int $id): void
    {
        $deleted = DB::table('cash_books')->where('id', $id)->delete();
        if ($deleted < 1) {
            throw new InvalidArgumentException('Cash book not found.');
        }
    }

    /**
     * Request deletion of a cash book (and its entries). Requires admin approval.
     *
     * @return array<string,mixed>
     */
    public function requestDeleteBook(int $bookId, int $userId, string $reason = ''): array
    {
        $book = $this->getBook($bookId);
        if ($book === null) {
            throw new InvalidArgumentException('Cash book not found.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }

        $existing = DB::table('cash_book_delete_requests')
            ->where('book_id', $bookId)
            ->where('status', 'pending')
            ->first();
        if ($existing) {
            throw new InvalidArgumentException('A delete request for this cash book is already pending admin approval.');
        }

        $reason = trim($reason);
        $id = (int) DB::table('cash_book_delete_requests')->insertGetId([
            'book_id' => $bookId,
            'book_name' => (string) $book['name'],
            'entry_count' => (int) ($book['entry_count'] ?? 0),
            'opening_balance' => (float) ($book['opening_balance'] ?? 0),
            'balance_snapshot' => (float) ($book['balance'] ?? 0),
            'reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
            'status' => 'pending',
            'requested_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->formatDeleteRequest(
            (array) DB::table('cash_book_delete_requests')->where('id', $id)->first()
        );
    }

    /**
     * Admin: approve pending delete ù removes the book and all its entries.
     *
     * @return array{request:array<string,mixed>,deleted_book_id:int}
     */
    public function approveDeleteBook(int $requestId, int $adminUserId): array
    {
        if ($adminUserId <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }

        $req = DB::table('cash_book_delete_requests')->where('id', $requestId)->first();
        if (!$req) {
            throw new InvalidArgumentException('Delete request not found.');
        }
        if ((string) ($req->status ?? '') !== 'pending') {
            throw new InvalidArgumentException('This delete request is no longer pending.');
        }

        $bookId = isset($req->book_id) && $req->book_id !== null ? (int) $req->book_id : 0;
        if ($bookId <= 0) {
            throw new InvalidArgumentException('The cash book for this request no longer exists.');
        }

        DB::transaction(function () use ($requestId, $adminUserId, $bookId) {
            DB::table('cash_book_delete_requests')->where('id', $requestId)->update([
                'status' => 'approved',
                'reviewed_by' => $adminUserId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            // Entries cascade via FK.
            $deleted = DB::table('cash_books')->where('id', $bookId)->delete();
            if ($deleted < 1) {
                throw new InvalidArgumentException('Cash book not found.');
            }
        });

        $fresh = DB::table('cash_book_delete_requests')->where('id', $requestId)->first();

        return [
            'request' => $this->formatDeleteRequest($fresh ? (array) $fresh : [
                'id' => $requestId,
                'status' => 'approved',
                'book_id' => null,
                'book_name' => (string) ($req->book_name ?? ''),
            ]),
            'deleted_book_id' => $bookId,
        ];
    }

    /**
     * Admin (or requester): reject / cancel a pending delete request.
     *
     * @return array<string,mixed>
     */
    public function rejectDeleteBook(int $requestId, int $userId, bool $asAdmin): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Not authenticated.');
        }

        $req = DB::table('cash_book_delete_requests')->where('id', $requestId)->first();
        if (!$req) {
            throw new InvalidArgumentException('Delete request not found.');
        }
        if ((string) ($req->status ?? '') !== 'pending') {
            throw new InvalidArgumentException('This delete request is no longer pending.');
        }

        $requestedBy = (int) ($req->requested_by ?? 0);
        if (!$asAdmin && $requestedBy !== $userId) {
            throw new InvalidArgumentException('Only an admin or the requester can cancel this delete request.');
        }

        DB::table('cash_book_delete_requests')->where('id', $requestId)->update([
            'status' => 'rejected',
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        $fresh = DB::table('cash_book_delete_requests')->where('id', $requestId)->first();

        return $this->formatDeleteRequest($fresh ? (array) $fresh : ['id' => $requestId, 'status' => 'rejected']);
    }

    /** @return list<array<string,mixed>> */
    public function listPendingDeleteRequests(): array
    {
        $rows = DB::table('cash_book_delete_requests')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->formatDeleteRequest((array) $row);
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function pendingDeleteForBook(int $bookId): ?array
    {
        $row = DB::table('cash_book_delete_requests')
            ->where('book_id', $bookId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        return $row ? $this->formatDeleteRequest((array) $row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatDeleteRequest(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'book_id' => isset($row['book_id']) && $row['book_id'] !== null ? (int) $row['book_id'] : null,
            'book_name' => (string) ($row['book_name'] ?? ''),
            'entry_count' => (int) ($row['entry_count'] ?? 0),
            'opening_balance' => round((float) ($row['opening_balance'] ?? 0), 2),
            'balance_snapshot' => round((float) ($row['balance_snapshot'] ?? 0), 2),
            'reason' => (string) ($row['reason'] ?? ''),
            'status' => (string) ($row['status'] ?? 'pending'),
            'requested_by' => (int) ($row['requested_by'] ?? 0),
            'reviewed_by' => isset($row['reviewed_by']) && $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
            'reviewed_at' => (string) ($row['reviewed_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array{entries:list<array<string,mixed>>,summary:array<string,mixed>,book:array<string,mixed>}
     */
    public function listEntries(int $bookId, ?string $dateFrom = null, ?string $dateTo = null, ?string $type = null): array
    {
        $book = $this->getBook($bookId);
        if ($book === null) {
            throw new InvalidArgumentException('Cash book not found.');
        }

        $q = DB::table('cash_book_entries as e')
            ->leftJoin('cash_book_categories as c', 'c.id', '=', 'e.category_id')
            ->where('e.book_id', $bookId)
            ->select([
                'e.*',
                'c.name as category_name',
            ])
            ->orderBy('e.entry_date')
            ->orderBy('e.id');

        if ($dateFrom) {
            $q->where('e.entry_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $q->where('e.entry_date', '<=', $dateTo);
        }
        if ($type === 'in' || $type === 'out') {
            $q->where('e.entry_type', $type);
        }

        $rows = $q->get();
        $opening = (float) ($book['opening_balance'] ?? 0);

        // Opening for filtered range: opening + all prior net
        $priorNet = 0.0;
        if ($dateFrom) {
            $prior = DB::table('cash_book_entries')
                ->where('book_id', $bookId)
                ->where('entry_date', '<', $dateFrom)
                ->selectRaw("COALESCE(SUM(CASE WHEN entry_type='in' THEN amount ELSE -amount END),0) as net")
                ->value('net');
            $priorNet = (float) $prior;
        }
        $running = $opening + $priorNet;

        $totalIn = 0.0;
        $totalOut = 0.0;
        $entries = [];
        foreach ($rows as $row) {
            $arr = (array) $row;
            $amount = round((float) ($arr['amount'] ?? 0), 2);
            $entryType = (string) ($arr['entry_type'] ?? 'out');
            if ($entryType === 'in') {
                $running += $amount;
                $totalIn += $amount;
            } else {
                $running -= $amount;
                $totalOut += $amount;
            }
            $entries[] = [
                'id' => (int) $arr['id'],
                'book_id' => (int) $arr['book_id'],
                'entry_date' => (string) $arr['entry_date'],
                'entry_type' => $entryType,
                'amount' => $amount,
                'category_id' => $arr['category_id'] !== null ? (int) $arr['category_id'] : null,
                'category_name' => (string) ($arr['category_name'] ?? ''),
                'party_name' => (string) ($arr['party_name'] ?? ''),
                'remark' => (string) ($arr['remark'] ?? ''),
                'created_by' => $arr['created_by'] !== null ? (int) $arr['created_by'] : null,
                'balance_after' => round($running, 2),
                'created_at' => (string) ($arr['created_at'] ?? ''),
            ];
        }

        // Newest first for UI ledger feel (Cash Book apps show recent on top)
        $entries = array_reverse($entries);

        return [
            'book' => $book,
            'entries' => $entries,
            'summary' => [
                'opening_balance' => round($opening, 2),
                'opening_for_period' => round($opening + $priorNet, 2),
                'total_in' => round($totalIn, 2),
                'total_out' => round($totalOut, 2),
                'net' => round($totalIn - $totalOut, 2),
                'closing_balance' => round($running, 2),
                'entry_count' => count($entries),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createEntry(array $data, int $userId): array
    {
        $bookId = (int) ($data['book_id'] ?? 0);
        if ($bookId <= 0 || $this->getBook($bookId) === null) {
            throw new InvalidArgumentException('Valid cash book is required.');
        }

        $type = strtolower(trim((string) ($data['entry_type'] ?? '')));
        if ($type !== 'in' && $type !== 'out') {
            throw new InvalidArgumentException('Entry type must be cash in or cash out.');
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        $date = trim((string) ($data['entry_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Invalid entry date.');
        }

        $categoryId = isset($data['category_id']) && (int) $data['category_id'] > 0
            ? (int) $data['category_id']
            : null;
        $party = mb_substr(trim((string) ($data['party_name'] ?? '')), 0, 180);
        $remark = mb_substr(trim((string) ($data['remark'] ?? '')), 0, 500);

        $id = (int) DB::table('cash_book_entries')->insertGetId([
            'book_id' => $bookId,
            'entry_date' => $date,
            'entry_type' => $type,
            'amount' => $amount,
            'category_id' => $categoryId,
            'party_name' => $party !== '' ? $party : null,
            'remark' => $remark !== '' ? $remark : null,
            'created_by' => $userId > 0 ? $userId : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catName = '';
        if ($categoryId) {
            $catName = (string) (DB::table('cash_book_categories')->where('id', $categoryId)->value('name') ?? '');
        }

        return [
            'id' => $id,
            'book_id' => $bookId,
            'entry_date' => $date,
            'entry_type' => $type,
            'amount' => $amount,
            'category_id' => $categoryId,
            'category_name' => $catName,
            'party_name' => $party,
            'remark' => $remark,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updateEntry(int $id, array $data): array
    {
        $row = DB::table('cash_book_entries')->where('id', $id)->first();
        if (!$row) {
            throw new InvalidArgumentException('Entry not found.');
        }

        $patch = ['updated_at' => now()];
        if (array_key_exists('entry_date', $data)) {
            $date = trim((string) $data['entry_date']);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException('Invalid entry date.');
            }
            $patch['entry_date'] = $date;
        }
        if (array_key_exists('entry_type', $data)) {
            $type = strtolower(trim((string) $data['entry_type']));
            if ($type !== 'in' && $type !== 'out') {
                throw new InvalidArgumentException('Entry type must be cash in or cash out.');
            }
            $patch['entry_type'] = $type;
        }
        if (array_key_exists('amount', $data)) {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new InvalidArgumentException('Amount must be greater than zero.');
            }
            $patch['amount'] = $amount;
        }
        if (array_key_exists('category_id', $data)) {
            $cid = (int) $data['category_id'];
            $patch['category_id'] = $cid > 0 ? $cid : null;
        }
        if (array_key_exists('party_name', $data)) {
            $party = mb_substr(trim((string) $data['party_name']), 0, 180);
            $patch['party_name'] = $party !== '' ? $party : null;
        }
        if (array_key_exists('remark', $data)) {
            $remark = mb_substr(trim((string) $data['remark']), 0, 500);
            $patch['remark'] = $remark !== '' ? $remark : null;
        }

        DB::table('cash_book_entries')->where('id', $id)->update($patch);
        $fresh = DB::table('cash_book_entries as e')
            ->leftJoin('cash_book_categories as c', 'c.id', '=', 'e.category_id')
            ->where('e.id', $id)
            ->select(['e.*', 'c.name as category_name'])
            ->first();

        $arr = (array) $fresh;

        return [
            'id' => (int) $arr['id'],
            'book_id' => (int) $arr['book_id'],
            'entry_date' => (string) $arr['entry_date'],
            'entry_type' => (string) $arr['entry_type'],
            'amount' => round((float) $arr['amount'], 2),
            'category_id' => $arr['category_id'] !== null ? (int) $arr['category_id'] : null,
            'category_name' => (string) ($arr['category_name'] ?? ''),
            'party_name' => (string) ($arr['party_name'] ?? ''),
            'remark' => (string) ($arr['remark'] ?? ''),
        ];
    }

    public function deleteEntry(int $id): void
    {
        $deleted = DB::table('cash_book_entries')->where('id', $id)->delete();
        if ($deleted < 1) {
            throw new InvalidArgumentException('Entry not found.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function listCategories(?string $entryType = null): array
    {
        $q = DB::table('cash_book_categories')
            ->where('status', 'active')
            ->orderBy('name');
        if ($entryType === 'in' || $entryType === 'out') {
            $q->where(function ($w) use ($entryType) {
                $w->where('entry_type', $entryType)->orWhere('entry_type', 'both');
            });
        }

        return array_map(static function ($row) {
            $a = (array) $row;

            return [
                'id' => (int) $a['id'],
                'name' => (string) $a['name'],
                'entry_type' => (string) $a['entry_type'],
                'status' => (string) $a['status'],
            ];
        }, $q->get()->all());
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function createCategory(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Category name is required.');
        }
        $type = strtolower(trim((string) ($data['entry_type'] ?? 'both')));
        if (!in_array($type, ['in', 'out', 'both'], true)) {
            $type = 'both';
        }

        try {
            $id = (int) DB::table('cash_book_categories')->insertGetId([
                'name' => mb_substr($name, 0, 120),
                'entry_type' => $type,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Category name already exists.');
        }

        return [
            'id' => $id,
            'name' => mb_substr($name, 0, 120),
            'entry_type' => $type,
            'status' => 'active',
        ];
    }

    public function deleteCategory(int $id): void
    {
        DB::table('cash_book_entries')->where('category_id', $id)->update(['category_id' => null]);
        $deleted = DB::table('cash_book_categories')->where('id', $id)->delete();
        if ($deleted < 1) {
            throw new InvalidArgumentException('Category not found.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function report(?string $dateFrom = null, ?string $dateTo = null, ?int $bookId = null): array
    {
        $q = DB::table('cash_book_entries as e')
            ->join('cash_books as b', 'b.id', '=', 'e.book_id')
            ->leftJoin('cash_book_categories as c', 'c.id', '=', 'e.category_id');

        if ($bookId && $bookId > 0) {
            $q->where('e.book_id', $bookId);
        }
        if ($dateFrom) {
            $q->where('e.entry_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $q->where('e.entry_date', '<=', $dateTo);
        }

        $totals = (clone $q)->selectRaw("
            COALESCE(SUM(CASE WHEN e.entry_type='in' THEN e.amount ELSE 0 END),0) as total_in,
            COALESCE(SUM(CASE WHEN e.entry_type='out' THEN e.amount ELSE 0 END),0) as total_out,
            COUNT(*) as entry_count
        ")->first();

        $byCategory = (clone $q)
            ->selectRaw("
                COALESCE(c.name, 'Uncategorised') as category_name,
                e.entry_type,
                SUM(e.amount) as total,
                COUNT(*) as cnt
            ")
            ->groupBy('category_name', 'e.entry_type')
            ->orderBy('category_name')
            ->get()
            ->map(static fn ($r) => [
                'category_name' => (string) $r->category_name,
                'entry_type' => (string) $r->entry_type,
                'total' => round((float) $r->total, 2),
                'count' => (int) $r->cnt,
            ])
            ->all();

        $byBook = (clone $q)
            ->selectRaw("
                b.id as book_id,
                b.name as book_name,
                COALESCE(SUM(CASE WHEN e.entry_type='in' THEN e.amount ELSE 0 END),0) as total_in,
                COALESCE(SUM(CASE WHEN e.entry_type='out' THEN e.amount ELSE 0 END),0) as total_out,
                COUNT(*) as entry_count
            ")
            ->groupBy('b.id', 'b.name')
            ->orderBy('b.name')
            ->get()
            ->map(static fn ($r) => [
                'book_id' => (int) $r->book_id,
                'book_name' => (string) $r->book_name,
                'total_in' => round((float) $r->total_in, 2),
                'total_out' => round((float) $r->total_out, 2),
                'net' => round((float) $r->total_in - (float) $r->total_out, 2),
                'entry_count' => (int) $r->entry_count,
            ])
            ->all();

        $totalIn = round((float) ($totals->total_in ?? 0), 2);
        $totalOut = round((float) ($totals->total_out ?? 0), 2);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'book_id' => $bookId,
            'summary' => [
                'total_in' => $totalIn,
                'total_out' => $totalOut,
                'net' => round($totalIn - $totalOut, 2),
                'entry_count' => (int) ($totals->entry_count ?? 0),
            ],
            'by_category' => $byCategory,
            'by_book' => $byBook,
        ];
    }

    /**
     * Import spreadsheet rows into a cash book.
     *
     * Supports the Cash Book Excel layout:
     *   Date | Notes | Cash In | Cash Out | Balance
     * Also supports: date, type, amount, category, party, remark
     *
     * @param array<string,mixed> $file
     * @return array{imported:int,skipped:int,errors:list<string>}
     */
    public function importFromSpreadsheet(int $bookId, array $file, int $userId): array
    {
        if ($this->getBook($bookId) === null) {
            throw new InvalidArgumentException('Cash book not found.');
        }

        $parsed = (new SpreadsheetReader())->readUpload($file);
        if (!($parsed['ok'] ?? false)) {
            throw new InvalidArgumentException((string) ($parsed['error'] ?? 'Could not read file.'));
        }

        /** @var list<list<string>> $matrix */
        $matrix = $parsed['matrix'] ?? [];
        $located = $this->locateImportHeader($matrix);
        if ($located === null) {
            throw new InvalidArgumentException(
                'Could not find header row. Expected columns like: Date, Notes, Cash In, Cash Out, Balance.'
            );
        }

        [$headerIndex, $map, $cashBookStyle] = $located;
        $rows = array_slice($matrix, $headerIndex + 1);

        $categories = [];
        foreach ($this->listCategories() as $cat) {
            $categories[strtolower((string) $cat['name'])] = (int) $cat['id'];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $lineNo = $headerIndex + $i + 2;
            try {
                if ($cashBookStyle) {
                    $entry = $this->parseCashBookStyleRow($row, $map);
                } else {
                    $entry = $this->parseGenericImportRow($row, $map);
                }
                if ($entry === null) {
                    $skipped++;
                    continue;
                }

                $categoryId = null;
                $catName = trim((string) ($entry['category'] ?? ''));
                if ($catName !== '') {
                    $key = strtolower($catName);
                    if (!isset($categories[$key])) {
                        $created = $this->createCategory([
                            'name' => $catName,
                            'entry_type' => 'both',
                        ]);
                        $categories[$key] = (int) $created['id'];
                    }
                    $categoryId = $categories[$key];
                }

                $this->createEntry([
                    'book_id' => $bookId,
                    'entry_type' => $entry['entry_type'],
                    'entry_date' => $entry['entry_date'],
                    'amount' => $entry['amount'],
                    'category_id' => $categoryId,
                    'party_name' => $entry['party_name'] ?? '',
                    'remark' => $entry['remark'] ?? '',
                ], $userId);
                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                if (count($errors) < 20) {
                    $errors[] = 'Row ' . $lineNo . ': ' . $e->getMessage();
                }
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<list<string>> $matrix
     * @return array{0:int,1:array<string,int>,2:bool}|null
     */
    private function locateImportHeader(array $matrix): ?array
    {
        foreach ($matrix as $idx => $row) {
            $headers = array_map(
                static fn ($h) => strtolower(trim((string) $h)),
                $row
            );
            $map = $this->mapImportHeaders($headers);
            $cashBookStyle = isset($map['date'], $map['cash_in'], $map['cash_out']);
            $generic = isset($map['date'], $map['type'], $map['amount']);
            if ($cashBookStyle || $generic) {
                return [$idx, $map, $cashBookStyle];
            }
        }

        return null;
    }

    /**
     * @param list<string> $row
     * @param array<string,int> $map
     * @return array{entry_date:string,entry_type:string,amount:float,remark?:string,party_name?:string,category?:string}|null
     */
    private function parseCashBookStyleRow(array $row, array $map): ?array
    {
        $dateRaw = trim((string) ($row[$map['date']] ?? ''));
        $notes = isset($map['remark']) ? trim((string) ($row[$map['remark']] ?? '')) : '';
        $inRaw = trim((string) ($row[$map['cash_in']] ?? ''));
        $outRaw = trim((string) ($row[$map['cash_out']] ?? ''));

        // Skip title / opening balance rows
        if ($dateRaw === '' || strcasecmp($dateRaw, 'date') === 0) {
            return null;
        }
        if (stripos($notes, 'previous balance') !== false || stripos($dateRaw, 'previous') !== false) {
            return null;
        }

        $inAmt = $this->normalizeImportAmount($inRaw === '' ? '0' : $inRaw);
        $outAmt = $this->normalizeImportAmount($outRaw === '' ? '0' : $outRaw);

        if ($inAmt <= 0 && $outAmt <= 0) {
            return null;
        }
        if ($inAmt > 0 && $outAmt > 0) {
            throw new InvalidArgumentException('Row has both Cash In and Cash Out amounts.');
        }

        return [
            'entry_date' => $this->normalizeImportDate($dateRaw),
            'entry_type' => $inAmt > 0 ? 'in' : 'out',
            'amount' => $inAmt > 0 ? $inAmt : $outAmt,
            'remark' => $notes,
            'party_name' => '',
            'category' => '',
        ];
    }

    /**
     * @param list<string> $row
     * @param array<string,int> $map
     * @return array{entry_date:string,entry_type:string,amount:float,remark?:string,party_name?:string,category?:string}|null
     */
    private function parseGenericImportRow(array $row, array $map): ?array
    {
        $dateRaw = trim((string) ($row[$map['date']] ?? ''));
        $typeRaw = trim((string) ($row[$map['type']] ?? ''));
        $amountRaw = trim((string) ($row[$map['amount']] ?? ''));
        if ($dateRaw === '' && $typeRaw === '' && $amountRaw === '') {
            return null;
        }

        $amount = $this->normalizeImportAmount($amountRaw);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return [
            'entry_date' => $this->normalizeImportDate($dateRaw),
            'entry_type' => $this->normalizeImportType($typeRaw),
            'amount' => $amount,
            'category' => isset($map['category']) ? trim((string) ($row[$map['category']] ?? '')) : '',
            'party_name' => isset($map['party']) ? trim((string) ($row[$map['party']] ?? '')) : '',
            'remark' => isset($map['remark']) ? trim((string) ($row[$map['remark']] ?? '')) : '',
        ];
    }

    /**
     * @param list<string> $headers
     * @return array<string,int>
     */
    private function mapImportHeaders(array $headers): array
    {
        $aliases = [
            'date' => ['date', 'entry_date', 'entry date', 'txn_date', 'transaction date'],
            'type' => ['type', 'entry_type', 'entry type', 'cash type', 'in_out', 'inout'],
            'amount' => ['amount', 'value', 'money', 'sum'],
            'cash_in' => ['cash in', 'cash_in', 'cashin', 'in amount', 'credit'],
            'cash_out' => ['cash out', 'cash_out', 'cashout', 'out amount', 'debit'],
            'category' => ['category', 'cat', 'category name'],
            'party' => ['party', 'party_name', 'payee', 'payer'],
            'remark' => ['remark', 'remarks', 'note', 'notes', 'description', 'narration'],
            'balance' => ['balance', 'running balance', 'bal'],
        ];
        $map = [];
        foreach ($headers as $idx => $header) {
            $h = strtolower(trim(str_replace('_', ' ', $header)));
            foreach ($aliases as $key => $names) {
                if (isset($map[$key])) {
                    continue;
                }
                if (in_array($h, $names, true) || in_array(str_replace(' ', '_', $h), $names, true)) {
                    $map[$key] = $idx;
                }
            }
        }

        return $map;
    }

    private function normalizeImportDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new InvalidArgumentException('Date is required.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        // 08-Jul-2026 / 11-Sep-2026
        if (preg_match('/^\d{1,2}-[A-Za-z]{3}-\d{4}$/', $raw)) {
            $ts = strtotime($raw);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $raw)) {
            [$a, $b, $y] = array_map('intval', explode('/', $raw));
            if ($a > 12) {
                return sprintf('%04d-%02d-%02d', $y, $b, $a);
            }
            if ($b > 12) {
                return sprintf('%04d-%02d-%02d', $y, $a, $b);
            }

            return sprintf('%04d-%02d-%02d', $y, $a, $b);
        }
        // Excel serial date
        if (is_numeric($raw) && (float) $raw > 20000 && (float) $raw < 80000) {
            $unix = ((int) round((float) $raw) - 25569) * 86400;

            return gmdate('Y-m-d', $unix);
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            throw new InvalidArgumentException('Invalid date "' . $raw . '".');
        }

        return date('Y-m-d', $ts);
    }

    private function normalizeImportType(string $raw): string
    {
        $v = strtolower(trim($raw));
        $v = str_replace(['_', '-'], ' ', $v);
        if (in_array($v, ['in', 'cash in', 'credit', 'cr', '+', 'income', 'receive', 'received'], true)) {
            return 'in';
        }
        if (in_array($v, ['out', 'cash out', 'debit', 'dr', '-', 'expense', 'pay', 'paid'], true)) {
            return 'out';
        }
        throw new InvalidArgumentException('Type must be cash in or cash out.');
    }

    private function normalizeImportAmount(string $raw): float
    {
        $v = trim($raw);
        if ($v === '' || strtoupper($v) === 'NULL') {
            return 0.0;
        }
        $v = str_replace([',', ' ', 'TZS', 'Tsh', 'tsh'], '', $v);
        if ($v === '' || !is_numeric($v)) {
            throw new InvalidArgumentException('Invalid amount.');
        }

        return round(abs((float) $v), 2);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateBook(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $opening = round((float) ($row['opening_balance'] ?? 0), 2);
        $agg = DB::table('cash_book_entries')
            ->where('book_id', $id)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN entry_type='in' THEN amount ELSE 0 END),0) as total_in,
                COALESCE(SUM(CASE WHEN entry_type='out' THEN amount ELSE 0 END),0) as total_out,
                COUNT(*) as entry_count
            ")
            ->first();
        $totalIn = round((float) ($agg->total_in ?? 0), 2);
        $totalOut = round((float) ($agg->total_out ?? 0), 2);

        $balance = round($opening + $totalIn - $totalOut, 2);
        $pendingDelete = null;
        try {
            $pendingDelete = $this->pendingDeleteForBook($id);
        } catch (Throwable $e) {
            $pendingDelete = null;
        }

        return [
            'id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'opening_balance' => $opening,
            'notes' => (string) ($row['notes'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'created_by' => isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'balance' => $balance,
            'entry_count' => (int) ($agg->entry_count ?? 0),
            'delete_request' => $pendingDelete,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
