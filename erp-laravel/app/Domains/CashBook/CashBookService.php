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

        return [
            'id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'opening_balance' => $opening,
            'notes' => (string) ($row['notes'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'created_by' => isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'balance' => round($opening + $totalIn - $totalOut, 2),
            'entry_count' => (int) ($agg->entry_count ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
