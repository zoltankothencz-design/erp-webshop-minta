<?php
declare(strict_types=1);

namespace App;

/** Admin belépés: session + jelszó-hash (password_verify) + CSRF-védelem + egyszerű próbálkozás-korlát. */
final class Auth
{
    private const MAX_FAILS = 5;
    private const LOCK_SECONDS = 60;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('wsm_sess');
            session_set_cookie_params([
                'httponly' => true,       // a JS nem olvashatja a sütit
                'samesite' => 'Lax',      // idegen oldalról érkező POST-ra nem küldi
                'secure' => str_starts_with((string) Config::get('APP_URL'), 'https://'),
            ]);
            session_start();
        }
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['uid']);
    }

    public static function attempt(string $username, string $password): bool
    {
        self::start();
        if (($_SESSION['lock_until'] ?? 0) > time()) {
            return false;
        }
        $row = Db::one('SELECT id, password_hash FROM admin_users WHERE username = ?', [$username]);
        if ($row !== null) {
            $ok = password_verify($password, $row['password_hash']);
        } else {
            password_hash($password, PASSWORD_DEFAULT);   // hasonló időigény, hogy a válaszidő ne áruljon el felhasználónevet
            $ok = false;
        }
        if ($ok) {
            session_regenerate_id(true);   // session fixation elleni védelem
            $_SESSION['uid'] = (int) $row['id'];
            $_SESSION['fails'] = 0;
            return true;
        }
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= self::MAX_FAILS) {
            $_SESSION['lock_until'] = time() + self::LOCK_SECONDS;
            $_SESSION['fails'] = 0;
        }
        return false;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** POST kéréseknél kötelező: a token vagy az X-CSRF-Token fejlécben, vagy a _csrf mezőben jön. */
    public static function verifyCsrf(Request $request): bool
    {
        $sent = $request->header('X-CSRF-Token') ?? ($request->post['_csrf'] ?? '');
        return is_string($sent) && $sent !== '' && hash_equals(self::csrfToken(), $sent);
    }
}
