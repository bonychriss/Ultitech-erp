<?php
/** @var array $user */
/** @var string $initial */
/** @var string $profileBackUrl */
/** @var string $profilePhotoUrl */
/** @var string $logoutUrl */
/** @var string|null $currentSig */
$esc = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$forgotPasswordUrl = function_exists('company_url') ? company_url('forgot-password.php') : '../forgot-password.php';
?>
<section class="editor-section" id="profile-photo">
    <div class="section-header">
        <h2 class="section-title">Profile Photo</h2>
        <p class="section-subtitle">Update your avatar and view your core identity details.</p>
    </div>
    <div class="form-row">
        <label class="form-label">Avatar</label>
        <div>
            <div class="profile-photo-row">
                <div class="account-avatar-wrap">
                    <div class="account-avatar">
                        <?php if ($profilePhotoUrl !== ''): ?>
                            <img src="<?= $esc($profilePhotoUrl) ?>" alt="Profile photo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <span class="account-avatar-fallback" style="display:none;"><?= $esc($initial) ?></span>
                        <?php else: ?>
                            <span class="account-avatar-fallback"><?= $esc($initial) ?></span>
                        <?php endif; ?>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="account-avatar-upload">
                        <label class="account-avatar-btn" title="Upload photo">
                            <i class="fas fa-camera"></i>
                            <input type="file" name="profile_photo" accept="image/*" hidden onchange="this.form.submit()">
                        </label>
                    </form>
                </div>
                <div class="account-profile-meta">
                    <h3 class="account-profile-name"><?= $esc($user['full_name'] ?? '') ?></h3>
                    <p class="account-profile-user">@<?= $esc($user['username'] ?? '') ?></p>
                    <p class="account-profile-email"><?= $esc($user['email'] ?? '') ?></p>
                    <div class="account-profile-badges">
                        <span class="profile-badge"><?= $esc(ucfirst((string) ($user['role'] ?? 'user'))) ?></span>
                        <?php if (!empty($user['department'])): ?>
                            <span class="profile-badge profile-badge-muted"><?= $esc($user['department']) ?></span>
                        <?php endif; ?>
                        <span class="profile-badge profile-badge-success">Active</span>
                    </div>
                    <p class="help-text">Member since <?= $esc(date('M Y', strtotime((string) ($user['created_at'] ?? 'now')))) ?></p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="editor-section" id="personal-details">
    <div class="section-header">
        <h2 class="section-title">Personal Details</h2>
        <p class="section-subtitle">Update your name, contact information, and account identifiers.</p>
    </div>
    <div class="form-row">
        <label class="form-label">Full Name</label>
        <div>
            <form method="post" class="inline-field-form">
                <input type="text" name="new_full_name" class="form-input" value="<?= $esc($user['full_name'] ?? '') ?>" required minlength="2">
                <button type="submit" class="btn-save btn-save-inline">Update</button>
            </form>
        </div>
    </div>
    <div class="form-row">
        <label class="form-label">Username</label>
        <div>
            <form method="post" class="inline-field-form">
                <input type="text" name="new_username" class="form-input" value="<?= $esc($user['username'] ?? '') ?>" required minlength="3">
                <button type="submit" class="btn-save btn-save-inline">Update</button>
            </form>
        </div>
    </div>
    <div class="form-row">
        <label class="form-label">Email</label>
        <div>
            <form method="post" class="inline-field-form">
                <input type="email" name="new_email" class="form-input" value="<?= $esc($user['email'] ?? '') ?>" required autocomplete="email">
                <button type="submit" class="btn-save btn-save-inline">Update</button>
            </form>
            <p class="help-text">Used for login and account recovery.</p>
        </div>
    </div>
    <div class="form-row">
        <label class="form-label">WhatsApp</label>
        <div>
            <form method="post" class="inline-field-form">
                <input type="text" name="whatsapp_number" class="form-input" value="<?= $esc($user['whatsapp_number'] ?? '') ?>" placeholder="+255 712 345 678">
                <button type="submit" class="btn-save btn-save-inline">Save</button>
            </form>
            <p class="help-text">Include country code for voucher and system notifications.</p>
        </div>
    </div>
</section>

<section class="editor-section" id="change-password">
    <div class="section-header">
        <h2 class="section-title">Security &amp; Password</h2>
        <p class="section-subtitle">Manage your password and authentication settings.</p>
    </div>
    <form method="post" class="account-password-form">
        <input type="hidden" name="action" value="change_password">
        <div class="form-row">
            <label class="form-label" for="accountNewPassword">New Password</label>
            <div>
                <div class="account-pw-field">
                    <input type="password" name="new_password" id="accountNewPassword" class="form-input account-pw-input" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
                    <button type="button" class="account-pw-toggle" data-target="accountNewPassword" aria-label="Show password" title="Show password">
                        <svg class="account-pw-toggle-svg account-pw-toggle-svg--show" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="account-pw-toggle-svg account-pw-toggle-svg--hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.8 21.8 0 0 1 5.06-6.94M9.9 4.24A10.94 10.94 0 0 1 12 5c7 0 11 7 11 7a21.8 21.8 0 0 1-3.87 5.16"/><path d="M1 1l22 22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                    </button>
                </div>
                <div class="account-pw-strength" id="accountPwStrength" aria-live="polite">
                    <div class="account-pw-strength-top">
                        <span class="account-pw-strength-label" id="accountPwStrengthLabel">Enter a password</span>
                        <span class="account-pw-strength-score" id="accountPwStrengthScore"></span>
                    </div>
                    <div class="account-pw-strength-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="accountPwStrengthBar">
                        <span class="account-pw-strength-fill" id="accountPwStrengthFill"></span>
                    </div>
                    <ul class="account-pw-rules" id="accountPwRules">
                        <li data-rule="length"><i class="fas fa-circle" aria-hidden="true"></i> At least 8 characters</li>
                        <li data-rule="mixed_case"><i class="fas fa-circle" aria-hidden="true"></i> Uppercase and lowercase letters</li>
                        <li data-rule="digit"><i class="fas fa-circle" aria-hidden="true"></i> At least one number</li>
                        <li data-rule="special"><i class="fas fa-circle" aria-hidden="true"></i> Special character (recommended)</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="form-row">
            <label class="form-label" for="accountConfirmPassword">Confirm Password</label>
            <div>
                <div class="account-pw-field">
                    <input type="password" name="confirm_password" id="accountConfirmPassword" class="form-input account-pw-input" required minlength="8" autocomplete="new-password" placeholder="Re-enter new password">
                    <button type="button" class="account-pw-toggle" data-target="accountConfirmPassword" aria-label="Show password" title="Show password">
                        <svg class="account-pw-toggle-svg account-pw-toggle-svg--show" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="account-pw-toggle-svg account-pw-toggle-svg--hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.8 21.8 0 0 1 5.06-6.94M9.9 4.24A10.94 10.94 0 0 1 12 5c7 0 11 7 11 7a21.8 21.8 0 0 1-3.87 5.16"/><path d="M1 1l22 22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                    </button>
                </div>
                <p class="account-pw-match help-text" id="accountPwMatch" aria-live="polite"></p>
                <p class="help-text">You are already signed in. Current password is not required.</p>
            </div>
        </div>
        <div class="form-row">
            <label class="form-label">Recovery</label>
            <div class="password-actions-row">
                <a href="<?= $esc($forgotPasswordUrl) ?>" class="account-forgot-link">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    Forgot password? Reset by email
                </a>
                <button type="submit" class="btn-save btn-save-inline" id="accountPwSubmitBtn">Update Password</button>
            </div>
        </div>
    </form>
</section>
