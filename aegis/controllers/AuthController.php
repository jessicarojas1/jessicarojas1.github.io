<?php
class AuthController {
    public function loginForm(): void {
        if (Auth::check()) { header('Location: /'); exit; }
        $error   = $_SESSION['login_error'] ?? null;
        $success = $_SESSION['login_success'] ?? null;
        unset($_SESSION['login_error'], $_SESSION['login_success']);
        require AEGIS_ROOT . '/views/auth/login.php';
    }

    public function login(): void {
        if (Auth::check()) { header('Location: /'); exit; }

        $token = $_POST['csrf_token'] ?? '';
        if (!Security::validateCsrf($token)) {
            $_SESSION['login_error'] = 'Invalid request. Please try again.';
            header('Location: /login'); exit;
        }

        $email    = Security::sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $_SESSION['login_error'] = 'Email and password are required.';
            header('Location: /login'); exit;
        }

        if (Auth::login($email, $password)) {
            $redirect = $_SESSION['redirect_after_login'] ?? '/';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect); exit;
        }

        $_SESSION['login_error'] = 'Invalid email or password, or your account is locked.';
        header('Location: /login'); exit;
    }

    public function logout(): void {
        Auth::log('logout', null, null);
        Auth::logout();
        header('Location: /login'); exit;
    }
}
