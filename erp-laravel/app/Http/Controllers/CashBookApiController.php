<?php

namespace App\Http\Controllers;

use App\Domains\CashBook\CashBookService;
use App\Domains\CashBook\Schema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Cash Book JSON API — Domains/CashBook.
 */
class CashBookApiController extends Controller
{
    public function handle(Request $request, string $resource, ?int $id = null): JsonResponse
    {
        $erp = $request->attributes->get('erp', []);
        $userId = (int) ($erp['user_id'] ?? 0);
        if ($userId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Not authenticated.'], 401);
        }

        try {
            Schema::ensure();
            $svc = new CashBookService();
            $method = strtoupper($request->method());
            $resource = strtolower(trim($resource));

            return match ($resource) {
                'init' => $this->init($svc, $erp),
                'books' => $this->books($request, $svc, $userId, $id, $method),
                'entries' => $this->entries($request, $svc, $userId, $id, $method),
                'categories' => $this->categories($request, $svc, $id, $method),
                'reports' => $this->reports($request, $svc),
                'import' => $this->import($request, $svc, $userId, $method),
                'delete-requests' => $this->deleteRequests($request, $svc, $erp, $userId, $id, $method),
                default => response()->json(['ok' => false, 'error' => 'Unknown resource.'], 404),
            };
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('CashBookApiController: ' . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'Server error.'], 500);
        }
    }

    /** @param array<string,mixed> $erp */
    private function isAdmin(array $erp): bool
    {
        if (array_key_exists('is_admin', $erp)) {
            return (bool) $erp['is_admin'];
        }

        return function_exists('isAdmin') && isAdmin();
    }

    /** @param array<string,mixed> $erp */
    private function init(CashBookService $svc, array $erp): JsonResponse
    {
        $books = $svc->listBooks('active');
        $totalBalance = 0.0;
        $totalIn = 0.0;
        $totalOut = 0.0;
        foreach ($books as $b) {
            $totalBalance += (float) ($b['balance'] ?? 0);
            $totalIn += (float) ($b['total_in'] ?? 0);
            $totalOut += (float) ($b['total_out'] ?? 0);
        }

        $isAdmin = $this->isAdmin($erp);

        return response()->json([
            'ok' => true,
            'engine' => 'erp-laravel Domains/CashBook',
            'user' => [
                'id' => (int) ($erp['user_id'] ?? 0),
                'name' => (string) ($erp['full_name'] ?? ''),
                'is_admin' => $isAdmin,
            ],
            'capabilities' => [
                'approve_delete' => $isAdmin,
                'request_delete' => true,
            ],
            'books' => $books,
            'pending_deletes' => $svc->listPendingDeleteRequests(),
            'summary' => [
                'book_count' => count($books),
                'total_balance' => round($totalBalance, 2),
                'total_in' => round($totalIn, 2),
                'total_out' => round($totalOut, 2),
            ],
            'categories' => $svc->listCategories(),
        ]);
    }

    private function books(Request $request, CashBookService $svc, int $userId, ?int $id, string $method): JsonResponse
    {
        if ($method === 'GET' && $id) {
            $book = $svc->getBook($id);
            if ($book === null) {
                return response()->json(['ok' => false, 'error' => 'Cash book not found.'], 404);
            }

            return response()->json(['ok' => true, 'book' => $book]);
        }

        if ($method === 'GET') {
            $status = (string) $request->query('status', 'active');

            return response()->json(['ok' => true, 'books' => $svc->listBooks($status)]);
        }

        if ($method === 'POST' && !$id) {
            $book = $svc->createBook($request->all(), $userId);

            return response()->json(['ok' => true, 'book' => $book, 'message' => 'Cash book created.'], 201);
        }

        if (($method === 'PUT' || $method === 'POST') && $id) {
            $book = $svc->updateBook($id, $request->all());

            return response()->json(['ok' => true, 'book' => $book, 'message' => 'Cash book updated.']);
        }

        // DELETE no longer hard-deletes — it queues an admin-approval request.
        if ($method === 'DELETE' && $id) {
            $reason = trim((string) $request->input('reason', ''));
            $req = $svc->requestDeleteBook($id, $userId, $reason);

            return response()->json([
                'ok' => true,
                'delete_request' => $req,
                'message' => 'Delete requested. Waiting for admin approval.',
            ]);
        }

        return response()->json(['ok' => false, 'error' => 'Unsupported books action.'], 405);
    }

    /** @param array<string,mixed> $erp */
    private function deleteRequests(
        Request $request,
        CashBookService $svc,
        array $erp,
        int $userId,
        ?int $id,
        string $method
    ): JsonResponse {
        if ($method === 'GET') {
            return response()->json([
                'ok' => true,
                'requests' => $svc->listPendingDeleteRequests(),
            ]);
        }

        // Create request via POST { book_id, reason }
        if ($method === 'POST' && !$id) {
            $bookId = (int) $request->input('book_id', 0);
            if ($bookId <= 0) {
                return response()->json(['ok' => false, 'error' => 'book_id is required.'], 422);
            }
            $reason = trim((string) $request->input('reason', ''));
            $req = $svc->requestDeleteBook($bookId, $userId, $reason);

            return response()->json([
                'ok' => true,
                'delete_request' => $req,
                'message' => 'Delete requested. Waiting for admin approval.',
            ], 201);
        }

        if (($method === 'POST' || $method === 'PUT') && $id) {
            $action = strtolower(trim((string) (
                $request->input('action')
                ?? $request->query('action')
                ?? ''
            )));

            if ($action === 'approve') {
                if (!$this->isAdmin($erp)) {
                    return response()->json(['ok' => false, 'error' => 'Admin approval required.'], 403);
                }
                $result = $svc->approveDeleteBook($id, $userId);

                return response()->json([
                    'ok' => true,
                    'delete_request' => $result['request'],
                    'deleted_book_id' => $result['deleted_book_id'],
                    'message' => 'Cash book and its records deleted.',
                ]);
            }

            if ($action === 'reject' || $action === 'cancel') {
                $asAdmin = $this->isAdmin($erp);
                $req = $svc->rejectDeleteBook($id, $userId, $asAdmin);

                return response()->json([
                    'ok' => true,
                    'delete_request' => $req,
                    'message' => $action === 'cancel' ? 'Delete request cancelled.' : 'Delete request rejected.',
                ]);
            }

            return response()->json(['ok' => false, 'error' => 'action must be approve, reject, or cancel.'], 422);
        }

        return response()->json(['ok' => false, 'error' => 'Unsupported delete-requests action.'], 405);
    }

    private function entries(Request $request, CashBookService $svc, int $userId, ?int $id, string $method): JsonResponse
    {
        if ($method === 'GET') {
            $bookId = (int) $request->query('book_id', 0);
            if ($bookId <= 0) {
                return response()->json(['ok' => false, 'error' => 'book_id is required.'], 422);
            }
            $payload = $svc->listEntries(
                $bookId,
                $this->optionalDate($request->query('date_from')),
                $this->optionalDate($request->query('date_to')),
                $this->optionalType($request->query('entry_type'))
            );

            return response()->json(['ok' => true] + $payload);
        }

        if ($method === 'POST' && !$id) {
            $entry = $svc->createEntry($request->all(), $userId);

            return response()->json(['ok' => true, 'entry' => $entry, 'message' => 'Entry saved.'], 201);
        }

        if (($method === 'PUT' || $method === 'POST') && $id) {
            $entry = $svc->updateEntry($id, $request->all());

            return response()->json(['ok' => true, 'entry' => $entry, 'message' => 'Entry updated.']);
        }

        if ($method === 'DELETE' && $id) {
            $svc->deleteEntry($id);

            return response()->json(['ok' => true, 'message' => 'Entry deleted.']);
        }

        return response()->json(['ok' => false, 'error' => 'Unsupported entries action.'], 405);
    }

    private function categories(Request $request, CashBookService $svc, ?int $id, string $method): JsonResponse
    {
        if ($method === 'GET') {
            return response()->json([
                'ok' => true,
                'categories' => $svc->listCategories($this->optionalType($request->query('entry_type'))),
            ]);
        }

        if ($method === 'POST' && !$id) {
            $cat = $svc->createCategory($request->all());

            return response()->json(['ok' => true, 'category' => $cat, 'message' => 'Category created.'], 201);
        }

        if ($method === 'DELETE' && $id) {
            $svc->deleteCategory($id);

            return response()->json(['ok' => true, 'message' => 'Category deleted.']);
        }

        return response()->json(['ok' => false, 'error' => 'Unsupported categories action.'], 405);
    }

    private function reports(Request $request, CashBookService $svc): JsonResponse
    {
        $bookId = (int) $request->query('book_id', 0);
        $report = $svc->report(
            $this->optionalDate($request->query('date_from')),
            $this->optionalDate($request->query('date_to')),
            $bookId > 0 ? $bookId : null
        );

        return response()->json(['ok' => true, 'report' => $report]);
    }

    private function import(Request $request, CashBookService $svc, int $userId, string $method): JsonResponse
    {
        if ($method !== 'POST') {
            return response()->json(['ok' => false, 'error' => 'POST required.'], 405);
        }

        $bookId = (int) $request->input('book_id', 0);
        if ($bookId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Select a cash book.'], 422);
        }

        $file = $request->file('file');
        if ($file === null) {
            $uploaded = $_FILES['file'] ?? null;
            if (!is_array($uploaded)) {
                return response()->json(['ok' => false, 'error' => 'Please choose an Excel or CSV file.'], 422);
            }
            $result = $svc->importFromSpreadsheet($bookId, $uploaded, $userId);
        } else {
            $result = $svc->importFromSpreadsheet($bookId, [
                'name' => $file->getClientOriginalName(),
                'tmp_name' => $file->getRealPath(),
                'error' => UPLOAD_ERR_OK,
            ], $userId);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Import finished.',
            'result' => $result,
        ]);
    }

    private function optionalDate(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }

        return $v;
    }

    private function optionalType(mixed $value): ?string
    {
        $v = strtolower(trim((string) ($value ?? '')));

        return ($v === 'in' || $v === 'out') ? $v : null;
    }
}
