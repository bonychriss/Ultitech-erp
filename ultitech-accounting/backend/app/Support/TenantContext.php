<?php

namespace App\Support;

use App\Models\AccountingBook;
use App\Models\Company;
use App\Models\User;
use RuntimeException;

class TenantContext
{
    private ?Company $company = null;

    private ?AccountingBook $book = null;

    private ?User $user = null;

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function setCompany(?Company $company): void
    {
        $this->company = $company;
    }

    public function setBook(?AccountingBook $book): void
    {
        $this->book = $book;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function book(): ?AccountingBook
    {
        return $this->book;
    }

    public function companyId(): int
    {
        if (! $this->company) {
            throw new RuntimeException('Company context is not set.');
        }

        return (int) $this->company->id;
    }

    public function bookId(): int
    {
        if (! $this->book) {
            throw new RuntimeException('Book context is not set.');
        }

        return (int) $this->book->id;
    }

    public function clear(): void
    {
        $this->user = null;
        $this->company = null;
        $this->book = null;
    }
}
