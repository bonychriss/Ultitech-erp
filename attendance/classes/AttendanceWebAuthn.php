<?php
/**
 * WebAuthn (platform biometric) helpers for attendance clock-in/out.
 */
declare(strict_types=1);

class AttendanceWebAuthn
{
    public const MAX_CREDENTIALS_PER_USER = 2;
    public const SESSION_CHALLENGE_KEY = 'att_webauthn_challenge';
    public const SESSION_CHALLENGE_USER_KEY = 'att_webauthn_challenge_user';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureAutoload();
    }

    private function ensureAutoload(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $srcDir = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'lbuchs'
            . DIRECTORY_SEPARATOR . 'webauthn' . DIRECTORY_SEPARATOR . 'src';
        $webAuthnFile = $srcDir . DIRECTORY_SEPARATOR . 'WebAuthn.php';

        // Load with absolute paths only (avoid Composer PSR-4 + CWD-relative requires on live).
        if (is_file($webAuthnFile) && !class_exists(\lbuchs\WebAuthn\WebAuthn::class, false)) {
            $deps = [
                'WebAuthnException.php',
                'Binary' . DIRECTORY_SEPARATOR . 'ByteBuffer.php',
                'CBOR' . DIRECTORY_SEPARATOR . 'CborDecoder.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'Format' . DIRECTORY_SEPARATOR . 'FormatBase.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'Format' . DIRECTORY_SEPARATOR . 'None.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'Format' . DIRECTORY_SEPARATOR . 'AndroidKey.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'Format' . DIRECTORY_SEPARATOR . 'AndroidSafetyNet.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'Format' . DIRECTORY_SEPARATOR . 'Apple.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'Format' . DIRECTORY_SEPARATOR . 'Packed.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'Format' . DIRECTORY_SEPARATOR . 'Tpm.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'Format' . DIRECTORY_SEPARATOR . 'U2f.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'AuthenticatorData.php',
                'Attestation' . DIRECTORY_SEPARATOR . 'AttestationObject.php',
                'WebAuthn.php',
            ];
            foreach ($deps as $rel) {
                $abs = $srcDir . DIRECTORY_SEPARATOR . $rel;
                if (is_file($abs)) {
                    require_once $abs;
                }
            }
        }
        $loaded = true;
    }

    public function isLibraryAvailable(): bool
    {
        $this->ensureAutoload();
        // Never trigger Composer PSR-4 autoload (its relative requires break on live).
        return class_exists(\lbuchs\WebAuthn\WebAuthn::class, false);
    }

    public function isSecureContextHint(): bool
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $hostNoPort = preg_replace('/:\d+$/', '', $host) ?: $host;
        return $https || $hostNoPort === 'localhost' || $hostNoPort === '127.0.0.1';
    }

    public function resolveRpId(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
        $host = strtolower(trim($host));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            // WebAuthn rpId must be a registrable domain; localhost is allowed as special case.
            if ($host === '127.0.0.1' || $host === '::1') {
                return 'localhost';
            }
            return $host !== '' ? $host : 'localhost';
        }
        return $host;
    }

    public function isRpIdUsable(): bool
    {
        $rpId = $this->resolveRpId();
        if ($rpId === 'localhost') {
            return true;
        }
        // Raw IPs are not valid WebAuthn relying party IDs on iPhone/Safari.
        return filter_var($rpId, FILTER_VALIDATE_IP) === false;
    }

    private function makeWebAuthn(): \lbuchs\WebAuthn\WebAuthn
    {
        if (!$this->isLibraryAvailable()) {
            throw new RuntimeException('WebAuthn library is not installed.');
        }
        return new \lbuchs\WebAuthn\WebAuthn(
            'Ultitech Attendance',
            $this->resolveRpId(),
            ['none'],
            true
        );
    }

    public function isBiometricRequired(): bool
    {
        try {
            if (function_exists('columnExists') && !columnExists('attendance_settings', 'biometric_required', $this->pdo)) {
                return true;
            }
            $stmt = $this->pdo->query('SELECT biometric_required FROM attendance_settings WHERE id = 1 LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!$row) {
                return true;
            }
            return (int) ($row['biometric_required'] ?? 1) === 1;
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * @return list<array{id:int,device_label:?string,created_at:?string,last_used_at:?string}>
     */
    public function listCredentials(int $userId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, device_label, created_at, last_used_at
                 FROM attendance_webauthn_credentials
                 WHERE user_id = ?
                 ORDER BY id ASC'
            );
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'device_label' => isset($row['device_label']) ? (string) $row['device_label'] : null,
                    'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                    'last_used_at' => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
                ];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('AttendanceWebAuthn::listCredentials: ' . $e->getMessage());
            return [];
        }
    }

    public function credentialCount(int $userId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM attendance_webauthn_credentials WHERE user_id = ?'
            );
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{required:bool,enrolled:bool,count:int,devices:list,library_ok:bool,secure_ok:bool,rp_id:string}
     */
    public function statusForUser(int $userId): array
    {
        $devices = $this->listCredentials($userId);
        return [
            'required' => $this->isBiometricRequired(),
            'enrolled' => $devices !== [],
            'count' => count($devices),
            'max' => self::MAX_CREDENTIALS_PER_USER,
            'devices' => $devices,
            'library_ok' => $this->isLibraryAvailable(),
            'secure_ok' => $this->isSecureContextHint(),
            'rp_id' => $this->resolveRpId(),
        ];
    }

    private function userHandle(int $userId): string
    {
        return 'uid:' . $userId;
    }

    private function normalizeMode(string $mode): string
    {
        return strtolower(trim($mode)) === 'passkey' ? 'passkey' : 'platform';
    }

    private function storeChallenge(
        int $userId,
        \lbuchs\WebAuthn\Binary\ByteBuffer $challenge,
        string $mode = 'platform'
    ): void {
        $mode = $this->normalizeMode($mode);
        $bag = $_SESSION[self::SESSION_CHALLENGE_KEY] ?? null;
        if (!is_array($bag)) {
            $bag = (is_string($bag) && $bag !== '') ? ['platform' => $bag] : [];
        }
        $bag[$mode] = $challenge->getBinaryString();
        $_SESSION[self::SESSION_CHALLENGE_KEY] = $bag;
        $_SESSION[self::SESSION_CHALLENGE_USER_KEY] = $userId;
    }

    private function takeChallenge(int $userId, string $mode = 'platform'): string
    {
        $mode = $this->normalizeMode($mode);
        $bag = $_SESSION[self::SESSION_CHALLENGE_KEY] ?? null;
        $challengeUser = (int) ($_SESSION[self::SESSION_CHALLENGE_USER_KEY] ?? 0);
        $challenge = null;
        if (is_array($bag)) {
            $challenge = $bag[$mode] ?? ($bag['platform'] ?? null);
            unset($bag[$mode]);
            if ($bag === []) {
                unset($_SESSION[self::SESSION_CHALLENGE_KEY], $_SESSION[self::SESSION_CHALLENGE_USER_KEY]);
            } else {
                $_SESSION[self::SESSION_CHALLENGE_KEY] = $bag;
            }
        } elseif (is_string($bag) && $bag !== '') {
            $challenge = $bag;
            unset($_SESSION[self::SESSION_CHALLENGE_KEY], $_SESSION[self::SESSION_CHALLENGE_USER_KEY]);
        }
        if (!is_string($challenge) || $challenge === '' || $challengeUser !== $userId) {
            throw new RuntimeException('Biometric challenge expired. Please try again.');
        }
        return $challenge;
    }

    /**
     * @param string $mode platform = Face ID / device unlock; passkey = Google / cross-platform passkey
     * @return array{success:bool,message?:string,publicKey?:object,mode?:string}
     */
    public function createRegisterOptions(
        int $userId,
        string $userName,
        string $displayName,
        string $mode = 'platform'
    ): array {
        if (!$this->isLibraryAvailable()) {
            return ['success' => false, 'message' => 'Biometric library is not available on the server.'];
        }
        if (!$this->isSecureContextHint()) {
            return [
                'success' => false,
                'message' => 'Passkeys require HTTPS. Open your company HTTPS URL (not plain http).',
            ];
        }
        if (!$this->isRpIdUsable()) {
            return [
                'success' => false,
                'message' => 'Biometrics cannot run on a raw IP address. Open the site with your company domain over HTTPS.',
            ];
        }
        if ($this->credentialCount($userId) >= self::MAX_CREDENTIALS_PER_USER) {
            return [
                'success' => false,
                'message' => 'This account already has ' . self::MAX_CREDENTIALS_PER_USER
                    . ' enrolled phones. Remove one before adding another.',
            ];
        }

        $mode = $this->normalizeMode($mode);

        try {
            $webAuthn = $this->makeWebAuthn();
            $excludeIds = [];
            $stmt = $this->pdo->prepare(
                'SELECT credential_id FROM attendance_webauthn_credentials WHERE user_id = ?'
            );
            $stmt->execute([$userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $excludeIds[] = $row['credential_id'];
            }

            // platform = Face ID only; passkey = any passkey provider (Google / iCloud / etc.)
            // null attachment = do NOT force security-key / QR hybrid — let the browser offer Google Password Manager.
            $crossPlatform = $mode === 'passkey' ? null : false;
            $residentKey = $mode === 'passkey' ? 'preferred' : 'discouraged';

            $createArgs = $webAuthn->getCreateArgs(
                $this->userHandle($userId),
                $userName !== '' ? $userName : ('user' . $userId),
                $displayName !== '' ? $displayName : ('User ' . $userId),
                120,
                $residentKey,
                'preferred',
                $crossPlatform,
                $excludeIds
            );
            // Avoid non-standard extension bags that Safari may reject.
            if (isset($createArgs->publicKey->extensions)) {
                unset($createArgs->publicKey->extensions);
            }
            if ($mode === 'passkey' && isset($createArgs->publicKey->authenticatorSelection->authenticatorAttachment)) {
                unset($createArgs->publicKey->authenticatorSelection->authenticatorAttachment);
            }
            $this->storeChallenge($userId, $webAuthn->getChallenge(), $mode);

            return [
                'success' => true,
                'mode' => $mode,
                'publicKey' => $createArgs->publicKey,
            ];
        } catch (Throwable $e) {
            error_log('AttendanceWebAuthn::createRegisterOptions: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not start biometric enrollment.'];
        }
    }

    /**
     * @param array<string,mixed> $client
     * @return array{success:bool,message:string,data?:array}
     */
    public function finishRegister(
        int $userId,
        array $client,
        ?string $deviceLabel = null,
        string $mode = 'platform'
    ): array {
        if (!$this->isLibraryAvailable()) {
            return ['success' => false, 'message' => 'Biometric library is not available on the server.'];
        }
        if ($this->credentialCount($userId) >= self::MAX_CREDENTIALS_PER_USER) {
            return [
                'success' => false,
                'message' => 'Enrollment limit reached. Remove a phone first.',
            ];
        }

        try {
            $challenge = $this->takeChallenge($userId, $mode);
            $clientDataJSON = $this->decodeBinaryField($client['clientDataJSON'] ?? null);
            $attestationObject = $this->decodeBinaryField($client['attestationObject'] ?? null);
            if ($clientDataJSON === '' || $attestationObject === '') {
                return ['success' => false, 'message' => 'Invalid biometric enrollment payload.'];
            }

            $webAuthn = $this->makeWebAuthn();
            // UV preferred (not required): Google/iCloud passkeys often omit the UV flag.
            $data = $webAuthn->processCreate(
                $clientDataJSON,
                $attestationObject,
                $challenge,
                false,
                true,
                false
            );

            $label = trim((string) ($deviceLabel ?? ''));
            if ($label === '') {
                $label = 'Smartphone';
            }
            $label = substr($label, 0, 120);
            $aaguid = null;
            if (isset($data->AAGUID) && is_string($data->AAGUID) && $data->AAGUID !== '') {
                $aaguid = bin2hex($data->AAGUID);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO attendance_webauthn_credentials
                    (user_id, credential_id, public_key, sign_count, device_label, aaguid, last_used_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL)'
            );
            $stmt->execute([
                $userId,
                $data->credentialId,
                $data->credentialPublicKey,
                (int) ($data->signatureCounter ?? 0),
                $label,
                $aaguid,
            ]);

            return [
                'success' => true,
                'message' => 'Phone unlock enrolled successfully.',
                'data' => ['biometric' => $this->statusForUser($userId)],
            ];
        } catch (Throwable $e) {
            error_log('AttendanceWebAuthn::finishRegister: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Phone unlock enrollment failed: ' . $e->getMessage()];
        }
    }

    /**
     * @return array{success:bool,message?:string,publicKey?:object}
     */
    public function createAssertOptions(int $userId): array
    {
        if (!$this->isLibraryAvailable()) {
            return ['success' => false, 'message' => 'Biometric library is not available on the server.'];
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT credential_id FROM attendance_webauthn_credentials WHERE user_id = ?'
            );
            $stmt->execute([$userId]);
            $ids = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ids[] = $row['credential_id'];
            }
            if ($ids === []) {
                return [
                    'success' => false,
                    'message' => 'No biometric enrolled on this account. Enroll this phone first.',
                ];
            }

            $webAuthn = $this->makeWebAuthn();
            // Allow Face ID (internal) and Google / hybrid / security-key passkeys.
            $getArgs = $webAuthn->getGetArgs($ids, 120, true, true, true, true, true, 'preferred');
            if (isset($getArgs->publicKey->extensions)) {
                unset($getArgs->publicKey->extensions);
            }
            $this->storeChallenge($userId, $webAuthn->getChallenge());

            return [
                'success' => true,
                'publicKey' => $getArgs->publicKey,
            ];
        } catch (Throwable $e) {
            error_log('AttendanceWebAuthn::createAssertOptions: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not start biometric verification.'];
        }
    }

    /**
     * @param array<string,mixed>|null $assertion
     * @return array{success:bool,message:string}
     */
    public function verifyAssertion(int $userId, ?array $assertion): array
    {
        if (!$this->isBiometricRequired()) {
            return ['success' => true, 'message' => 'Biometric not required.'];
        }
        if (!$this->isLibraryAvailable()) {
            return ['success' => false, 'message' => 'Biometric library is not available on the server.'];
        }
        if ($this->credentialCount($userId) === 0) {
            return [
                'success' => false,
                'message' => 'Enroll your phone biometric before clocking in or out.',
            ];
        }
        if (!is_array($assertion) || $assertion === []) {
            return [
                'success' => false,
                'message' => 'Biometric verification is required. Use your enrolled smartphone.',
            ];
        }

        try {
            $challenge = $this->takeChallenge($userId);
            $credentialId = $this->decodeBinaryField($assertion['id'] ?? ($assertion['rawId'] ?? null));
            $clientDataJSON = $this->decodeBinaryField($assertion['clientDataJSON'] ?? null);
            $authenticatorData = $this->decodeBinaryField($assertion['authenticatorData'] ?? null);
            $signature = $this->decodeBinaryField($assertion['signature'] ?? null);

            if ($credentialId === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
                return ['success' => false, 'message' => 'Invalid biometric assertion.'];
            }

            $stmt = $this->pdo->prepare(
                'SELECT id, public_key, sign_count
                 FROM attendance_webauthn_credentials
                 WHERE user_id = ? AND credential_id = ?
                 LIMIT 1'
            );
            $stmt->execute([$userId, $credentialId]);
            $cred = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cred) {
                return [
                    'success' => false,
                    'message' => 'This phone is not enrolled for your account.',
                ];
            }

            $webAuthn = $this->makeWebAuthn();
            $prevCount = (int) ($cred['sign_count'] ?? 0);
            // Many phone platform authenticators keep signCount at 0; only enforce when non-zero.
            // UV preferred (not required): match create options; passkeys may omit UV.
            $webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                (string) $cred['public_key'],
                $challenge,
                $prevCount > 0 ? $prevCount : null,
                false,
                true
            );

            $newCount = null;
            try {
                $authData = new \lbuchs\WebAuthn\Attestation\AuthenticatorData($authenticatorData);
                $newCount = (int) $authData->getSignCount();
            } catch (Throwable $e) {
                $newCount = null;
            }

            if ($newCount !== null && $newCount > 0) {
                $upd = $this->pdo->prepare(
                    'UPDATE attendance_webauthn_credentials
                     SET sign_count = ?, last_used_at = NOW()
                     WHERE id = ? AND user_id = ?'
                );
                $upd->execute([$newCount, (int) $cred['id'], $userId]);
            } else {
                $upd = $this->pdo->prepare(
                    'UPDATE attendance_webauthn_credentials
                     SET last_used_at = NOW()
                     WHERE id = ? AND user_id = ?'
                );
                $upd->execute([(int) $cred['id'], $userId]);
            }

            return ['success' => true, 'message' => 'Biometric verified.'];
        } catch (Throwable $e) {
            error_log('AttendanceWebAuthn::verifyAssertion: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Biometric verification failed. Use your enrolled smartphone and try again.',
            ];
        }
    }

    /**
     * @return array{success:bool,message:string,data?:array}
     */
    public function removeCredential(int $userId, int $credentialRowId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM attendance_webauthn_credentials WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([$credentialRowId, $userId]);
            if ($stmt->rowCount() < 1) {
                return ['success' => false, 'message' => 'Device not found.'];
            }
            return [
                'success' => true,
                'message' => 'Enrolled phone removed.',
                'data' => ['biometric' => $this->statusForUser($userId)],
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not remove enrolled phone.'];
        }
    }

    /**
     * Clear all enrollments for this user (e.g. after Face ID / screen replacement).
     *
     * @return array{success:bool,message:string,data?:array}
     */
    public function resetCredentials(int $userId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM attendance_webauthn_credentials WHERE user_id = ?'
            );
            $stmt->execute([$userId]);
            return [
                'success' => true,
                'message' => 'Old phone unlock cleared. Enroll this phone again with Face ID or passcode.',
                'data' => ['biometric' => $this->statusForUser($userId)],
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not clear enrolled phones.'];
        }
    }

    /**
     * @param mixed $value
     */
    private function decodeBinaryField($value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        // Prefer base64url, fall back to standard base64.
        $b64 = strtr($value, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $bin = base64_decode($b64, true);
        return $bin === false ? '' : $bin;
    }
}
