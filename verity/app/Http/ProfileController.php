<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Audit;
use Verity\Support\Auth;
use Verity\Support\Mfa;
use Verity\Support\Security;
use Verity\Support\Session;

/**
 * Self-service account page — available to every authenticated user
 * regardless of role, since it only ever acts on the caller's own account.
 * No Authorize permission check is needed here for that reason (there is no
 * "whose account" ambiguity the way there is for every other controller).
 */
final class ProfileController
{
    public static function index(string $nonce, ?string $error = null): void
    {
        Auth::requireAuth();
        $user = Auth::user();

        $mfaEnabled = Mfa::isEnabled((int) $user['id']);
        $mfaPending = $mfaEnabled ? null : Mfa::pendingEnrollment((int) $user['id'], (string) $user['email']);
        $mfaRecoveryCodeCount = $mfaEnabled ? Mfa::remainingRecoveryCodeCount((int) $user['id']) : 0;
        Session::start();
        $newRecoveryCodes = $_SESSION['mfa_new_recovery_codes'] ?? null;
        unset($_SESSION['mfa_new_recovery_codes']); // read-once

        $title = 'My Account';
        $navActive = 'profile';
        $breadcrumbs = ['My Account' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_profile.php';
    }

    public static function changePassword(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $nonce = Security::nonce();

        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::index($nonce, 'Your session expired. Please try again.');
            return;
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!Auth::verifyPassword((int) $user['id'], $current)) {
            Audit::denied('auth.password_change_failed', 'app_user#' . $user['id']);
            self::index($nonce, 'Current password is incorrect.');
            return;
        }
        if ($new !== $confirm) {
            self::index($nonce, 'New password and confirmation do not match.');
            return;
        }
        $policyError = Auth::passwordPolicyError($new);
        if ($policyError !== null) {
            self::index($nonce, $policyError);
            return;
        }
        if ($new === $current) {
            self::index($nonce, 'New password must be different from your current password.');
            return;
        }

        Auth::setPassword((int) $user['id'], $new, (int) $user['id']);
        Audit::log('auth.password_changed_self', 'app_user#' . $user['id'], null, null, null, (int) $user['id']);
        // Regenerate the session id after a credential change, matching the
        // login flow's own practice — limits the value of a stolen session
        // id from before the password changed.
        Session::regenerate();

        header('Location: /app/profile?changed=1');
    }

    /** Begins (or restarts) enrollment — generates and stores a secret, not yet active until confirmed. */
    public static function mfaEnroll(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::index(Security::nonce(), 'Your session expired. Please try again.');
            return;
        }
        Mfa::beginEnrollment((int) $user['id'], (string) $user['email']);
        header('Location: /app/profile');
    }

    /** Confirms enrollment with a real code; shows the recovery codes exactly once via a short-lived session flash. */
    public static function mfaConfirm(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $nonce = Security::nonce();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::index($nonce, 'Your session expired. Please try again.');
            return;
        }
        try {
            $codes = Mfa::confirmEnrollment((int) $user['id'], (string) ($_POST['code'] ?? ''), (int) $user['id']);
        } catch (\RuntimeException $e) {
            self::index($nonce, $e->getMessage());
            return;
        }
        Session::start();
        $_SESSION['mfa_new_recovery_codes'] = $codes; // read-once; app_profile.php clears it after displaying
        header('Location: /app/profile?mfa_enrolled=1');
    }

    public static function mfaDisable(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $nonce = Security::nonce();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::index($nonce, 'Your session expired. Please try again.');
            return;
        }
        if (!Auth::verifyPassword((int) $user['id'], (string) ($_POST['current_password'] ?? ''))) {
            Audit::denied('mfa.disable_failed', 'app_user#' . $user['id']);
            self::index($nonce, 'Current password is incorrect.');
            return;
        }
        Mfa::disable((int) $user['id'], (int) $user['id']);
        header('Location: /app/profile?mfa_disabled=1');
    }

    public static function mfaRegenerateCodes(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $nonce = Security::nonce();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::index($nonce, 'Your session expired. Please try again.');
            return;
        }
        if (!Auth::verifyPassword((int) $user['id'], (string) ($_POST['current_password'] ?? ''))) {
            Audit::denied('mfa.regenerate_codes_failed', 'app_user#' . $user['id']);
            self::index($nonce, 'Current password is incorrect.');
            return;
        }
        if (!Mfa::isEnabled((int) $user['id'])) {
            self::index($nonce, 'Two-factor authentication is not enabled.');
            return;
        }
        $codes = Mfa::regenerateRecoveryCodes((int) $user['id'], (int) $user['id']);
        Session::start();
        $_SESSION['mfa_new_recovery_codes'] = $codes;
        header('Location: /app/profile?mfa_enrolled=1');
    }
}
