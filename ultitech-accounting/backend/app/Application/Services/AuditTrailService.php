<?php

namespace App\Application\Services;

use App\Models\AuditLog;
use App\Support\TenantContext;

class AuditTrailService
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function record(
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        ?array $before = null,
        ?array $after = null
    ): AuditLog {
        return AuditLog::query()->create([
            'company_id' => $this->tenant->company()?->id,
            'user_id' => $this->tenant->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
