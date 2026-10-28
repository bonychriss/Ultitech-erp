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
        <h2 class="section-title"><i class="fas fa-image" style="color:#7c3aed;margin-right:0.4rem;"></i>Profile Photo</h2>
        <p class="section-subtitle">Use the camera on your device or upload a new avatar. JPG, PNG, GIF or WEBP (max 2MB).</p>
    </div>
    <div class="ps-photo-actions">
        <div class="ps-photo-preview">
            <?php if ($profilePhotoUrl !== ''): ?>
                <img src="<?= $esc($profilePhotoUrl) ?>" alt="Current photo">
            <?php else: ?>
                <span><?= $esc($initial) ?></span>
            <?php endif; ?>
        </div>
        <form method="post" enctype="multipart/form-data" style="margin:0;">
            <label class="ps-upload-btn">
                <i class="fas fa-upload"></i> Upload new photo
                <input type="file" name="profile_photo" accept="image/*" hidden onchange="this.form.submit()">
            </label>
        </form>
        <?php if ($profilePhotoUrl !== ''): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('Remove your profile photo?');">
                <button type="submit" name="remove_profile_photo" value="1" class="ps-remove-btn">
                    <i class="fas fa-trash"></i> Remove
                </button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="editor-section" id="personal-details">
    <div class="section-header">
        <h2 class="section-title"><i class="fas fa-user" style="color:#7c3aed;margin-right:0.4rem;"></i>Personal Details</h2>
        <p class="section-subtitle">Update your name, contact information, and account identifiers.</p>
    </div>
    <div class="ps-details-grid">
        <div>
            <label class="form-label">Full Name</label>
            <form method="post" class="inline-field-form">
                <div class="ps-icon-field" style="flex:1;min-width:0;">
                    <i class="fas fa-user"></i>
                    <input type="text" name="new_full_name" class="form-input" value="<?= $esc($user['full_name'] ?? '') ?>" required minlength="2">
                </div>
                <button type="submit" class="btn-save btn-save-inline">Update</button>
            </form>
        </div>
        <div>
            <label class="form-label">Username</label>
            <form method="post" class="inline-field-form">
                <div class="ps-icon-field" style="flex:1;min-width:0;">
                    <i class="fas fa-at"></i>
                    <input type="text" name="new_username" class="form-input" value="<?= $esc($user['username'] ?? '') ?>" required minlength="3">
                </div>
                <button type="submit" class="btn-save btn-save-inline">Update</button>
            </form>
        </div>
        <div>
            <label class="form-label">Email</label>
            <form method="post" class="inline-field-form">
                <div class="ps-icon-field" style="flex:1;min-width:0;">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="new_email" class="form-input" value="<?= $esc($user['email'] ?? '') ?>" required autocomplete="email">
                </div>
                <button type="submit" class="btn-save btn-save-inline">Update</button>
            </form>
        </div>
    </div>
    <div class="form-row" style="margin-top:1rem;">
        <label class="form-label">WhatsApp</label>
        <div>
            <form method="post" class="inline-field-form">
                <div class="ps-icon-field" style="flex:1;min-width:0;">
                    <i class="fas fa-phone"></i>
                    <input type="text" name="whatsapp_number" class="form-input" value="<?= $esc($user['whatsapp_number'] ?? '') ?>" placeholder="+255 712 345 678">
                </div>
                <button type="submit" class="btn-save btn-save-inline">Save</button>
            </form>
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
