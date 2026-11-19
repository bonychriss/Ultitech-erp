<?php

namespace App\Application\Services;

use App\Models\AccountingBook;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalWorkflow;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalWorkflowService
{
    public function __construct(private readonly AuditTrailService $audit) {}

    public function createWorkflow(int $companyId, array $payload): ApprovalWorkflow
    {
        return DB::transaction(function () use ($companyId, $payload) {
            $workflow = ApprovalWorkflow::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'code' => $payload['code'],
                'name' => $payload['name'],
                'entity_type' => $payload['entity_type'],
                'is_active' => true,
            ]);

            foreach ($payload['steps'] as $index => $step) {
                ApprovalStep::query()->create([
                    'workflow_id' => $workflow->id,
                    'step_no' => $step['step_no'] ?? ($index + 1),
                    'name' => $step['name'],
                    'required_permission' => $step['required_permission'] ?? null,
                ]);
            }

            return $workflow->load('steps');
        });
    }

    public function activeWorkflow(int $companyId, string $entityType): ?ApprovalWorkflow
    {
        return ApprovalWorkflow::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->with('steps')
            ->first();
    }

    public function submitJournal(JournalEntry $journal, AccountingBook $book, User $user, ?string $notes = null): ApprovalRequest
    {
        if (! $journal->isDraft()) {
            throw ValidationException::withMessages(['journal' => 'Only draft journals can be submitted for approval.']);
        }

        $workflow = $this->activeWorkflow((int) $book->company_id, 'journal_entry');
        if (! $workflow || $workflow->steps->isEmpty()) {
            throw ValidationException::withMessages(['workflow' => 'No active journal approval workflow configured.']);
        }

        $existing = ApprovalRequest::withoutGlobalScopes()
            ->where('company_id', $book->company_id)
            ->where('entity_type', 'journal_entry')
            ->where('entity_id', $journal->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages(['journal' => 'An approval request already exists for this journal.']);
        }

        return DB::transaction(function () use ($journal, $book, $user, $workflow, $notes) {
            $request = ApprovalRequest::withoutGlobalScopes()->create([
                'company_id' => $book->company_id,
                'book_id' => $book->id,
                'workflow_id' => $workflow->id,
                'entity_type' => 'journal_entry',
                'entity_id' => $journal->id,
                'status' => 'pending',
                'current_step' => 1,
                'requested_by' => $user->id,
                'requested_at' => now(),
                'notes' => $notes,
            ]);

            $journal->update([
                'approval_status' => 'pending',
                'approval_request_id' => $request->id,
            ]);

            $this->audit->record('approval.submitted', 'approval_request', $request->id, null, [
                'entity_type' => 'journal_entry',
                'entity_id' => $journal->id,
            ]);

            return $request->load(['workflow.steps', 'actions']);
        });
    }

    public function act(ApprovalRequest $request, User $user, string $action, ?string $comments = null): ApprovalRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Approval request is not pending.']);
        }

        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['action' => 'Action must be approve or reject.']);
        }

        return DB::transaction(function () use ($request, $user, $action, $comments) {
            ApprovalAction::query()->create([
                'approval_request_id' => $request->id,
                'company_id' => $request->company_id,
                'step_no' => $request->current_step,
                'action' => $action,
                'acted_by' => $user->id,
                'comments' => $comments,
                'acted_at' => now(),
            ]);

            if ($action === 'reject') {
                $request->update([
                    'status' => 'rejected',
                    'resolved_at' => now(),
                ]);
                $this->syncJournal($request, 'rejected');
                $this->audit->record('approval.rejected', 'approval_request', $request->id, null, [
                    'step' => $request->current_step,
                ]);

                return $request->fresh(['workflow.steps', 'actions']);
            }

            $maxStep = (int) $request->workflow->steps()->max('step_no');
            if ($request->current_step >= $maxStep) {
                $request->update([
                    'status' => 'approved',
                    'resolved_at' => now(),
                ]);
                $this->syncJournal($request, 'approved');
                $this->audit->record('approval.approved', 'approval_request', $request->id, null, [
                    'final_step' => $request->current_step,
                ]);
            } else {
                $request->update(['current_step' => $request->current_step + 1]);
                $this->audit->record('approval.step_advanced', 'approval_request', $request->id, null, [
                    'current_step' => $request->current_step,
                ]);
            }

            return $request->fresh(['workflow.steps', 'actions']);
        });
    }

    public function assertJournalPostable(JournalEntry $journal): void
    {
        if (in_array($journal->approval_status, ['pending', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'approval_status' => 'Journal approval is '.$journal->approval_status.'; posting is blocked.',
            ]);
        }

        $workflow = $this->activeWorkflow((int) $journal->company_id, 'journal_entry');
        if (! $workflow) {
            return;
        }

        $journal->loadMissing('source');
        $sourceCode = $journal->source?->code ?? 'manual';

        // Operational/system postings bypass workflow; manual journals must be approved.
        if ($sourceCode === 'manual' && $journal->approval_status !== 'approved') {
            throw ValidationException::withMessages([
                'approval_status' => 'Manual journals require approval before posting.',
            ]);
        }
    }

    private function syncJournal(ApprovalRequest $request, string $status): void
    {
        if ($request->entity_type !== 'journal_entry') {
            return;
        }

        JournalEntry::withoutGlobalScopes()
            ->whereKey($request->entity_id)
            ->update(['approval_status' => $status]);
    }
}
