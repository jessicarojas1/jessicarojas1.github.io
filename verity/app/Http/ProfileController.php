<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Audit;
use Verity\Support\Auth;
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
}
