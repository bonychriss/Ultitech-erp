<?php

namespace App\Http\Middleware;

use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\UserCompanyAccess;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyAccess
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $companyKey = $request->header('X-Company-Id') ?: $request->query('company_id');
        if (! $companyKey) {
            return response()->json(['message' => 'X-Company-Id header is required.'], 422);
        }

        $company = Company::query()
            ->when(is_numeric($companyKey), fn ($q) => $q->where('id', $companyKey))
            ->when(! is_numeric($companyKey), fn ($q) => $q->where('uuid', $companyKey))
            ->first();

        if (! $company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $access = UserCompanyAccess::query()
            ->where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->first();

        if (! $access) {
            return response()->json(['message' => 'Forbidden for this company.'], 403);
        }

        $bookKey = $request->header('X-Book-Id');
        $bookQuery = AccountingBook::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active');

        $book = $bookKey
            ? $bookQuery->where('id', $bookKey)->first()
            : $bookQuery->where('is_primary', true)->first();

        if (! $book) {
            return response()->json(['message' => 'Accounting book not found.'], 404);
        }

        $this->tenant->setUser($user);
        $this->tenant->setCompany($company);
        $this->tenant->setBook($book);

        $request->attributes->set('company', $company);
        $request->attributes->set('book', $book);
        $request->attributes->set('company_access', $access);

        return $next($request);
    }
}
