<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\ApprovalWorkflowService;
use App\Application\Services\ComplianceReportService;
use App\Application\Services\PeriodCloseService;
use App\Application\Services\YearCloseService;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use App\Models\ComplianceReport;
use App\Models\JournalEntry;
use App\Models\PeriodClose;
use App\Models\YearClose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GovernanceController extends Controller
{
    public function __construct(
        private readonly ApprovalWorkflowService $approvals,
        private readonly PeriodCloseService $periodClose,
        private readonly YearCloseService $yearClose,
        private readonly ComplianceReportService $compliance,
    ) {}

    public function workflows(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => ApprovalWorkflow::query()
                ->where('company_id', $company->id)
                ->with('steps')
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function storeWorkflow(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $payload = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'entity_type' => ['required', 'string', 'max:80'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.name' => ['required', 'string', 'max:150'],
            'steps.*.step_no' => ['nullable', 'integer', 'min:1'],
            'steps.*.required_permission' => ['nullable', 'string', 'max:80'],
        ]);

        $workflow = $this->approvals->createWorkflow((int) $company->id, $payload);

        return response()->json(['data' => $workflow], 201);
    }

    public function approvalRequests(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json([
            'data' => ApprovalRequest::query()
                ->where('company_id', $company->id)
                ->with(['workflow.steps', 'actions'])
                ->orderByDesc('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function submitJournalApproval(Request $request, int $id): JsonResponse
    {
        $book = $request->attributes->get('book');
        $journal = JournalEntry::query()->where('book_id', $book->id)->findOrFail($id);
        $data = $request->validate(['notes' => ['nullable', 'string']]);

        $approval = $this->approvals->submitJournal($journal, $book, $request->user(), $data['notes'] ?? null);

        return response()->json(['data' => $approval], 201);
    }

    public function actOnApproval(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');
        $approval = ApprovalRequest::query()->where('company_id', $company->id)->findOrFail($id);
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'comments' => ['nullable', 'string'],
        ]);

        $updated = $this->approvals->act($approval, $request->user(), $data['action'], $data['comments'] ?? null);

        return response()->json(['data' => $updated]);
    }

    public function monthEndChecklist(Request $request): JsonResponse
    {
        $data = $request->validate(['period_id' => ['required', 'integer', 'exists:acc_fiscal_periods,id']]);
        $checklist = $this->periodClose->buildChecklist(
            $request->attributes->get('book'),
            $request->user(),
            (int) $data['period_id']
        );

        return response()->json(['data' => $checklist]);
    }

    public function closePeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:acc_fiscal_periods,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $close = $this->periodClose->close(
            $request->attributes->get('book'),
            $request->user(),
            (int) $data['period_id'],
            $data['notes'] ?? null
        );

        return response()->json(['data' => $close], 201);
    }

    public function periodCloses(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => PeriodClose::query()->where('book_id', $book->id)->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function yearEndChecklist(Request $request): JsonResponse
    {
        $data = $request->validate(['fiscal_year_id' => ['required', 'integer', 'exists:acc_fiscal_years,id']]);
        $checklist = $this->yearClose->buildChecklist(
            $request->attributes->get('book'),
            $request->user(),
            (int) $data['fiscal_year_id']
        );

        return response()->json(['data' => $checklist]);
    }

    public function closeYear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'integer', 'exists:acc_fiscal_years,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $close = $this->yearClose->close(
            $request->attributes->get('book'),
            $request->user(),
            (int) $data['fiscal_year_id'],
            $data['notes'] ?? null
        );

        return response()->json(['data' => $close], 201);
    }

    public function yearCloses(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => YearClose::query()->where('book_id', $book->id)->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $filters = $request->validate([
            'action' => ['nullable', 'string'],
            'entity_type' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->compliance->auditSearch((int) $company->id, $filters),
        ]);
    }

    public function complianceReports(Request $request): JsonResponse
    {
        $book = $request->attributes->get('book');

        return response()->json([
            'data' => ComplianceReport::query()->where('book_id', $book->id)->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function generateCompliance(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of_date' => ['required', 'date']]);
        $report = $this->compliance->generate(
            $request->attributes->get('book'),
            $request->user(),
            $data['as_of_date']
        );

        return response()->json(['data' => $report], 201);
    }
}
