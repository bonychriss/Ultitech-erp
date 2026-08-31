<?php
/**
 * Mail sync — personal IMAP and/or remote cPanel bridges.
 */
header('Content-Type: application/json');
error_reporting(0);

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/includes/email_bootstrap.php';
    require_once __DIR__ . '/includes/email_remote_bridges.php';

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired.']);
        exit;
    }

    $user_id = (int) $_SESSION['user_id'];
    $emailDb = function_exists('email_module_pdo') ? email_module_pdo() : null;
    if (!($emailDb instanceof PDO)) {
        $emailDb = $pdo;
    }
    if (!($emailDb instanceof PDO)) {
        echo json_encode(['status' => 'error', 'message' => 'Email database unavailable.']);
        exit;
    }

    ensure_email_module_schema($emailDb);

    $settings = null;
    try {
        $stmt = $emailDb->prepare('SELECT * FROM module_email_user_settings WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $settings = null;
    }

    $quick = isset($_GET['quick'])
        ? (($_GET['quick'] === '1' || $_GET['quick'] === 'true'))
        : false;
    if (!$quick && isset($_POST['quick'])) {
        $quick = ($_POST['quick'] === '1' || $_POST['quick'] === 'true');
    }

    $hasPersonalImap = !empty($settings['imap_host']) && !empty($settings['imap_user']);
    $hasBridges = email_has_enabled_remote_bridges();
    $hasLocalPackage = function_exists('email_local_bridge_imap_config')
        && (email_local_bridge_imap_config('ultimate') || email_local_bridge_imap_config('roadmaster'));

    if (!$hasPersonalImap && !$hasBridges && !$hasLocalPackage) {
        echo json_encode([
            'status' => 'error',
            'message' => 'No mail source configured. Enable a Remote Bridge in Email Settings, or add personal IMAP in My Account.',
        ]);
        exit;
    }

    @ $emailDb->exec("SET time_zone = '+00:00'");
    set_time_limit($quick ? 45 : 180);
    $count = 0;
    $imapError = null;

    // Auto-sync skips personal IMAP (slow). Manual Sync still runs incremental IMAP + bridges.
    if (!$quick && $hasPersonalImap && function_exists('imap_open')) {
        $imap_user = $settings['imap_user'];
        $imap_pass = $settings['imap_pass'];
        $mbox_path = '{' . $settings['imap_host'] . ':' . ($settings['imap_port'] ?: '993') . '/imap/' . ($settings['imap_ssl'] ?: 'ssl') . '/novalidate-cert}INBOX';
        $mbox = @imap_open($mbox_path, $imap_user, $imap_pass);

        if (!$mbox) {
            if (function_exists('imap_errors')) {
                @imap_errors();
                @imap_alerts();
            }
            $imapError = 'IMAP Connection Fail: ' . imap_last_error();
        } else {
            $criteria = 'ALL';
            $imapSince = email_bridge_incremental_since($emailDb, 48);
            if ($imapSince) {
                $ts = strtotime($imapSince);
                if ($ts) {
                    $criteria = 'SINCE "' . date('d-M-Y', $ts) . '"';
                }
            }
            $msgNos = @imap_search($mbox, $criteria);
            if (!$msgNos && $criteria !== 'ALL') {
                $msgNos = @imap_search($mbox, 'UNSEEN');
            }
            if ($msgNos) {
                rsort($msgNos);
                $msgNos = array_slice($msgNos, 0, 120);
                $overviewList = @imap_fetch_overview($mbox, implode(',', $msgNos), 0);
                if (!is_array($overviewList)) {
                    $overviewList = array();
                }

                $msgIds = array();
                foreach ($overviewList as $ov) {
                    $mid = trim((string) ($ov->message_id ?? ''));
                    if ($mid !== '') {
                        $msgIds[] = $mid;
                    }
                }
                $existingIds = email_existing_message_id_set($emailDb, $msgIds);
                $importedThisRun = 0;

                foreach ($overviewList as $ov) {
                    if ($importedThisRun >= 50) {
                        break;
                    }
                    $msg_id = trim((string) ($ov->message_id ?? ''));
                    if ($msg_id !== '' && isset($existingIds[$msg_id])) {
                        continue;
                    }

                    $m = (int) ($ov->msgno ?? 0);
                    if ($m < 1) {
                        continue;
                    }

                    $db_date = isset($ov->udate)
                        ? gmdate('Y-m-d H:i:s', (int) $ov->udate)
                        : gmdate('Y-m-d H:i:s', strtotime((string) ($ov->date ?? 'now')));
                    $subject = trim(imap_utf8((string) ($ov->subject ?? '')));
                    $sender = trim(imap_utf8((string) ($ov->from ?? '')));

                    if ($msg_id === '') {
                        $check = $emailDb->prepare('SELECT id FROM module_emails WHERE user_id = ? AND sender_email = ? AND subject = ? AND created_at = ? LIMIT 1');
                        $check->execute([$user_id, $sender, $subject, $db_date]);
                        if ($check->fetch()) {
                            continue;
                        }
                    }

                    list(, $body) = email_build_body_from_imap_message($mbox, $m);
                    if ($body === '') {
                        $body = (string) imap_body($mbox, $m);
                    }

                    $status = email_is_sender_blocked($sender, $emailDb) ? 'spam' : 'unread';
                    $storeUserId = (function_exists('email_is_company_mailbox') && email_is_company_mailbox((string) $imap_user))
                        ? 0
                        : $user_id;
                    $ins = $emailDb->prepare("INSERT INTO module_emails (user_id, sender_email, recipient_email, subject, body, direction, status, created_at, message_id) VALUES (?, ?, ?, ?, ?, 'inbound', ?, ?, ?)");
                    $ins->execute([$storeUserId, $sender, $imap_user, $subject, $body, $status, $db_date, $msg_id !== '' ? $msg_id : null]);
                    $new_id = $emailDb->lastInsertId();
                    if ($status !== 'spam') {
                        email_imap_download_attachments($mbox, $m, $new_id, $emailDb);
                    }
                    if ($msg_id !== '') {
                        $existingIds[$msg_id] = true;
                    }
                    $importedThisRun++;
                    $count++;
                }
            }
            imap_close($mbox);
        }
    }

    $bridgeNew = 0;
    $bridgeErrors = array();
    if ($hasBridges) {
        $bridgeLimit = $quick ? 40 : 80;
        $bridgeTimeout = $quick ? 12 : 25;
        $bridgeResult = email_sync_remote_bridges($emailDb, $bridgeLimit, 0, $bridgeTimeout);
        $bridgeNew = (int) ($bridgeResult['new_count'] ?? 0);
        $bridgeErrors = $bridgeResult['errors'] ?? array();
        $count += $bridgeNew;
    }

    $preferred = function_exists('email_resolve_preferred_bridge') ? email_resolve_preferred_bridge() : 'ultimate';
    $preferredBridgeFailed = ($preferred !== '' && !empty($bridgeErrors[$preferred]));
    // Only attempt IMAP on full (non-quick) sync, or when bridge failed AND TCP is likely usable.
    // Quick/silent sync must stay HTTP-only so live UI never blanks on firewalled IMAP.
    $runImapFallback = !$quick && ($preferredBridgeFailed || $bridgeNew === 0);

    if ($runImapFallback && function_exists('email_sync_local_package_inbox')) {
        try {
            $localLimit = $quick ? 20 : 40;
            $localSync = email_sync_local_package_inbox($emailDb, $localLimit, 0);
            $count += (int) ($localSync['new_count'] ?? 0);
        } catch (Throwable $e) {
        }
    }

    // Company IMAP from admin Email settings (shared mailbox, user_id=0).
    if ($runImapFallback && function_exists('email_has_company_imap') && email_has_company_imap() && function_exists('imap_open')) {
        try {
            if (session_status() !== PHP_SESSION_NONE) {
                unset($_SESSION['imap_unreachable']);
            }
            $companySettings = email_get_imap_settings();
            $mbox = email_open_imap_mailbox($companySettings);
            if ($mbox) {
                $criteria = 'UNSEEN';
                $imapSince = function_exists('email_bridge_incremental_since') ? email_bridge_incremental_since($emailDb, 48) : null;
                if ($imapSince) {
                    $ts = strtotime($imapSince);
                    if ($ts) {
                        $criteria = 'SINCE "' . date('d-M-Y', $ts) . '"';
                    }
                }
                $msgNos = @imap_search($mbox, $criteria);
                if (!$msgNos && $criteria !== 'UNSEEN') {
                    $msgNos = @imap_search($mbox, 'UNSEEN');
                }
                if ($msgNos) {
                    rsort($msgNos);
                    $msgNos = array_slice($msgNos, 0, $quick ? 30 : 80);
                    $overviewList = @imap_fetch_overview($mbox, implode(',', $msgNos), 0);
                    if (is_array($overviewList)) {
                        $msgIds = array();
                        foreach ($overviewList as $ov) {
                            $mid = trim((string) ($ov->message_id ?? ''));
                            if ($mid !== '') {
                                $msgIds[] = $mid;
                            }
                        }
                        $existingIds = function_exists('email_existing_message_id_set')
                            ? email_existing_message_id_set($emailDb, $msgIds)
                            : array();
                        $imapUser = (string) ($companySettings['email_imap_user'] ?? '');
                        $importedThisRun = 0;
                        foreach ($overviewList as $ov) {
                            if ($importedThisRun >= ($quick ? 15 : 40)) {
                                break;
                            }
                            $msg_id = trim((string) ($ov->message_id ?? ''));
                            if ($msg_id !== '' && isset($existingIds[$msg_id])) {
                                continue;
                            }
                            $m = (int) ($ov->msgno ?? 0);
                            if ($m < 1) {
                                continue;
                            }
                            $db_date = isset($ov->udate)
                                ? gmdate('Y-m-d H:i:s', (int) $ov->udate)
                                : gmdate('Y-m-d H:i:s', strtotime((string) ($ov->date ?? 'now')));
                            $subject = trim(imap_utf8((string) ($ov->subject ?? '')));
                            $sender = trim(imap_utf8((string) ($ov->from ?? '')));
                            list(, $body) = email_build_body_from_imap_message($mbox, $m);
                            if ($body === '') {
                                $body = (string) imap_body($mbox, $m);
                            }
                            $status = email_is_sender_blocked($sender, $emailDb) ? 'spam' : 'unread';
                            $ins = $emailDb->prepare("INSERT INTO module_emails (user_id, sender_email, recipient_email, subject, body, direction, status, created_at, message_id) VALUES (0, ?, ?, ?, ?, 'inbound', ?, ?, ?)");
                            $ins->execute([$sender, $imapUser, $subject, $body, $status, $db_date, $msg_id !== '' ? $msg_id : null]);
                            $new_id = (int) $emailDb->lastInsertId();
                            if ($new_id > 0 && $status !== 'spam' && function_exists('email_imap_download_attachments')) {
                                email_imap_download_attachments($mbox, $m, $new_id, $emailDb);
                            }
                            if ($msg_id !== '') {
                                $existingIds[$msg_id] = true;
                            }
                            $importedThisRun++;
                            $count++;
                        }
                    }
                }
                @imap_close($mbox);
            }
        } catch (Throwable $e) {
            if ($imapError === null) {
                $imapError = 'Company IMAP: ' . $e->getMessage();
            }
        }
    }

    if ($count === 0 && $imapError && !$hasBridges) {
        echo json_encode(['status' => 'error', 'message' => $imapError]);
        exit;
    }

    if ($count === 0 && !empty($bridgeErrors)) {
        $preferredKey = function_exists('email_resolve_preferred_bridge') ? email_resolve_preferred_bridge() : '';
        $bridgeMsg = ($preferredKey !== '' && !empty($bridgeErrors[$preferredKey]))
            ? $bridgeErrors[$preferredKey]
            : (string) reset($bridgeErrors);
        echo json_encode([
            'status' => 'error',
            'message' => 'Could not refresh the company mailbox. ' . $bridgeMsg
                . ' On ultitech.io, Sync needs the Ultimate mail-bridge (HTTPS). If Cloudflare blocks it, whitelist the Ultitech server IP for /staff/ultimate/* or import a catch-up SQL dump.',
            'new_count' => 0,
            'bridge_errors' => $bridgeErrors,
        ]);
        exit;
    }

    $inboxTotal = 0;
    try {
        $inboxTotal = (int) $emailDb->query("SELECT COUNT(*) FROM module_emails WHERE direction = 'inbound' AND status != 'trash' AND status != 'archived'")->fetchColumn();
    } catch (Throwable $e) {
    }

    $msg = $count > 0
        ? ('Synced ' . $count . ' new message' . ($count === 1 ? '' : 's'))
        : 'Up to date';
    if (!empty($bridgeErrors)) {
        $msg .= ' (bridge warnings: ' . implode('; ', array_map(static function ($k, $v) {
            return $k . ': ' . $v;
        }, array_keys($bridgeErrors), array_values($bridgeErrors))) . ')';
    }

    echo json_encode([
        'status' => 'success',
        'message' => $msg,
        'new_count' => $count,
        'inbox_total' => $inboxTotal,
        'bridge_new' => $bridgeNew,
        'bridge_errors' => $bridgeErrors,
    ]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Could not sync mail. Please try again.']);
}
