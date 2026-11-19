<?php

namespace App\Application\Services;

use App\Models\CompanySetting;
use App\Models\ErpConnection;
use App\Models\ErpIdMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Scoped classic Balances writes for Cash & Bank desks (dual-run).
 * Updates/creates financial_accounts fields needed by the banking form.
 */
final class BankingErpWalletWriter
{
    /**
     * @return array{path:?string,changed:bool}
     */
    public function resolveLogoPath(
        ?string $institutionSlug,
        ?string $logoBase64,
        ?string $logoFileName,
        bool $clearLogo
    ): array {
        if ($clearLogo) {
            return ['path' => '', 'changed' => true];
        }

        $uploaded = $this->storeBase64Logo($logoBase64, $logoFileName);
        if ($uploaded !== null) {
            return ['path' => $uploaded, 'changed' => true];
        }

        $slug = trim((string) $institutionSlug);
        if ($slug !== '') {
            $copied = $this->copyInstitutionLogo($slug);
            if ($copied !== '') {
                return ['path' => $copied, 'changed' => true];
            }
        }

        return ['path' => null, 'changed' => false];
    }

    /**
     * @param  array{name?:string,status?:string,account_image?:string|null,currency?:string,type?:string}  $fields
     */
    public function syncMappedWallet(int $companyId, string $entityType, int $localId, array $fields): void
    {
        try {
            $map = ErpIdMap::query()
                ->where('company_id', $companyId)
                ->where('entity_type', $entityType)
                ->where('local_id', $localId)
                ->first();
            if (! $map) {
                return;
            }

            $this->bindErpDatabase($companyId);
            if (! Schema::connection('erp_ultimate')->hasTable('financial_accounts')) {
                return;
            }

            $erpId = (int) $map->erp_id;
            $row = DB::connection('erp_ultimate')->table('financial_accounts')->where('id', $erpId)->first();
            if (! $row) {
                return;
            }

            $update = [];
            if (array_key_exists('name', $fields)) {
                $label = trim((string) $fields['name']);
                $classicCode = $this->classicCodeFromName((string) ($row->name ?? ''), (string) ($fields['fallback_code'] ?? ''));
                $update['name'] = $classicCode !== '' ? ($classicCode.' - '.$label) : $label;
            }
            if (array_key_exists('status', $fields) && in_array($fields['status'], ['active', 'inactive'], true)) {
                $update['status'] = $fields['status'];
            }
            if (array_key_exists('currency', $fields) && is_string($fields['currency']) && strlen($fields['currency']) === 3) {
                $update['currency'] = strtoupper($fields['currency']);
            }
            if (array_key_exists('type', $fields) && in_array($fields['type'], ['bank', 'cash', 'mobile'], true)) {
                $update['type'] = $fields['type'];
            }
            if (array_key_exists('account_image', $fields) && Schema::connection('erp_ultimate')->hasColumn('financial_accounts', 'account_image')) {
                $img = $fields['account_image'];
                $update['account_image'] = ($img === null || $img === '') ? null : (string) $img;
            }

            if ($update === []) {
                return;
            }

            DB::connection('erp_ultimate')->table('financial_accounts')
                ->where('id', $erpId)
                ->update($update);
        } catch (\Throwable $e) {
            Log::warning('ERP wallet sync failed: '.$e->getMessage());
        }
    }

    /**
     * Create a classic Balances wallet and return its id, or null on failure.
     *
     * @param  array{
     *   name:string,
     *   type:string,
     *   currency:string,
     *   status?:string,
     *   opening_balance?:float,
     *   opening_date?:?string,
     *   account_image?:?string,
     *   company_id?:?int
     * }  $payload
     */
    public function createClassicWallet(int $companyId, array $payload): ?int
    {
        try {
            $this->bindErpDatabase($companyId);
            if (! Schema::connection('erp_ultimate')->hasTable('financial_accounts')) {
                return null;
            }

            $type = in_array($payload['type'], ['bank', 'cash', 'mobile'], true) ? $payload['type'] : 'bank';
            $label = trim((string) $payload['name']);
            if ($label === '') {
                return null;
            }

            $code = $this->nextClassicCode($type);
            $name = $code.' - '.$label;
            $opening = round((float) ($payload['opening_balance'] ?? 0), 2);
            $openingDate = trim((string) ($payload['opening_date'] ?? ''));
            if ($openingDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $openingDate)) {
                $openingDate = '';
            }
            if (abs($opening) >= 0.005 && $openingDate === '') {
                $openingDate = date('Y-m-d');
            }

            $row = [
                'name' => $name,
                'type' => $type,
                'currency' => strtoupper(substr((string) ($payload['currency'] ?? 'TZS'), 0, 3)),
                'opening_balance' => $opening,
                'current_balance' => $opening,
                'status' => in_array(($payload['status'] ?? 'active'), ['active', 'inactive'], true)
                    ? $payload['status']
                    : 'active',
            ];

            if (Schema::connection('erp_ultimate')->hasColumn('financial_accounts', 'opening_date')) {
                $row['opening_date'] = abs($opening) >= 0.005 ? $openingDate : null;
            }
            if (
                Schema::connection('erp_ultimate')->hasColumn('financial_accounts', 'account_image')
                && ! empty($payload['account_image'])
            ) {
                $row['account_image'] = (string) $payload['account_image'];
            }
            if (Schema::connection('erp_ultimate')->hasColumn('financial_accounts', 'parent_id')) {
                $parentId = $this->resolveParentId($type);
                if ($parentId !== null) {
                    $row['parent_id'] = $parentId;
                }
            }
            if (Schema::connection('erp_ultimate')->hasColumn('financial_accounts', 'company_id')) {
                $erpCompanyId = (int) ($payload['company_id'] ?? 0);
                if ($erpCompanyId > 0) {
                    $row['company_id'] = $erpCompanyId;
                }
            }

            return (int) DB::connection('erp_ultimate')->table('financial_accounts')->insertGetId($row);
        } catch (\Throwable $e) {
            Log::warning('ERP wallet create failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Persist a typed bank/mobile name into balances_custom_institutions so it
     * reappears in Select Bank (classic + UltiTech boot institutions).
     */
    public function rememberCustomInstitution(
        int $companyId,
        string $name,
        string $type,
        ?string $logoPath = null,
        ?int $erpCompanyId = null
    ): void {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $type = in_array($type, ['bank', 'mobile', 'cash'], true) ? $type : 'bank';
        if ($name === '') {
            return;
        }

        try {
            $this->bindErpDatabase($companyId);
            if (! Schema::connection('erp_ultimate')->hasTable('balances_custom_institutions')) {
                // Create the classic table if the ERP DB is shared and the helper never ran.
                DB::connection('erp_ultimate')->statement(
                    'CREATE TABLE IF NOT EXISTS balances_custom_institutions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        company_id INT NULL DEFAULT NULL,
                        name VARCHAR(100) NOT NULL,
                        type VARCHAR(20) NOT NULL,
                        logo VARCHAR(500) NOT NULL,
                        created_by INT NULL DEFAULT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_bci_company_name (company_id, name)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            }

            $companyKey = $erpCompanyId && $erpCompanyId > 0 ? $erpCompanyId : null;
            $logo = trim((string) $logoPath);

            $existing = DB::connection('erp_ultimate')
                ->table('balances_custom_institutions')
                ->where(function ($q) use ($companyKey) {
                    if ($companyKey === null) {
                        $q->whereNull('company_id');
                    } else {
                        $q->where('company_id', $companyKey);
                    }
                })
                ->where('name', $name)
                ->first();

            if ($existing) {
                $update = ['type' => $type];
                if ($logo !== '') {
                    $update['logo'] = $logo;
                }
                DB::connection('erp_ultimate')
                    ->table('balances_custom_institutions')
                    ->where('id', (int) $existing->id)
                    ->update($update);

                return;
            }

            DB::connection('erp_ultimate')->table('balances_custom_institutions')->insert([
                'company_id' => $companyKey,
                'name' => $name,
                'type' => $type,
                'logo' => $logo,
                'created_by' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Remember custom institution failed: '.$e->getMessage());
        }
    }

    public function mapLocalWallet(
        int $companyId,
        string $entityType,
        int $erpId,
        int $localId,
        array $meta = []
    ): void {
        $connection = ErpConnection::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
        if (! $connection) {
            return;
        }

        ErpIdMap::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'entity_type' => $entityType,
                'erp_id' => (string) $erpId,
            ],
            [
                'company_id' => $companyId,
                'local_id' => $localId,
                'meta_json' => $meta ?: null,
            ]
        );
    }

    private function bindErpDatabase(int $companyId): void
    {
        $settings = CompanySetting::query()->where('company_id', $companyId)->first();
        $json = is_array($settings?->settings_json) ? $settings->settings_json : [];
        $erpDb = trim((string) data_get($json, 'erp_bridge.erp_db', ''));
        if ($erpDb === '') {
            $connection = ErpConnection::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->first();
            $erpDb = trim((string) ($connection?->erp_database ?: config('database.connections.erp_ultimate.database')));
        }
        if ($erpDb !== '') {
            config(['database.connections.erp_ultimate.database' => $erpDb]);
            DB::purge('erp_ultimate');
        }
    }

    private function erpPublicRoot(): string
    {
        $configured = trim((string) config('erp.public_root', env('ERP_PUBLIC_ROOT', '')));
        if ($configured !== '' && is_dir($configured)) {
            return rtrim(str_replace('\\', '/', $configured), '/');
        }

        return rtrim(str_replace('\\', '/', dirname(base_path(), 2)), '/');
    }

    private function copyInstitutionLogo(string $slug): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $slug) ?? '';
        if ($slug === '') {
            return '';
        }

        $root = $this->erpPublicRoot();
        $candidates = [
            $root.'/assets/balances/institutions/'.$slug.'.png',
            $root.'/assets/balances/institutions/'.$slug.'.jpg',
            $root.'/assets/balances/institutions/'.$slug.'.webp',
        ];

        // Custom institutions may store absolute-ish relative logo paths via slug lookup file — skip DB here.
        $source = '';
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $source = $path;
                break;
            }
        }
        if ($source === '') {
            return '';
        }

        $targetDir = $root.'/uploads/balances/accounts';
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            return '';
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION) ?: 'png');
        $fileName = 'ACC_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        if (! @copy($source, $targetDir.'/'.$fileName)) {
            return '';
        }

        return 'uploads/balances/accounts/'.$fileName;
    }

    private function storeBase64Logo(?string $logoBase64, ?string $logoFileName): ?string
    {
        $raw = trim((string) $logoBase64);
        if ($raw === '') {
            return null;
        }

        if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $raw, $m)) {
            $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
            $raw = substr($raw, strpos($raw, ',') + 1);
        } else {
            $ext = strtolower(pathinfo((string) $logoFileName, PATHINFO_EXTENSION) ?: 'png');
            if (! in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                $ext = 'png';
            }
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
        }

        $binary = base64_decode($raw, true);
        if ($binary === false || strlen($binary) < 24 || strlen($binary) > 2 * 1024 * 1024) {
            throw new \InvalidArgumentException('Account image must be a valid image up to 2MB.');
        }
        if (@getimagesizefromstring($binary) === false) {
            throw new \InvalidArgumentException('Account image must be a JPG, PNG, WEBP, or GIF picture.');
        }

        $root = $this->erpPublicRoot();
        $targetDir = $root.'/uploads/balances/accounts';
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            throw new \RuntimeException('Could not create upload folder for account image.');
        }

        $fileName = 'ACC_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        if (file_put_contents($targetDir.'/'.$fileName, $binary) === false) {
            throw new \RuntimeException('Could not save account image.');
        }

        return 'uploads/balances/accounts/'.$fileName;
    }

    private function nextClassicCode(string $type): string
    {
        $rows = DB::connection('erp_ultimate')
            ->table('financial_accounts')
            ->whereIn('type', ['bank', 'cash', 'mobile', 'asset'])
            ->pluck('name');

        $max = 1099;
        foreach ($rows as $name) {
            if (preg_match('/^(\d{3,4})\s*[-–:]/u', (string) $name, $m)) {
                $n = (int) $m[1];
                if ($n >= 1000 && $n < 2000) {
                    $max = max($max, $n);
                }
            }
        }

        return (string) ($max + 1);
    }

    private function resolveParentId(string $type): ?int
    {
        $walletType = $type === 'mobile' ? 'mobile' : $type;
        $sibling = DB::connection('erp_ultimate')
            ->table('financial_accounts')
            ->where('type', $walletType)
            ->whereNotNull('parent_id')
            ->where('parent_id', '>', 0)
            ->orderByDesc('id')
            ->value('parent_id');
        if ($sibling) {
            return (int) $sibling;
        }

        $assets = DB::connection('erp_ultimate')
            ->table('financial_accounts')
            ->where(function ($q) {
                $q->where('name', 'like', '1000%Assets%')
                    ->orWhere('name', '1000 - Assets');
            })
            ->where(function ($q) {
                $q->whereNull('parent_id')->orWhere('parent_id', 0);
            })
            ->value('id');

        return $assets ? (int) $assets : null;
    }

    private function classicCodeFromName(string $existingName, string $fallbackCode): string
    {
        if (preg_match('/^(\d+)\s*[-–:]\s*(.+)$/u', trim($existingName), $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^FA-(\d+)$/i', $fallbackCode, $m)) {
            return $m[1];
        }

        return trim($fallbackCode);
    }
}
