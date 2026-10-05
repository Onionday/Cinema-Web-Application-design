<?php
/* =====================================================================
 * includes/auth.php
 *
 * Session-based authentication. Passwords are stored as bcrypt hashes
 * (password_hash / password_verify) - never in plain text.
 * ===================================================================== */

require_once __DIR__ . '/functions.php';

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    static $cache = null;

    if (!is_logged_in()) {
        return null;
    }
    if ($cache !== null) {
        return $cache;
    }

    $cache = db_one(
        'SELECT user_id, full_name, email, phone, birth_date, role, created_at, last_login
           FROM users
          WHERE user_id = ? AND is_active = 1',
        [(int) $_SESSION['user_id']]
    );

    if (!$cache) {                 // account removed or disabled mid-session
        logout_user();
        return null;
    }

    return $cache;
}

function current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Starts a signed-in session and refreshes last_login. */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int) $user['user_id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];

    /* UPDATE - stamp the login time */
    db_execute('UPDATE users SET last_login = NOW() WHERE user_id = ?',
               [(int) $user['user_id']]);
}

/**
 * Ends the signed-in session.
 *
 * Everything in $_SESSION is dropped and a brand-new session id is
 * issued, which discards the old session server-side as well. The
 * session itself stays usable, so the "you have been signed out"
 * message survives the redirect that follows - destroying the session
 * outright here would throw that message away with it.
 */
function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/**
 * Gate for customer-only pages. Remembers where the visitor was heading
 * so they land back there after signing in.
 */
function require_login(string $why = 'Please sign in to continue.'): void
{
    if (is_logged_in()) {
        return;
    }
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
    flash('info', $why);
    redirect('account.php');
}

/** Gate for the staff page. */
function require_admin(): void
{
    require_login('Please sign in with a staff account.');
    if (!is_admin()) {
        flash('error', 'That page is restricted to CineSphere staff accounts.');
        redirect('index.php');
    }
}
