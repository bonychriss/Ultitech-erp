<?php
/**
 * Profile avatars for approval flow (PHP 7.0+).
 * Uses account profile_photo when the file exists; otherwise colorful initials avatar.
 */
if (!function_exists('user_avatar_initials')) {
    function user_avatar_initials($displayName)
    {
        $displayName = trim((string) $displayName);
        if ($displayName === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $displayName);
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $dp) {
            if ($dp !== '') {
                $initials .= strtoupper(substr($dp, 0, 1));
            }
        }
        if ($initials === '') {
            return '?';
        }
        if (strlen($initials) === 1 && count($parts) === 1 && strlen($parts[0]) > 1) {
            $initials .= strtoupper(substr($parts[0], 1, 1));
        }
        return substr($initials, 0, 2);
    }
}

if (!function_exists('user_avatar_tone')) {
    function user_avatar_tone($displayName)
    {
        $displayName = trim((string) $displayName);
        if ($displayName === '') {
            return 0;
        }
        return (int) (abs(crc32($displayName)) % 10);
    }
}

if (!function_exists('user_avatar_path_candidates')) {
    function user_avatar_path_candidates($rawPath)
    {
        $rawPath = trim((string) $rawPath);
        if ($rawPath === '') {
            return array();
        }
        $out = array();
        $add = function ($p) use (&$out) {
            $p = trim((string) $p);
            if ($p === '') {
                return;
            }
            $p = str_replace('\\', '/', $p);
            if (!in_array($p, $out, true)) {
                $out[] = $p;
            }
        };

        $add($rawPath);
        if (function_exists('normalizeMediaPathForApp')) {
            $add(normalizeMediaPathForApp($rawPath));
        }
        $stripped = preg_replace('#^(\.\./)+#', '', str_replace('\\', '/', $rawPath));
        $stripped = ltrim((string) $stripped, '/');
        $add($stripped);
        if (stripos($stripped, 'public_html/') === 0) {
            $add(substr($stripped, strlen('public_html/')));
        }
        $base = basename($stripped);
        if ($base !== '' && $base !== '.' && $base !== '..') {
            $add('assets/uploads/profiles/' . $base);
        }
        return $out;
    }
}

if (!function_exists('user_avatar_photo_url')) {
    /**
     * Resolve a working public URL for profile_photo, or empty if file missing.
     */
    function user_avatar_photo_url($rawPath)
    {
        $rawPath = trim((string) $rawPath);
        if ($rawPath === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $rawPath)) {
            return '';
        }

        foreach (user_avatar_path_candidates($rawPath) as $candidate) {
            if (function_exists('mediaUrlFromPath')) {
                $url = mediaUrlFromPath($candidate, true);
                if ($url !== '') {
                    return $url;
                }
            }
            $fs = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $candidate), '/');
            if (is_file($fs) && function_exists('app_url')) {
                return app_url('/' . ltrim(str_replace('\\', '/', $candidate), '/'));
            }
        }
        return '';
    }
}

if (!function_exists('resolve_approver_profile_photo')) {
    function resolve_approver_profile_photo(array $stage, array $userPhotosByName, array $userPhotosById)
    {
        $aid = isset($stage['approver_id']) ? (int) $stage['approver_id'] : 0;
        if ($aid > 0 && !empty($userPhotosById[$aid])) {
            $url = user_avatar_photo_url($userPhotosById[$aid]);
            if ($url !== '') {
                return $url;
            }
        }
        $nameKey = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) (isset($stage['approver_name']) ? $stage['approver_name'] : ''))));
        if ($nameKey !== '' && !empty($userPhotosByName[$nameKey])) {
            return user_avatar_photo_url($userPhotosByName[$nameKey]);
        }
        return '';
    }
}

if (!function_exists('render_approval_flow_avatar')) {
    function render_approval_flow_avatar($displayName, $photoUrl, $size)
    {
        $displayName = trim((string) $displayName);
        $photoUrl = trim((string) $photoUrl);
        $size = (int) $size;
        if ($size < 24) {
            $size = 42;
        }
        $initials = user_avatar_initials($displayName !== '' ? $displayName : 'User');
        $tone = (int) user_avatar_tone($displayName);
        $uid = 'user-av-' . substr(md5($displayName . '|' . $size), 0, 8);

        $html = '<span class="approval-avatar user-av user-av--' . $tone . '" id="' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . '" style="width:' . $size . 'px;height:' . $size . 'px;min-width:' . $size . 'px;min-height:' . $size . 'px;">';
        $html .= '<span class="approval-avatar-initial user-av-fallback" aria-hidden="true" style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;"><i class="bi bi-person-fill" style="font-size:' . round($size * 0.6) . 'px;line-height:1;"></i></span>';
        if ($photoUrl !== '') {
            $html .= '<img src="' . htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') . '" alt="" width="' . $size . '" height="' . $size . '" class="user-av-photo" decoding="async" onerror="var p=this.parentNode;if(p){p.classList.add(\'user-av--no-photo\');}this.remove();">';
        } else {
            $html = str_replace('user-av--' . $tone, 'user-av--' . $tone . ' user-av--no-photo', $html);
        }
        $html .= '</span>';
        return $html;
    }
}
